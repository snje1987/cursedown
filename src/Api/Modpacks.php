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

class Modpacks implements Api
{
    //document_url https://modpacksch.docs.apiary.io/
    const API_URL = 'https://api.modpacks.ch/public';
    const MANIFEST_FILE = 'manifest.json';
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function search(string $name) : array
    {
        $client = $this->app->getClient();
        $url = self::API_URL . '/modpack/search/50';

        $param = [];
        $param['term'] = $name; //Enigmatica

        $data = $client->get($url, $param, 'json');

        $body = $data['body'];

        $result = [];
        if (!empty($body['packs'])) {
            foreach ($body['packs'] as $id) {
                $result[] = [
                    'id' => $id,
                ];
            }
        }

        return $result;
    }

    public function info(int $id) : array
    {
        $client = $this->app->getClient();
        $url = self::API_URL . '/modpack/' . $id;

        $data = $client->get($url, [], 'json');
        $body = $data['body'];

        if (empty($body)) {
            return [];
        }

        $result = [];
        $result['name'] = $body['name'];
        $result['description'] = $body['description'];
        $result['versions'] = '';

        $versions = [];
        if (!empty($body['versions'])) {
            foreach ($body['versions'] as $version) {
                $versions[] = date('Y-m-d H:i:s', $version['updated']) . ' ' . $version['name'];
                if (count($result) > 10) {
                    array_shift($versions);
                }
            }

            $versions = array_reverse($versions);

            $result['versions'] = implode("\n", $versions);
        }

        return $result;
    }

    public function updatePackInfo(int $id, string $packPath, array $packInfo) : array
    {
        $console = $this->app->getConsole();
        $console->setStatus('获取整合包信息...');

        $client = $this->app->getClient();
        $url = self::API_URL . '/modpack/' . $id;

        $data = $client->get($url, [], 'json');
        $body = $data['body'];

        if (empty($body)) {
            return [];
        }

        $newFileId = 0;
        foreach ($body['versions'] as $file) {
            $new_id = intval($file['id']);
            if ($new_id > $newFileId) {
                $newFileId = $new_id;
            }
        }

        if (empty($newFileId)) {
            throw new Exception('未找到可下载的文件');
        }

        $console->setStatus('获取版本信息...');

        $url = self::API_URL . '/modpack/' . $id . '/' . $newFileId;
        $data = $client->get($url, [], 'json');

        $manifest = $data['body'];
        if (empty($manifest['files'])) {
            throw new Exception('信息获取失败');
        }

        $console->reset();
        file_put_contents($packPath . '/' . self::MANIFEST_FILE, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $packInfo['id'] = $id;
        $packInfo['sha'] = '';
        $packInfo['file_id'] = $newFileId;
        $packInfo['api'] = 'modpacks';

        return $packInfo;
    }

    public function getFiles(string $packPath, array $packInfo) : array
    {
        $manifest = json_decode(file_get_contents($packPath . '/' . self::MANIFEST_FILE), true);
        if (empty($manifest['files'])) {
            throw new Exception('文件列表无效');
        }

        $overrides = $packInfo['overrides'];

        $curseIds = [];
        $fileHash = [];

        foreach ($manifest['files'] as $file) {
            $fileHash[$file['id']] = [
                'project' => $file['id'],
                'type' => 'file',
                'dir' => $overrides . '/' . rtrim($file['path'], '\\/'),
                'name' => $file['name'],
                'sha' => $file['sha1'],
                'size' => $file['size'],
            ];
            if (!empty($file['url'])) {
                $fileHash[$file['id']]['url'] = $file['url'];
            } else {
                if (empty($file['curseforge']) || empty($file['curseforge']['file'])) {
                    throw new Exception('数据错误');
                }
                $curseIds[$file['curseforge']['file']] = $file['id'];
            }

            if (!empty($file['curseforge']) && !empty($file['curseforge']['project'])) {
                $fileHash[$file['id']]['project'] = $file['curseforge']['project'];
            }
        }

        $curseforge = new Curseforge($this->app);
        $files = $curseforge->getFilesInfo(array_keys($curseIds));

        foreach ($files as $file) {
            $id = $file['id'];
            if (!empty($file['downloadUrl'])) {
                $fid = $curseIds[$id];
                $fileHash[$fid]['url'] = $file['downloadUrl'];
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
}
