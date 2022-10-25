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

namespace Org\Snje\Cursedown\Api;

use Minifw\Common\Exception;
use Minifw\Console\Utils;
use Org\Snje\Cursedown\App;

class Curseforge implements Api
{
    //document_url https://docs.curseforge.com/?
    const API_URL = 'https://api.curseforge.com';
    protected App $app;
    protected string $api_key;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->api_key = $app->getConfig('curseforge_api_key');
        if (empty($this->api_key)) {
            throw new Exception('API KEY未设置');
        }
    }

    protected function get(string $url, array $param) : array
    {
        $client = $this->app->getClient();
        $option = [
            'header' => [
                'x-api-key: ' . $this->api_key,
                'Accept: application/json',
            ]
        ];

        return $client->get($url, $param, 'json', $option);
    }

    protected function post(string $url, array $param) : array
    {
        $client = $this->app->getClient();
        $option = [
            'header' => [
                'x-api-key: ' . $this->api_key,
                'Accept: application/json',
                'Content-Type: application/json',
            ]
        ];

        $body = json_encode($param);

        return $client->post($url, $body, 'json', $option);
    }

    public function search(string $name) : array
    {
        $url = self::API_URL . '/v1/mods/search';

        $param = [];
        $param['gameId'] = 432;
        $param['searchFilter'] = $name; //Enigmatica

        $data = $this->get($url, $param);

        $body = $data['body'];
        if (empty($body['data'])) {
            return [];
        }
        $data = $body['data'];
        $result = [];

        foreach ($data as $packinfo) {
            $pack = [
                'id' => $packinfo['id'],
                'name' => $packinfo['name'],
            ];

            $max_id = 0;
            if (!empty($packinfo['latestFilesIndexes'])) {
                foreach ($packinfo['latestFilesIndexes'] as $fileInfo) {
                    if ($fileInfo['fileId'] > $max_id) {
                        $max_id = $fileInfo['fileId'];
                        $pack['game_version'] = $fileInfo['gameVersion'];
                    }
                }
            } else {
                $pack['game_version'] = '';
            }

            $result[] = $pack;
        }

        return $result;
    }

    public function info(int $id) : array
    {
        $url = self::API_URL . '/v1/mods/' . $id;
        $param = [];
        $data = $this->get($url, $param);

        $body = $data['body'];
        if (empty($body['data'])) {
            return [];
        }
        $body = $body['data'];

        $result = [];
        $result['name'] = $body['name'];
        $result['summary'] = $body['summary'];
        $result['versions'] = '';

        $versions = [];
        if (!empty($body['latestFiles'])) {
            foreach ($body['latestFiles'] as $fileInfo) {
                $date = strtotime($fileInfo['fileDate']);
                $versions[] = date('Y-m-d H:i:s', $date) . ' ' . $fileInfo['displayName'];
            }

            $versions = array_reverse($versions);

            $result['versions'] = implode("\n", $versions);
        }

        return $result;
    }

    public function updatePackInfo(int $id, string $packPath, array $packInfo) : array
    {
        $url = self::API_URL . '/v1/mods/' . $id;
        $param = [];
        $data = $this->get($url, $param);

        $body = $data['body'];
        if (empty($body['data'])) {
            return [];
        }

        $body = $body['data'];
        if (empty($body['latestFiles'])) {
            throw new Exception('未找到可下载的文件');
        }

        $lastFile = ['id' => 0];
        foreach ($body['latestFiles'] as $fileInfo) {
            if ($fileInfo['id'] > $lastFile['id']) {
                $lastFile = $fileInfo;
            }
        }

        if ($lastFile['id'] <= 0) {
            throw new Exception('未找到可下载的文件');
        }

        $sha = '';
        if (!empty($lastFile['hashes'])) {
            foreach ($lastFile['hashes'] as $value) {
                if ($value['algo'] == 1) {
                    $sha = strtolower($value['value']);
                }
            }
        }

        if ($packInfo['sha'] == '' || $packInfo['sha'] != $sha) {
            if (empty($lastFile['downloadUrl'])) {
                throw new Exception('下载地址获取失败');
            }
            $this->app->getConsole()->setStatus('开始下载整合包文件: ' . App::showSize($lastFile['fileLength']));
            $downloader = $this->app->getDownloader();
            $downloader->download($lastFile['downloadUrl'], $packPath . '/overrides.zip', true);

            if ($sha != '') {
                $shaFile = strtolower(sha1_file($packPath . '/overrides.zip', false));
                if ($sha !== $shaFile) {
                    throw new Exception('文件校验失败');
                }
            }

            $this->app->extractTo($packPath, 'overrides.zip', '.');
        }

        $packInfo['id'] = $id;
        $packInfo['sha'] = $sha;
        $packInfo['file_id'] = $lastFile['id'];
        $packInfo['api'] = 'curseforge';

        return $packInfo;
    }

    public function getFiles(string $packPath) : array
    {
        $manifest = json_decode(file_get_contents($packPath . '/manifest.json'), true);
        if (empty($manifest['files'])) {
            throw new Exception('文件列表无效');
        }

        $fileIds = [];
        $fileHash = [];
        foreach ($manifest['files'] as $file) {
            $fileIds[] = $file['fileID'];
            $fileHash[$file['fileID']] = [
                'url' => $file['downloadUrl'],
                'project' => $file['projectID'],
                'type' => 'file',
                'dir' => 'overrides/mods',
            ];
        }

        $url = self::API_URL . '/v1/mods/files';
        $param = [
            'fileIds' => $fileIds,
        ];
        $data = $this->post($url, $param);
        $body = $data['body'];
        if (empty($body['data'])) {
            throw new Exception('获取文件信息失败');
        }
        $body = $body['data'];

        foreach ($body as $file) {
            if (empty($file['id'])) {
                continue;
            }
            $id = $file['id'];

            $sha = '';
            if (!empty($file['hashes'])) {
                foreach ($file['hashes'] as $value) {
                    if ($value['algo'] == 1) {
                        $sha = strtolower($value['value']);
                    }
                }
            }

            $fileHash[$id]['sha'] = $sha;
            $fileHash[$id]['name'] = $file['fileName'];
            $fileHash[$id]['size'] = $file['fileLength'];
        }

        $result = [];

        foreach ($fileHash as $id => $info) {
            if (empty($info['name']) || empty($info['size'])) {
                throw new Exception('缺少文件信息:' . Utils::printJson($info, true));
            }
            $result[] = $info;
        }

        return $result;
    }
}
