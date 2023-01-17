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
use Org\Snje\Cursedown\App;

class Curseforge implements Api
{
    //document_url https://docs.curseforge.com/?
    const API_URL = 'https://api.curseforge.com';
    const MANIFEST_FILE = 'manifest.json';
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
        $this->app->getConsole()->setStatus('获取整合包信息');
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
                if ($lastFile['id'] <= 9999999 && $lastFile['id'] >= 1000000) {
                    $lastFile['downloadUrl'] = 'https://edge.forgecdn.net/files/' . intval($lastFile['id'] / 1000) . '/' . intval($lastFile['id'] % 1000) . '/' . $lastFile['fileName'];
                }
            }

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
        } else {
            $this->app->getConsole()->setStatus('整合包文件未更新');
        }

        $this->app->extractTo($packPath, 'overrides.zip', '.');

        $url = self::API_URL . '/v1/mods/' . $id . '/files/' . $lastFile['id'] . '/changelog';
        $param = [];
        $data = $this->get($url, $param);

        if (!empty($data['body']) && !empty($data['body']['data'])) {
            file_put_contents($packPath . '/changelog.html', $data['body']['data']);
        }

        $packInfo['id'] = $id;
        $packInfo['sha'] = $sha;
        $packInfo['file_id'] = $lastFile['id'];
        $packInfo['api'] = 'curseforge';

        return $packInfo;
    }

    public function getFile(int $modid, int $fileid)
    {
        $url = self::API_URL . '/v1/mods/' . $modid . '/files/' . $fileid;
        $param = [];
        $data = $this->get($url, $param);
        if (!empty($data['body']) && !empty($data['body']['data'])) {
            return $data['body']['data'];
        }
        throw new Exception('获取信息失败');
    }

    public function getFilesInfo(array $fileIds) : array
    {
        $fileHash = [];
        foreach ($fileIds as $id) {
            $fileHash[$id] = 1;
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
        $result = [];
        foreach ($body as $file) {
            if (empty($file['id'])) {
                continue;
            }

            $id = $file['id'];
            if (!isset($fileHash[$id])) {
                continue;
            }

            $sha = '';
            if (!empty($file['hashes'])) {
                foreach ($file['hashes'] as $value) {
                    if ($value['algo'] == 1) {
                        $sha = strtolower($value['value']);
                    }
                }
            }

            $file['sha'] = $sha;
            if (empty($file['downloadUrl'])) {
                if ($file['id'] <= 9999999 && $file['id'] >= 1000000) {
                    $file['downloadUrl'] = 'https://edge.forgecdn.net/files/' . intval($file['id'] / 1000) . '/' . intval($file['id'] % 1000) . '/' . $file['fileName'];
                }
            }
            $result[] = $file;
        }

        return $result;
    }

    public function getFiles(string $packPath, array $packInfo) : array
    {
        $manifest = json_decode(file_get_contents($packPath . '/' . self::MANIFEST_FILE), true);
        if (empty($manifest['files'])) {
            throw new Exception('文件列表无效');
        }

        $overrides = $packInfo['overrides'];

        $fileIds = [];
        $fileHash = [];
        foreach ($manifest['files'] as $file) {
            $fileIds[] = $file['fileID'];
            $fileHash[$file['fileID']] = [
                'project' => $file['projectID'],
                'type' => 'file',
                'dir' => $overrides . '/mods',
            ];

            if (!empty($file['downloadUrl'])) {
                $fileHash[$file['fileID']]['url'] = $file['downloadUrl'];
            }
        }

        $body = $this->getFilesInfo($fileIds);

        foreach ($body as $file) {
            $id = $file['id'];

            $fileHash[$id]['sha'] = $file['sha'];
            $fileHash[$id]['name'] = $file['fileName'];
            $fileHash[$id]['size'] = $file['fileLength'];
            if (!empty($file['downloadUrl'])) {
                $fileHash[$id]['url'] = $file['downloadUrl'];
            }
        }

        $result = [];
        foreach ($fileHash as $id => $info) {
            if (empty($info['name'])) {
                throw new Exception('缺少关键信息');
            }
            $result[] = $info;
        }

        return $result;
    }

    public function checkUpdate(string $packPath, array $packInfo) : array
    {
        $result = [];
        $result['id'] = $packInfo['id'];

        $manifest = json_decode(file_get_contents($packPath . '/' . self::MANIFEST_FILE), true);
        if (empty($manifest['files'])) {
            throw new Exception('整合包信息不合法: ' . $packPath);
        }

        $result['currentVersion'] = $manifest['version'];
        $result['name'] = $manifest['name'];

        $url = self::API_URL . '/v1/mods/' . $packInfo['id'];
        $param = [];
        $data = $this->get($url, $param);

        $body = $data['body'];
        if (empty($body['data'])) {
            return [];
        }

        $body = $body['data'];
        if (empty($body['latestFiles'])) {
            $result['latestVersion'] = '';
            $result['updateTime'] = '';
        } else {
            $lastFile = ['id' => 0];
            foreach ($body['latestFiles'] as $fileInfo) {
                if ($fileInfo['id'] > $lastFile['id']) {
                    $lastFile = $fileInfo;
                }
            }

            if ($lastFile['id'] <= 0) {
                $result['latestVersion'] = '';
                $result['updateTime'] = '';
            } else {
                $date = strtotime($lastFile['fileDate']);
                $result['latestVersion'] = $lastFile['displayName'];
                $result['updateTime'] = date('Y-m-d', $date);
            }
        }

        return $result;
    }
}
