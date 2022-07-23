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

use Minifw\Common\Exception;
use Minifw\Console\Console;

class HttpClient
{
    public function get(string $url, array $param = [], string $return_type = 'raw') : array
    {
        if (!empty($param)) {
            $param = http_build_query($param);
            $url .= '?' . $param;
        }

        for ($i = 1; $i <= self::MAX_RETRY; $i++) {
            try {
                return self::doRequest('GET', $url, [], $return_type);
            } catch (Exception $ex) {
                if ($i >= self::MAX_RETRY) {
                    throw $ex;
                }

                $this->console->reset()->print('下载失败，正在重试' . ($i + 1) . '/' . self::MAX_RETRY);
            }
        }

        throw new Exception('文件下载失败');
    }

    public static function doRequest(string $method, string $url, ?array $body, string $return_type) : array
    {
        $ch = self::prepareCurl($method, $url, $body);

        $content = curl_exec($ch);
        $error = curl_errno($ch);

        if ($error !== 0) {
            $msg = curl_error($ch);
            curl_close($ch);
            throw new Exception($msg, $error);
        }
        $result = curl_getinfo($ch);

        return self::parseResult($result, $content, $return_type);
    }

    public static function prepareCurl(string $method, string $url, ?array $body)
    {
        $ch = curl_init();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER => true,
            CURLOPT_LOW_SPEED_LIMIT => 100,
            CURLOPT_LOW_SPEED_TIME => 10
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

    public static function parseResult(array $result, string $content, string $return_type) : array
    {
        if (preg_match_all('/Set-Cookie:(.*);/iU', $content, $matches)) {
            $result['cookie'] = substr(implode(';', $matches[1]), 1);
        } else {
            $result['cookie'] = '';
        }

        $header_size = isset($result['header_size']) ? intval($result['header_size']) : 0;
        if ($header_size > 0) {
            $result['header'] = substr($content, 0, $header_size - 4);
            $result['body'] = substr($content, $header_size);
        } else {
            $result['header'] = '';
            $result['body'] = $content;
        }

        $code = isset($result['http_code']) ? intval($result['http_code']) : 0;
        if ($code < 200 || $code >= 300) {
            if ($code != 301 && $code != 302) {
                throw new Exception($result['header'], $code);
            }
        } else {
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

    public static function showSize(int $size) : string
    {
        return \Minifw\Common\Utils::showSize($size);
    }

    /////////////////////////////////////////////////////

    public static function init() : void
    {
        if (!file_exists(self::CAROOT) || time() - filemtime(self::CAROOT) > self::UPDATE_OFFSET) {
            try {
                $result = self::doRequest('GET', self::CAROOT_URL, [], 'raw');
                if ($result['http_code'] == 200) {
                    file_put_contents(self::CAROOT, $result['body']);
                }
            } catch (Exception $ex) {
            }
        }
    }

    public function __construct(Console $console)
    {
        self::init();
        $this->console = $console;
    }
    const CAROOT = DATA_DIR . '/caroot.pem';
    const CAROOT_URL = 'https://curl.se/ca/cacert.pem';
    const UPDATE_OFFSET = 2592000; //7天
    const MAX_RETRY = 3;
    protected Console $console;
}
