<?php

/*
 * Copyright (C) 2022 Yang Ming <yangming0116@163.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Org\Snje\Cursedown;

use Minifw\Common\Exception;
use Org\Snje\Cursedown;

class Downloader
{
    /**
     * @param $url
     * @param $flush
     */
    public function download($url, $save_path, $flush = false)
    {
        if ($this->multiHandler === null) {
            $this->multiHandler = curl_multi_init();
        }

        foreach ($this->taskList as $task) {
            if ($task['url'] === $url) {
                return;
            }
        }

        $dir = dirname($save_path);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        $task = [
            'url' => $url,
            'save_path' => $save_path,
            'retry' => 0
        ];

        $this->addTask($task);

        while (true) {
            if (!$flush && count($this->taskList) < $this->maxTask) {
                break;
            }
            $this->doDownload($flush);
        }
    }

    public function flush()
    {
        $this->doDownload(true);
    }

    /**
     * @param $task
     */
    protected function addTask($task)
    {
        $task['lastDown'] = 0;
        $task['lastTime'] = 0;
        $task['speed'] = 0;
        $task['size'] = 0;
        $task['fh'] = fopen($task['save_path'], 'w');

        $ch = HttpClient::prepareCurl('GET', $task['url'], null);
        curl_setopt($ch, CURLOPT_FILE, $task['fh']);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, [$this, 'onProgress']);

        $this->taskList[] = $task;
        curl_multi_add_handle($this->multiHandler, $ch);
    }

    /**
     * @param $url
     */
    protected function rmTask($url, $ch)
    {
        foreach ($this->taskList as $k => $task) {
            if ($task['url'] === $url) {
                $task = $this->taskList[$k];
                unset($this->taskList[$k]);
                curl_multi_remove_handle($this->multiHandler, $ch);
                curl_close($ch);
                fclose($task['fh']);
                unset($task['fh']);
            }
        }
    }

    /**
     * @param $url
     * @return mixed
     */
    protected function getTask($url)
    {
        foreach ($this->taskList as $task) {
            if ($task['url'] === $url) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @param $url
     * @return mixed
     */
    protected function getTaskKey($url)
    {
        foreach ($this->taskList as $k => $task) {
            if ($task['url'] === $url) {
                return $k;
            }
        }

        return -1;
    }

    /**
     * @param $flush
     */
    protected function doDownload($flush)
    {
        do {
            $status = curl_multi_exec($this->multiHandler, $active);
            if ($active) {
                curl_multi_select($this->multiHandler, 1);
            }

            while (false !== ($read = curl_multi_info_read($this->multiHandler))) {
                $ch = $read['handle'];
                $info = curl_getinfo($ch);

                $error = curl_errno($ch);
                if ($error !== 0) {
                    $msg = curl_error($ch);
                    $task = $this->getTask($info['url']);
                    $this->rmTask($info['url'], $ch);
                    if ($task['retry'] >= self::MAX_RETRY) {
                        throw new Exception("下载出错: " . $info['url'] . ' ' . $msg);
                    }
                    $task['retry']++;
                    $this->console->print('重试 ' . $task['retry'] . '/' . self::MAX_RETRY . ': ' . $task['url']);
                    $this->addTask($task);

                    continue;
                }

                if ($read['result'] !== CURLE_OK) {
                    $task = $this->getTask($info['url']);
                    $this->rmTask($info['url'], $ch);
                    if ($task['retry'] >= self::MAX_RETRY) {
                        throw new Exception("下载出错: " . $info['url']);
                    }
                    $task['retry']++;
                    $this->console->print('重试 ' . $task['retry'] . '/' . self::MAX_RETRY . ': ' . $task['url']);
                    $this->addTask($task);

                    continue;
                }

                if (!empty($info['redirect_url'])) {
                    $task = $this->getTask($info['url']);
                    if ($task['retry'] >= self::MAX_REDIRECT) {
                        throw new Exception("重定向过多: " . $info['url']);
                        $this->rmTask($info['url'], $ch);
                    } else {
                        $new_url = $info['redirect_url'];

                        $this->rmTask($info['url'], $ch);
                        $task['url'] = $new_url;
                        $task['retry']++;
                        $this->addTask($task);
                    }
                } else {
                    $this->rmTask($info['url'], $ch);
                }
            }

            if (count($this->taskList) < $this->maxTask && !$flush) {
                break;
            }

            if (count($this->taskList) <= 0) {
                break;
            }
        }
        while ($status == CURLM_OK);

        if (count($this->taskList) <= 0) {
            curl_multi_close($this->multiHandler);
            $this->console->reset();
        }
    }

    /**
     * @param $ch
     * @param $total_down
     * @param $down
     * @param $total_up
     * @param $up
     */
    public function onProgress($ch, $total_down, $down, $total_up, $up)
    {
        if ($down <= 0) {
            return;
        }

        $info = curl_getinfo($ch);
        $key = $this->getTaskKey($info['url']);
        $task = $this->taskList[$key];

        if ($task['lastTime'] == 0) {
            $this->totalSize += $total_down;
        }

        $now = time();

        if ($task['lastDown'] != $down) {
            $this->totalDownload += $down - $task['lastDown'];
            $task['lastDown'] = $down;
            $task['lastTime'] = $now;
            $this->taskList[$key] = $task;
        }

        if ($this->lastTime < $now) {
            $newDownload = $this->totalDownload - $this->lastDownload;
            $this->lastDownload = $this->totalDownload;
            $this->speed = bcdiv($newDownload, $now - $this->lastTime, 2);
            $this->lastTime = $now;

            $this->showSpeed();
        }
    }

    /**
     * @return null
     */
    protected function showSpeed()
    {
        $now = time();
        if ($this->lastShow >= $now) {
            return;
        }
        $this->lastShow = $now;
        $count = count($this->taskList);

        if ($this->totalSize > 0) {
            $msg = '[' . $count . '/' . $this->maxTask . '] [' . self::showSize($this->totalDownload) . '/' . self::showSize($this->totalSize) . ' ' . bcdiv($this->totalDownload * 100, $this->totalSize, 2) . '% ' . self::showSize($this->speed) . '/s]';
            $this->console->setStatus($msg);
        } elseif ($this->totalDownload > 0) {
            $msg = '[' . $count . '/' . $this->maxTask . '] [' . self::showSize($this->totalDownload) . ' ' . self::showSize($this->speed) . '/s]';
            $this->console->setStatus($msg);
        }
    }

    /**
     * @param $size
     */
    public static function showSize($size)
    {
        return \Minifw\Common\Utils::showSize($size);
    }

    /////////////////////////////////////////////////////

    /**
     * @param $console
     */
    public function __construct($console, $maxTask = 5)
    {
        HttpClient::init();
        $this->console = $console;
        $this->maxTask = $maxTask;
    }

    const CAROOT = DATA_DIR . '/caroot.pem';
    const MAX_RETRY = 3;
    const MAX_REDIRECT = 3;

    /**
     * @var int
     */
    protected $maxTask = 5;

    /**
     *
     * @var Console
     */
    protected $console;
    /**
     * @var array
     */
    protected $taskList = [];
    /**
     * @var \CurlMultiHandle
     */
    protected $multiHandler = null;
    /**
     * @var int
     */
    protected $totalSize = 0;
    /**
     * @var int
     */
    protected $totalDownload = 0;
    /**
     * @var int
     */
    protected $lastDownload = 0;
    /**
     * @var int
     */
    protected $lastTime = 0;
    /**
     * @var int
     */
    protected $speed = 0;
    /**
     * @var int
     */
    protected $lastShow = 0;
}
