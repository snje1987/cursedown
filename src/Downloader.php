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
     * @param $save_path
     * @param $onFinished
     * @param $flush
     * @return null
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
            'retry' => 0,
            'redirect' => 0
        ];

        $this->addTask($task);

        while (true) {
            if (!$flush && count($this->taskList) < $this->maxTask) {
                break;
            }
            $this->doDownload($flush);
        }
    }

    /**
     * @return null
     */
    public function flush()
    {
        if ($this->multiHandler === null) {
            return;
        }
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
     * @param $ch
     * @param $info
     */
    protected function downloadRetry($ch, $info)
    {
        $msg = curl_error($ch);
        $task = $this->getTask($info['url']);
        $this->rmTask($info['url'], $ch);

        if ($task['retry'] >= self::MAX_RETRY) {
            if (is_callable($this->onFinished)) {
                call_user_func($this->onFinished, $info['url'], self::TASK_RESULT_ERROR, $msg);
            } else {
                throw new Exception("下载出错: " . $info['url'] . ' ' . $msg);
            }
        }

        $task['retry']++;
        $task['redirect'] = 0;
        $this->addTask($task);
    }

    /**
     * @param $ch
     * @param $info
     */
    protected function downloadFinshed($ch, $info)
    {
        $task = $this->getTask($info['url']);
        $this->updateSpeed($info['url'], $task, $info['download_content_length'], $info['download_content_length']);

        $this->rmTask($info['url'], $ch);

        if (is_callable($this->onFinished)) {
            call_user_func($this->onFinished, $info['url'], self::TASK_RESULT_OK);
        }
    }

    /**
     * @param $ch
     * @param $info
     */
    protected function downloadRedirect($ch, $info)
    {
        $task = $this->getTask($info['url']);
        $this->rmTask($info['url'], $ch);

        if ($task['redirect'] >= self::MAX_REDIRECT) {
            if (is_callable($this->onFinished)) {
                call_user_func($this->onFinished, $info['url'], self::TASK_RESULT_ERROR, '重定向次数过多');
            } else {
                throw new Exception("重定向过多: " . $info['url']);
            }
        } else {
            $new_url = $info['redirect_url'];
            $task['url'] = $new_url;
            $task['redirect']++;

            $this->addTask($task);
        }
    }

    /**
     * @param $flush
     */
    protected function doDownload($flush)
    {
        if ($this->isRunning) {
            return;
        }
        $this->isRunning = true;
        do {
            $status = curl_multi_exec($this->multiHandler, $active);
            if ($active) {
                curl_multi_select($this->multiHandler, 1);
            }

            while (false !== ($read = curl_multi_info_read($this->multiHandler))) {
                $ch = $read['handle'];
                $info = curl_getinfo($ch);

                $error = curl_errno($ch);
                if ($error !== 0 || $read['result'] !== CURLE_OK) {
                    $this->downloadRetry($ch, $info);
                } elseif (!empty($info['redirect_url'])) {
                    $this->downloadRedirect($ch, $info);
                } else {
                    $this->downloadFinshed($ch, $info);
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
            $this->multiHandler = null;
        }

        $this->isRunning = false;
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

        $this->updateSpeed($key, $task, $total_down, $down);
    }

    /**
     * @param $now
     * @return null
     */
    protected function updateSpeed($key, $task, $total_down, $down)
    {
        $now = time();

        if ($task['lastTime'] == 0) {
            $this->totalSize += $total_down;
        }

        if ($task['lastDown'] != $down) {
            $this->totalDownload += $down - $task['lastDown'];
            $task['lastDown'] = $down;
            $task['lastTime'] = $now;
            $this->taskList[$key] = $task;
        }

        if ($this->lastTime >= $now || !is_callable($this->showProgress)) {
            return;
        }

        $newDownload = $this->totalDownload - $this->lastDownload;
        $this->lastDownload = $this->totalDownload;
        $this->speed = bcdiv($newDownload, $now - $this->lastTime, 2);
        $this->lastTime = $now;

        $count = count($this->taskList);

        if ($this->totalSize > 0) {
            $msg = '[' . $count . '/' . $this->maxTask . '] [' . self::showSize($this->totalDownload) . '/' . self::showSize($this->totalSize) . ' ' . bcdiv($this->totalDownload * 100, $this->totalSize, 2) . '% ' . self::showSize($this->speed) . '/s]';
            call_user_func($this->showProgress, $msg);
        } elseif ($this->totalDownload > 0) {
            $msg = '[' . $count . '/' . $this->maxTask . '] [' . self::showSize($this->totalDownload) . ' ' . self::showSize($this->speed) . '/s]';
            call_user_func($this->showProgress, $msg);
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
     * @param $maxTask
     */
    public function __construct($maxTask = 5)
    {
        HttpClient::init();
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
     * @var mixed
     */
    protected $isRunning = false;

    /**
     * @var mixed
     */
    public $showProgress = null;
    /**
     * @var mixed
     */
    public $onFinished = null;

    const TASK_RESULT_OK = 0;
    const TASK_RESULT_ERROR = 1;
}
