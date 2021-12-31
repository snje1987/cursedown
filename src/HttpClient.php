<?php

/*
 * Copyright (C) 2021 Yang Ming <yangming0116@163.com>
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

use Org\Snje\Cursedown;
use Minifw\Common\Exception;

class HttpClient {

    public function download($url, $save_path) {
        for ($i = 1; $i <= self::MAX_RETRY; $i++) {
            try {
                return $this->do_download($url, $save_path);
            }
            catch (Exception $ex) {
                if ($i >= self::MAX_RETRY) {
                    throw $ex;
                }

                $this->console->reset()->print('下载失败，正在重试' . ($i + 1) . '/' . self::MAX_RETRY);
            }
        }
    }

    public function do_download($url, $save_path) {
        $max_redirect = 5;

        while ($max_redirect > 0) {
            $ch = $this->prepare_curl('GET', $url, null);

            $fh = fopen($save_path, 'w');

            curl_setopt($ch, CURLOPT_FILE, $fh);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, [$this, 'on_progress']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 0);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);

            $result = curl_exec($ch);
            fclose($fh);

            $error = curl_errno($ch);

            if ($error !== 0) {
                $msg = curl_error($ch);
                curl_close($ch);
                throw new Exception($msg, $error);
            }

            $result = curl_getinfo($ch);
            if (!empty($result['redirect_url'])) {
                $url = $result['redirect_url'];
            }
            else {
                return;
            }
        }
        throw new Exception('too many redirect');
    }

    public function on_progress($ch, $total_down, $down, $total_up, $up) {
        $now = time();

        if ($this->progress_timer != $now) {
            $this->speed = $down - $this->last_down;
            $this->progress_timer = $now;
            $this->last_down = $down;
        }

        if ($total_down > 0) {
            $msg = ' [' . self::show_size($down) . '/' . self::show_size($total_down) . ' ' . bcdiv($down * 100, $total_down, 2) . '% ' . self::show_size($this->speed) . '/s]';
            $this->console->set_status($msg);
        }
        elseif ($down > 0) {
            $msg = ' [' . self::show_size($down) . ' ' . self::show_size($this->speed) . '/s]';
            $this->console->set_status($msg);
        }
    }

    public function get($url, $param = [], $return_type = 'raw') {
        if (!empty($param)) {
            $param = http_build_query($param);
            $url .= '?' . $param;
        }

        for ($i = 1; $i <= self::MAX_RETRY; $i++) {
            try {
                return $this->do_request('GET', $url, [], $return_type);
            }
            catch (Exception $ex) {
                if ($i >= self::MAX_RETRY) {
                    throw $ex;
                }

                $this->console->reset()->print('下载失败，正在重试' . ($i + 1) . '/' . self::MAX_RETRY);
            }
        }
    }

    protected function do_request($method, $url, $body, $return_type) {
        $ch = $this->prepare_curl($method, $url, $body);

        $content = curl_exec($ch);
        $error = curl_errno($ch);

        if ($error !== 0) {
            $msg = curl_error($ch);
            curl_close($ch);
            throw new Exception($msg, $error);
        }
        $result = curl_getinfo($ch);

        return $this->parse_result($result, $content, $return_type);
    }

    protected function prepare_curl($method, $url, $body) {
        $ch = curl_init();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER => true,
        ];

        if (file_exists(self::CAROOT) && filesize(self::CAROOT) > 0) {
            $options[CURLOPT_CAINFO] = self::CAROOT;
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
        }

        $proxy = getenv('http_proxy');
        if (!empty($proxy)) {
            $options[CURLOPT_PROXY] = $proxy;
        }

        $header = [];

        if (!empty($body)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $options[CURLOPT_HTTPHEADER] = $header;

        curl_setopt_array($ch, $options);
        return $ch;
    }

    protected function parse_result($result, $content, $return_type) {
        if (preg_match_all('/Set-Cookie:(.*);/iU', $content, $matches)) {
            $result['cookie'] = substr(implode(';', $matches[1]), 1);
        }
        else {
            $result['cookie'] = '';
        }

        $header_size = isset($result['header_size']) ? intval($result['header_size']) : 0;
        if ($header_size > 0) {
            $result['header'] = substr($content, 0, $header_size - 4);
            $result['body'] = substr($content, $header_size);
        }
        else {
            $result['header'] = '';
            $result['body'] = $content;
        }

        $code = isset($result['http_code']) ? intval($result['http_code']) : 0;
        if ($code < 200 || $code >= 300) {
            if ($code != 301 && $code != 302) {
                throw new Exception($result['header'], $code);
            }
        }
        else {
            if ($return_type == 'json') {
                $json = json_decode($result['body'], true);
                if ($json === null) {
                    throw new Exception('返回信息不合法:' . $result['body'], -1);
                }
                $result['body'] = $json;
            }
        }

        return $result;
    }

    ////////////////////////////////////////////////

    public static function show_size($size) {
        return \Minifw\Common\Utils::show_size($size);
    }

    /////////////////////////////////////////////////////

    protected function init() {
        if (!file_exists(self::CAROOT) || time() - filemtime(self::CAROOT) > self::UPDATE_OFFSET) {
            $result = $this->get(self::CAROOT_URL);
            if ($result['http_code'] == 200) {
                file_put_contents(self::CAROOT, $result['body']);
            }
        }
    }

    public function __construct($console) {
        $this->console = $console;
        $this->init();
    }

    const CAROOT = DATA_DIR . '/caroot.pem';
    const CAROOT_URL = 'https://curl.se/ca/cacert.pem';
    const UPDATE_OFFSET = 2592000; //7天
    const MAX_RETRY = 3;

    protected $progress_timer;
    protected $speed = 0;
    protected $last_down = 0;

    /**
     *
     * @var Console
     */
    protected $console;

}
