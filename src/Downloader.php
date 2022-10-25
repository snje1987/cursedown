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

use Closure;
use Minifw\Common\Exception;

class Downloader
{
    public function download(string $url, string $savePath, bool $flush = false) : void
    {
        if (empty($url)) {
            return;
        }

        if ($this->multiHandler === null) {
            $this->multiHandler = curl_multi_init();
        }

        foreach ($this->taskList as $task) {
            if ($task['url'] === $url) {
                return;
            }
        }

        $dir = dirname($savePath);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        $task = [
            'url' => $url,
            'save_path' => $savePath,
            'retry' => 0,
            'redirect' => 0
        ];

        $this->addTask($task);

        while (true) {
            if (!$flush && count($this->taskList) < $this->maxTask) {
                break;
            }
            if (count($this->taskList) == 0) {
                break;
            }
            $this->doDownload($flush);
        }
    }

    public function flush() : void
    {
        if ($this->multiHandler === null) {
            return;
        }
        $this->doDownload(true);
    }

    protected function addTask(array $task) : void
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

    protected function rmTask(string $url, $ch) : void
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

    protected function getTask(string $url) : ?array
    {
        foreach ($this->taskList as $task) {
            if ($task['url'] === $url) {
                return $task;
            }
        }

        return null;
    }

    protected function getTaskKey(string $url) : int
    {
        foreach ($this->taskList as $k => $task) {
            if ($task['url'] === $url) {
                return $k;
            }
        }

        return -1;
    }

    protected function downloadRetry($ch, array $info) : void
    {
        $msg = curl_error($ch);
        $task = $this->getTask($info['url']);
        $this->rmTask($info['url'], $ch);

        if ($task['retry'] >= self::MAX_RETRY) {
            if (is_callable($this->onFinished)) {
                call_user_func($this->onFinished, $info['url'], self::TASK_RESULT_ERROR, $msg);
            } else {
                throw new Exception('下载出错: ' . $info['url'] . ' ' . $msg);
            }
        }

        $task['retry']++;
        $task['redirect'] = 0;
        $this->addTask($task);
    }

    protected function downloadFinshed($ch, array $info) : void
    {
        $task = $this->getTask($info['url']);
        $key = $this->getTaskKey($info['url']);
        $this->updateSpeed($key, $task, (int) $info['download_content_length'], (int) $info['download_content_length']);

        $this->rmTask($info['url'], $ch);

        if (is_callable($this->onFinished)) {
            call_user_func($this->onFinished, $info['url'], self::TASK_RESULT_OK);
        }
    }

    protected function downloadRedirect($ch, array $info) : void
    {
        $task = $this->getTask($info['url']);
        $this->rmTask($info['url'], $ch);

        if ($task['redirect'] >= self::MAX_REDIRECT) {
            if (is_callable($this->onFinished)) {
                call_user_func($this->onFinished, $info['url'], self::TASK_RESULT_ERROR, '重定向次数过多');
            } else {
                throw new Exception('重定向过多: ' . $info['url']);
            }
        } else {
            $new_url = $info['redirect_url'];
            $task['url'] = $new_url;
            $task['redirect']++;

            $this->addTask($task);
        }
    }

    protected function doDownload(bool $flush) : void
    {
        if ($this->isRunning || $this->multiHandler === null) {
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
        } while ($status == CURLM_OK);

        if (count($this->taskList) <= 0) {
            curl_multi_close($this->multiHandler);
            $this->multiHandler = null;
        }

        $this->isRunning = false;
    }

    public function onProgress($ch, int $totalDown, int $down, int $totalUp, int $up) : void
    {
        if ($down <= 0) {
            return;
        }

        $info = curl_getinfo($ch);
        $key = $this->getTaskKey($info['url']);
        $task = $this->taskList[$key];

        $this->updateSpeed($key, $task, $totalDown, $down);
    }

    protected function updateSpeed(int $key, array $task, int $totalDown, int $down) : void
    {
        $now = time();

        if ($task['lastTime'] == 0) {
            $this->totalSize += $totalDown;
        }

        if ($task['lastDown'] != $down) {
            $this->totalDownload += $down - $task['lastDown'];
            $task['lastDown'] = $down;
            $task['lastTime'] = $now;
            $this->taskList[$key] = $task;
        }

        if (!is_callable($this->showProgress)) {
            return;
        }

        if ($this->lastTime < $now) {
            $newDownload = $this->totalDownload - $this->lastDownload;
            $this->lastDownload = $this->totalDownload;
            $this->speed = bcdiv($newDownload, $now - $this->lastTime, 2);
            $this->lastTime = $now;
        }

        $count = count($this->taskList);

        if ($this->totalSize > 0) {
            $msg = '[' . $count . '/' . $this->maxTask . '] [' . self::showSize($this->totalDownload) . '/' . self::showSize($this->totalSize) . ' ' . bcdiv($this->totalDownload * 100, $this->totalSize, 2) . '% ' . self::showSize($this->speed) . '/s]';
            call_user_func($this->showProgress, $msg);
        } elseif ($this->totalDownload > 0) {
            $msg = '[' . $count . '/' . $this->maxTask . '] [' . self::showSize($this->totalDownload) . ' ' . self::showSize($this->speed) . '/s]';
            call_user_func($this->showProgress, $msg);
        } else {
            $msg = '[' . $count . '/' . $this->maxTask . ']';
            call_user_func($this->showProgress, $msg);
        }
    }

    public static function showSize(int $size) : string
    {
        return \Minifw\Common\Utils::showSize($size);
    }

    /////////////////////////////////////////////////////

    public function __construct(int $maxTask = 5)
    {
        HttpClient::init();
        $this->maxTask = $maxTask;
    }
    const CAROOT = DATA_DIR . '/caroot.pem';
    const MAX_RETRY = 3;
    const MAX_REDIRECT = 3;
    protected int $maxTask = 5;
    protected array $taskList = [];
    protected $multiHandler = null;
    protected int $totalSize = 0;
    protected int $totalDownload = 0;
    protected int $lastDownload = 0;
    protected int $lastTime = 0;
    protected int $speed = 0;
    protected bool $isRunning = false;
    public ?Closure $showProgress = null;
    public ?Closure $onFinished = null;
    const TASK_RESULT_OK = 0;
    const TASK_RESULT_ERROR = 1;
}
