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
use Minifw\Console\Cmd;
use Minifw\Console\Console;

/**
 * @document_url https://modpacksch.docs.apiary.io/
 */
class App
{
    protected HttpClient $client;
    protected Downloader $downloader;
    protected string $action;
    protected array $args;
    protected Console $console;
    protected Config $config;
    protected array $errorList = [];
    protected string $progress = '';
    protected string $lastMsg = '';
    const DEFAULT_ARGS = [
        'search' => [
            'name' => [ //整合包名称
                'default' => null,
                'alias' => ['s'],
                'params' => ['string']
            ]
        ],
        'info' => [
            'id' => [ //整合包ID
                'default' => null,
                'alias' => ['id'],
                'params' => ['int']
            ],
            'curse' => [
                'default' => null,
                'alias' => ['c'],
                'params' => ['bool']
            ]
        ],
        'download' => [
            'id' => [ //整合包ID
                'default' => null,
                'alias' => ['id'],
                'params' => ['int']
            ],
            'path' => [ //保存路径
                'default' => null,
                'alias' => ['p'],
                'params' => ['dir']
            ],
            'curse' => [
                'default' => null,
                'alias' => ['c'],
                'params' => ['bool']
            ]
        ],
        'modify' => [
            'path' => [ //保存路径
                'default' => '.',
                'alias' => ['p'],
                'params' => ['dir']
            ],
            'rm' => [ //模组列表
                'default' => [],
                'alias' => ['r'],
                'params' => [['type' => 'array', 'data_type' => 'int']]
            ]
        ],
        'help' => [
        ]
    ];
    const API_URL = 'https://api.modpacks.ch/public/';
    const CACHE_DIR = DATA_DIR . '/cache';

    public function __construct()
    {
        $this->config = new Config(DATA_DIR . '/config.json');
        if ($this->config->get('debug')) {
            define('DEBUG', 1);
        } else {
            define('DEBUG', 0);
        }

        $this->console = new Console();
        $this->client = new HttpClient($this->console);
        $this->downloader = new Downloader(10);
        $this->downloader->showProgress = \Closure::fromCallable([$this, 'showDownload']);
        $this->downloader->onFinished = \Closure::fromCallable([$this, 'onFileDownloaded']);
    }

    public function run(array $argv) : void
    {
        try {
            array_shift($argv);
            $this->parseArgs($argv);
            $this->doJob();
        } catch (Exception $ex) {
            $msg = $ex->getMessage();
            if (DEBUG) {
                $msg = $ex->getFile() . '[' . $ex->getLine() . ']: ' . $msg;
            }

            $this->console->reset()->print($msg);
        }
    }

    /////////////////////////////////////

    public static function clearDir(string $path, bool $remove = false) : void
    {
        if (!is_dir($path)) {
            throw new Exception('参数不合法：' . $path);
        }

        $dir = opendir($path);

        while (($file = readdir($dir)) !== false) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $full = $path . '/' . $file;
            if (is_dir($full)) {
                self::clearDir($full);
                rmdir($full);
            } else {
                unlink($full);
            }
        }

        if ($remove) {
            rmdir($path);
        }
    }

    public static function mergeDir(string $from, string $to, bool $includeSub = false) : void
    {
        if (!file_exists($to)) {
            mkdir($to, 0777, true);
        }

        $from_list = scandir($from);
        $to_list = scandir($to);

        $to_hash = [];

        foreach ($to_list as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $to_hash[$file] = 1;
        }

        foreach ($from_list as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            if (isset($to_hash[$file])) {
                if ($includeSub && is_dir($to . '/' . $file)) {
                    self::mergeDir($from . '/' . $file, $to . '/' . $file, $includeSub);
                } else {
                    continue;
                }
            } else {
                rename($from . '/' . $file, $to . '/' . $file);
            }
        }
    }

    public static function overrideDir(string $from, string $to, bool $copy = false) : void
    {
        if (file_exists($to) && !is_dir($to)) {
            unlink($to);
        }

        if (!file_exists($to)) {
            mkdir($to, 0777, true);
        }

        $from_list = scandir($from);
        $to_list = scandir($to);

        $to_hash = [];

        foreach ($to_list as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $to_hash[$file] = 1;
        }

        foreach ($from_list as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            $src = $from . '/' . $file;
            $dst = $to . '/' . $file;

            if (isset($to_hash[$file])) {
                if (is_file($src)) {
                    if (is_dir($dst)) {
                        self::clearDir($dst, true);
                    }
                    if ($copy) {
                        copy($src, $dst);
                    } else {
                        rename($src, $dst);
                    }
                } else {
                    if (is_file($dst)) {
                        unlink($dst);
                    }
                    self::overrideDir($src, $dst, $copy);
                }
            } else {
                if ($copy) {
                    if (is_file($src)) {
                        copy($src, $dst);
                    } else {
                        self::overrideDir($src, $dst, $copy);
                    }
                } else {
                    rename($src, $dst);
                }
            }
        }
    }

    public static function fileIsOk(array $info, string $path) : bool
    {
        if (!file_exists($path)) {
            return false;
        }

        if (filesize($path) != $info['size']) {
            return false;
        }

        if ($info['sha1'] !== '') {
            $sha1 = sha1_file($path, false);
            if ($sha1 !== $info['sha1']) {
                return false;
            }
        }

        return true;
    }

    ///////////////////////////////////

    protected function printHelp() : void
    {
        $help_file = APP_ROOT . '/res/help.txt';

        $data = file_get_contents($help_file);

        $this->console->print($data);
    }

    protected function parseArgs(array $argv) : void
    {
        $this->action = Cmd::getAction($argv, self::DEFAULT_ARGS);
        array_shift($argv);

        $cfg = self::DEFAULT_ARGS[$this->action];
        $this->args = Cmd::getArgs($argv, $cfg);
    }

    public function onFileDownloaded(string $url, int $error, string $msg = '') : void
    {
        if ($error !== Downloader::TASK_RESULT_OK) {
            $this->addError('下载出错: ' . $url . ' ' . $msg);
        }
    }

    public function showDownload(?string $msg = null) : void
    {
        if ($msg !== null) {
            $this->lastMsg = $msg;
        }
        $this->console->setStatus($this->progress . $this->lastMsg);
    }

    protected function addError(string $msg) : void
    {
        $this->errorList[] = $msg;
    }

    protected function showError() : void
    {
        foreach ($this->errorList as $msg) {
            $this->console->print($msg);
        }
    }

    ////////////////////////////////////////////////

    protected function doJob() : void
    {
        $function = 'do' . ucfirst($this->action);
        call_user_func([$this, $function]);
    }

    protected function doSearch() : void
    {
        if (empty($this->args['name'])) {
            throw new Exception('必须指定参数[name]');
        }

        $name = $this->args['name'];

        $url = self::API_URL . 'modpack/search/50';

        $param = [];

        $param['term'] = $name; //Enigmatica

        $data = $this->client->get($url, $param, 'json');

        $body = $data['body'];

        if (!empty($body['packs'])) {
            $msg = 'Packs: ' . implode(', ', $body['packs']);
            $this->console->print($msg);
        }
        if (!empty($body['curseforge'])) {
            $msg = 'Curseforge: ' . implode(', ', $body['curseforge']);
            $this->console->print($msg);
        }
    }

    protected function doModify() : void
    {
        if ($this->args['path'] === '.') {
            $this->args['path'] = Cmd::getFullPath('.');
        }

        if (empty($this->args['path'])) {
            throw new Exception('参数[path]不能为空');
        }

        $pack_path = $this->args['path'];

        $rm = [];

        if (!empty($this->args['rm'])) {
            $rm = $this->args['rm'];
        }

        if (!empty($rm)) {
            $this->console->print('正在编辑模组');
            $this->actionDependencyModify($pack_path, $rm);
        }
    }

    protected function doDownload() : void
    {
        if (empty($this->args['path'])) {
            throw new Exception('参数[path]不能为空');
        }

        $id = null;
        $pack_path = Cmd::getFullPath($this->args['path']);

        if (!empty($this->args['id'])) {
            $id = strval($this->args['id']);
        }

        $is_curse = null;
        if (isset($this->args['curse'])) {
            $is_curse = $this->args['curse'];
        }

        $pack_path = $this->actionDownloadModpack($id, $pack_path, $is_curse);
        $this->actionDownloadDependency($pack_path);

        if (!empty($this->errorList)) {
            $this->showError();

            return;
        }

        $this->actionRestoreChange($pack_path);

        if (file_exists($pack_path . '/overrides_old')) {
            self::clearDir($pack_path . '/overrides_old', true);
        }
        rename($pack_path . '/curse_new.json', $pack_path . '/curse.json');
        rename($pack_path . '/manifest_new.json', $pack_path . '/manifest.json');
    }

    protected function doInfo() : void
    {
        if (empty($this->args['id'])) {
            throw new Exception('必须指定ID');
        }
        $id = strval($this->args['id']);

        $is_curse = false;
        if (isset($this->args['curse'])) {
            $is_curse = $this->args['curse'];
        }

        if ($is_curse) {
            $url = self::API_URL . 'curseforge/' . $id;
        } else {
            $url = self::API_URL . 'modpack/' . $id;
        }

        $this->console->setStatus('获取整合包信息...');
        $data = $this->client->get($url, [], 'json');
        $body = $data['body'];

        if (!empty($body['name'])) {
            $this->console->print('name: ' . $body['name']);
        }

        if (!isset($body['versions'])) {
            throw new Exception('下载失败:' . $id);
        }

        $new_file = [];
        foreach ($body['versions'] as $file) {
            if (empty($new_file) || $file['id'] > $new_file['id']) {
                $new_file = $file;
            }
        }

        if (!empty($new_file)) {
            $this->console->print('updated: ' . $new_file['name'] . ' ' . $new_file['type'] . ' ' . date('Y-m-d H:i:s', $new_file['updated']));
        }

        if (!empty($body['description'])) {
            $this->console->print('description: ' . $body['description']);
        }

        $this->console->reset();
    }

    protected function doHelp() : void
    {
        $this->printHelp();
    }

    //////////////////////////////////////////

    protected function actionDownloadModpack(?string $id, string $packDir, ?bool $isCurse) : string
    {
        $curse_old = [];

        $info_file = $packDir . '/curse.json';
        if (file_exists($info_file)) {
            $json = file_get_contents($info_file);
            $curse_old = json_decode($json, true);
        }

        if ($id !== null && !empty($curse_old) && $curse_old['id'] !== $id) {
            throw new Exception('整合包ID不正确');
        }

        if ($id === null) {
            if (!empty($curse_old) && !empty($curse_old['id'])) {
                $id = $curse_old['id'];
            } else {
                throw new Exception('缺少整合包ID');
            }
        }

        if ($isCurse === null) {
            if (!empty($curse_old) && isset($curse_old['is_curse'])) {
                $isCurse = $curse_old['is_curse'];
            } else {
                $isCurse = false;
            }
        }

        if ($isCurse) {
            $url = self::API_URL . 'curseforge/' . $id;
        } else {
            $url = self::API_URL . 'modpack/' . $id;
        }

        $this->console->setStatus('获取整合包信息...');
        $data = $this->client->get($url, [], 'json');

        if (empty($data['body']) || !is_array($data['body'])) {
            throw new Exception('下载失败:' . $id);
        }
        $data = $data['body'];

        if (!isset($data['versions'])) {
            throw new Exception('下载失败:' . $id);
        }

        $new_file_id = 0;
        foreach ($data['versions'] as $file) {
            $new_id = intval($file['id']);
            if ($new_id > $new_file_id) {
                $new_file_id = $new_id;
            }
        }

        if (empty($new_file_id)) {
            throw new Exception('未找到可下载的文件');
        }

        if ($isCurse) {
            $url = self::API_URL . 'curseforge/' . $id;
        } else {
            $url = self::API_URL . 'modpack/' . $id;
        }

        $url .= '/' . $new_file_id;

        $this->console->setStatus('获取版本信息...');
        $data = $this->client->get($url, [], 'json');

        $manifest = $data['body'];
        if (empty($manifest['files'])) {
            throw new Exception('信息获取失败');
        }

        if (!file_exists($packDir)) {
            mkdir($packDir, 0777, true);
        }

        $this->console->reset();

        $curse = $curse_old;
        $curse['id'] = strval($id);
        $curse['file_id'] = $new_file_id;
        $curse['is_curse'] = $isCurse;

        $new_path = $packDir . '/overrides';

        if (is_dir($new_path)) {
            $old_path = $new_path . '_old';
            if (!file_exists($old_path)) {
                rename($new_path, $old_path);
            }
        }

        file_put_contents($packDir . '/manifest_new.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($packDir . '/curse_new.json', json_encode($curse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $packDir;
    }

    protected function actionDownloadDependency(string $packPath) : void
    {
        $pack_info = self::loadPackInfo($packPath, true);

        $mainfest = $pack_info['manifest'];
        $curse = $pack_info['curse'];

        $this->console->print('正在下载模组');

        $files = self::praseDependency($mainfest['files'], $curse);

        $total = count($files);
        $i = 0;
        foreach ($files as $file) {
            $this->progress = '[' . (++$i) . '/' . $total . '] ';
            $this->actionDownloadFile($packPath, $file);
        }

        $this->downloader->flush();
        $this->console->reset();

        foreach ($files as $file) {
            if ($file['type'] == 'cf-extract') {
                $new_path = $packPath . '/' . $file['name'];
                $this->console->print('解压文件: ' . $file['name']);

                $zip = new \ZipArchive();
                $zip->open($new_path);
                $zip->extractTo($packPath . '/' . $file['path']);

                $this->console->print('文件解压完成：' . $file['name']);
            }
        }
    }

    protected function actionDownloadFile(string $packPath, array $file) : void
    {
        if (!file_exists($packPath)) {
            mkdir($packPath, 0777, true);
        }

        $new_dir = $packPath . '/overrides/';
        $old_dir = $packPath . '/overrides_old/';

        if ($file['type'] == 'mod' || $file['type'] == 'resource' || $file['type'] == 'config' || $file['type'] == 'script') {
            $new_path = $new_dir . $file['path'] . $file['name'];
            $old_path = $old_dir . $file['path'] . $file['name'];
            $this->showDownload(null);
            $this->actionDownloadWithCache($file, $new_path, $old_path);
        } elseif ($file['type'] == 'cf-extract') {
            $new_path = $packPath . '/' . $file['name'];
            $this->console->print('下载文件：' . $file['name'] . self::showSize($file['size']));
            $this->showDownload(null);
            $this->downloader->download($file['url'], $new_path);
        } else {
            throw new Exception('manifest数据不合法' . $file['type']);
        }
    }

    protected function actionDownloadWithCache(array $info, string $newPath, string $oldPath) : void
    {
        $dir = dirname($newPath);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($newPath) && self::fileIsOk($info, $newPath)) {
            return;
        } elseif (file_exists($oldPath) && self::fileIsOk($info, $oldPath)) {
            rename($oldPath, $newPath);
        } else {
            $this->console->print('下载文件：' . $info['path'] . $info['name'] . self::showSize($info['size']));
            $this->downloader->download($info['url'], $newPath);
        }
    }

    protected function actionRestoreChange(string $pack_path) : void
    {
        $new_dir = $pack_path . '/overrides/';
        $old_dir = $pack_path . '/overrides_old/';

        if (!file_exists($old_dir)) {
            return;
        }

        $dirs = ['saves'];
        foreach ($dirs as $dir) {
            if (file_exists($old_dir . $dir)) {
                self::overrideDir($old_dir . $dir, $new_dir . $dir);
            }
        }

        $dirs = ['config'];
        foreach ($dirs as $dir) {
            if (file_exists($old_dir . $dir)) {
                self::mergeDir($old_dir . $dir, $new_dir . $dir, true);
            }
        }

        self::mergeDir($old_dir, $new_dir);

        if (file_exists($pack_path . '/custom')) {
            self::overrideDir($pack_path . '/custom', $pack_path . '/overrides', true);
        }
    }

    //////////////////////////////////////////////////////

    protected function actionDependencyModify(string $packPath, array $rm = []) : void
    {
        $pack_info = self::loadPackInfo($packPath);

        $curse = $pack_info['curse'];

        if (!isset($curse['modify']) || !is_array($curse['modify'])) {
            $curse['modify'] = [];
        }

        foreach ($rm as $proj_id) {
            $curse['modify'][$proj_id] = [
                'type' => 'rm',
                'id' => strval($proj_id)
            ];
        }

        file_put_contents($packPath . '/curse.json', json_encode($curse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->console->print('编辑完成');
    }

    ////////////////////////////////////////////////////////////////////

    protected static function praseDependency(array $origin, array $curse) : array
    {
        $deps = [];

        foreach ($origin as $file) {
            $deps[$file['id']] = $file;
        }

        if (!empty($curse['modify']) && is_array($curse['modify'])) {
            foreach ($curse['modify'] as $v) {
                if ($v['type'] == 'rm') {
                    if (isset($deps[$v['id']])) {
                        unset($deps[$v['id']]);
                    }
                }
            }
        }

        return $deps;
    }

    protected static function loadPackInfo(string $packPath, bool $isNew = false) : array
    {
        if ($isNew) {
            $curse_path = $packPath . '/curse_new.json';
        } else {
            $curse_path = $packPath . '/curse.json';
        }

        $json = file_get_contents($curse_path);
        $curse = json_decode($json, true);

        if (empty($curse)) {
            throw new Exception('curse.json格式错误');
        }

        if ($isNew) {
            $file_path = $packPath . '/manifest_new.json';
        } else {
            $file_path = $packPath . '/manifest.json';
        }

        $json = file_get_contents($file_path);
        $mainfest = json_decode($json, true);

        if (empty($mainfest) || empty($mainfest['files'])) {
            throw new Exception('manifest.json格式错误');
        }

        if (empty($mainfest['targets'])) {
            throw new Exception('manifest.json格式错误');
        }

        return [
            'manifest' => $mainfest,
            'curse' => $curse
        ];
    }

    ///////////////////////////////////////////////////////////////////

    public static function showSize(int $size) : string
    {
        return " \033[32m[" . HttpClient::showSize($size) . "\033[0m]";
    }
}
