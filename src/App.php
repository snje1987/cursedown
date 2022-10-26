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
use Minifw\Console\Console;
use Minifw\Console\OptionParser;
use Minifw\Console\Utils;
use Org\Snje\Cursedown\Api\Api;

class App
{
    protected Console $console;
    protected Config $config;
    protected HttpClient $client;
    protected Downloader $downloader;
    protected OptionParser $parser;
    protected array $options;
    protected array $input;
    protected string $action;
    protected string $api = '';
    protected string $progress = '';
    protected static ?self $instance = null;
    protected array $errorList = [];
    protected array $noticeList = [];
    const API_LIST = ['curseforge', 'modpacks'];

    public static function get(?array $argv = null) : ?self
    {
        if (self::$instance === null) {
            try {
                self::$instance = new self($argv);
            } catch (Exception $ex) {
                $msg = $ex->getMessage();
                if (defined('DEBUG') && DEBUG) {
                    $msg = $ex->getFile() . '[' . $ex->getLine() . ']: ' . $msg;
                }
                echo $msg . "\n";

                return null;
            }
        }

        return self::$instance;
    }

    protected function __construct($argv)
    {
        $options = require(APP_ROOT . '/config/optionCfg.php');
        $this->parser = new OptionParser($options);

        array_shift($argv);
        $info = $this->parser->parse($argv);

        $this->options = $info['options'];
        $this->input = $info['input'];
        $this->action = $info['action'];

        $this->init($info['global']);
    }

    protected function init(array $global) : void
    {
        if (!empty($global['config'])) {
            $configPath = $global['config'];
        } else {
            $configPath = DATA_DIR . '/config.json';
        }

        $this->config = new Config($configPath);
        if ($this->config->get('debug')) {
            define('DEBUG', 1);
        } else {
            define('DEBUG', 0);
        }

        if (!empty($global['api'])) {
            $this->api = $global['api'];
        } else {
            $this->api = $this->config->get('api');
        }
        if (empty($this->api)) {
            throw new Exception('API未指定');
        }

        $this->console = new Console();

        $this->client = new HttpClient();
        $this->client->onError = function ($msg) {
            $this->console->print($msg);
        };

        $this->downloader = new Downloader(10);
        $this->downloader->showProgress = \Closure::fromCallable([$this, 'showDownload']);
        $this->downloader->onFinished = \Closure::fromCallable([$this, 'onFileDownloaded']);

        set_error_handler(function ($code, $msg, $file, $line) {
            if (DEBUG) {
                $msg = '[' . $code . '] ' . $file . '[' . $line . ']: ' . $msg;
            }
            $this->console->print($msg);
        });
    }

    public function run() : void
    {
        try {
            $function = 'do' . ucfirst($this->action);
            if (!method_exists($this, $function)) {
                throw new Exception('操作不存在');
            }

            call_user_func([$this, $function], $this->options, $this->input);
        } catch (Exception $ex) {
            $msg = $ex->getMessage();
            if (DEBUG) {
                $msg = '[' . $ex->getCode() . '] ' . $ex->getFile() . '[' . $ex->getLine() . ']: ' . $msg;
            }

            $this->console->reset()->print($msg);
        }
    }

    public function getConsole() : Console
    {
        return $this->console;
    }

    public function getClient() : HttpClient
    {
        return $this->client;
    }

    public function getDownloader() : Downloader
    {
        return $this->downloader;
    }

    public function getConfig(string $name)
    {
        return $this->config->get($name);
    }

    /////////////////////////////////////

    protected function getApi() : Api
    {
        $classname = __NAMESPACE__ . '\\Api\\' . ucfirst($this->api);
        $obj = new $classname($this);

        return $obj;
    }

    protected function doConfig() : void
    {
        if (!empty($this->options['get'])) {
            $name = $this->options['get'];
            $this->console->print($this->config->show($name));
        }
        if (!empty($this->options['set'])) {
            $pair = $this->options['set'];
            $this->config->set($pair[0], $pair[1])->save();
            $this->console->print($this->config->show($pair[0]));
        }
    }

    protected function doHelp() : void
    {
        $this->console->print($this->parser->getManual());
    }

    protected function doSearch() : void
    {
        $api = $this->getApi();

        $data = $api->search($this->options['name']);
        if (empty($data)) {
            $this->console->print('未找到搜索结果');
        } else {
            $cols = array_keys($data[0]);
            $colsConfig = [];
            foreach ($cols as $name) {
                $colsConfig[$name] = [
                    'name' => $name,
                    'align' => 'left',
                ];
            }
            $this->console->print(Utils::printTable($colsConfig, $data, [], '', true));
        }
    }

    protected function doInfo() : void
    {
        $packPath = $this->options['path'];
        $id = $this->options['id'];

        if (!empty($packPath)) {
            $packinfo = $this->loadPackInfo($packPath . '/packinfo.json');
            if (empty($packinfo['api'])) {
                throw new Exception('指定目录不是一个整合包目录');
            }
            $id = $packinfo['id'];
            $this->api = $packinfo['api'];
        }

        if (empty($id)) {
            throw new Exception('未指定整合包ID');
        }

        $api = $this->getApi();

        $data = $api->info($id);
        if (empty($data)) {
            $this->console->print('未找到搜索结果');
        } else {
            foreach ($data as $key => $value) {
                $this->console->print($key . ":\n\n" . $value . "\n");
            }
        }
    }

    protected function doDownload() : void
    {
        $packPath = $this->options['path'];
        $id = $this->options['id'];

        if (!file_exists($packPath)) {
            mkdir($packPath, 0777, true);
        }
        if (!is_dir($packPath)) {
            throw new Exception('无法创建保存目录');
        }

        $packInfoOld = $this->loadPackInfo($packPath . '/packinfo.json');

        if (empty($packInfoOld['id'])) {
            if (empty($id)) {
                throw new Exception('未指定整合包ID');
            }
        } else {
            if (!empty($id) && $id != $packInfoOld['id']) {
                throw new Exception('不能修改已下载整合包的ID');
            }
            $id = $packInfoOld['id'];
            if (!empty($packInfoOld['api'])) {
                $this->api = $packInfoOld['api'];
            }
        }

        $overridesPath = $packPath . '/overrides';
        //如果存在旧文件，则先备份旧文件，以防止丢失自定义修改
        if (file_exists($overridesPath) && !file_exists($overridesPath . '_old')) {
            rename($overridesPath, $overridesPath . '_old');
        }

        $api = $this->getApi();
        $packInfoNew = $api->updatePackInfo($id, $packPath, $packInfoOld);
        if (empty($packInfoNew)) {
            throw new Exception('获取整合包信息失败');
        }
        $this->savePackInfo($packPath . '/packinfo.json', $packInfoNew);

        $files = $api->getFiles($packPath);
        $files = self::praseFiles($files, $packInfoNew);

        //Utils::printJson($files);

        $i = 0;
        $total = count($files);

        foreach ($files as $file) {
            $this->progress = '[' . (++$i) . '/' . $total . '] ';
            $save = $packPath . '/' . $file['dir'] . '/' . $file['name'];
            $old = str_replace('overrides/', 'overrides_old/', $save);

            $dir = dirname($save);
            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }

            if ($file['sha'] != '') {
                if (file_exists($save)) {
                    $sha = strtolower(sha1_file($save));
                    if ($sha == $file['sha']) {
                        continue;
                    }
                }

                if (file_exists($old)) {
                    $sha = strtolower(sha1_file($old));
                    if ($sha == $file['sha']) {
                        rename($old, $save);
                        continue;
                    }
                }
            }

            if (empty($file['url'])) {
                $this->addNotice('缺少下载地址:' . Utils::printJson($file, true));
                continue;
            } else {
                $this->console->print('下载文件：' . $file['dir'] . '/' . $file['name'] . self::showSize($file['size']));
                $this->downloader->download($file['url'], $save, false);
            }
        }

        $this->downloader->flush();
        $this->console->reset();

        // foreach ($files as $file) {
        //     if ($file['type'] == 'extract') {
        //         $this->extractTo($packPath, $file['dir'] . '/' . $file['name'], $file['target']);
        //     }
        // }

        if (!empty($this->errorList)) {
            $this->showError();

            return;
        }

        $this->restoreChange($packPath);

        if (file_exists($packPath . '/overrides_old')) {
            self::clearDir($packPath . '/overrides_old', true);
        }

        if (!empty($this->noticeList)) {
            $this->showNotice();
        }
    }

    protected function doModify() : void
    {
        $packPath = $this->options['path'];
        if (!is_dir($packPath)) {
            throw new Exception('指定路径不存在');
        }

        $rm = [];

        if (!empty($this->options['rm'])) {
            $rm = $this->options['rm'];
        }

        if (empty($rm)) {
            return;
        }

        $packinfo = self::loadPackInfo($packPath . '/packinfo.json');

        if (!isset($packinfo['modify']) || !is_array($packinfo['modify'])) {
            $packinfo['modify'] = [];
        }

        foreach ($rm as $proj_id) {
            $packinfo['modify'][$proj_id] = [
                'type' => 'rm',
                'project' => strval($proj_id)
            ];
        }

        file_put_contents($packPath . '/packinfo.json', json_encode($packinfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    //////////////////////
    protected function downloadFile(string $packPath, array $file) : void
    {
    }

    protected function loadPackInfo(string $path) : array
    {
        if (!file_exists($path)) {
            return [
                'id' => '',
                'api' => '',
                'sha' => '',
            ];
        }

        $packInfo = json_decode(file_get_contents($path), true);
        if (!isset($packInfo['sha'])) {
            $packInfo['sha'] = '';
        }

        if (empty($packInfo['api'])) {
            throw new Exception('未指定api');
        }
        if (!in_array($packInfo['api'], self::API_LIST)) {
            throw new Exception('api不合法');
        }

        return $packInfo;
    }

    protected function savePackInfo(string $path, array $packInfo)
    {
        file_put_contents($path, json_encode($packInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function addError(string $msg) : void
    {
        $this->console->print($msg);
        $this->errorList[] = $msg;
    }

    public function addNotice(string $msg) : void
    {
        $this->noticeList[] = $msg;
    }

    protected function showError() : void
    {
        foreach ($this->errorList as $msg) {
            $this->console->print($msg);
        }
        $this->errorList = [];
    }

    protected function showNotice() : void
    {
        foreach ($this->noticeList as $msg) {
            $this->console->print($msg);
        }
        $this->noticeList = [];
    }

    protected function restoreChange(string $packPath) : void
    {
        $new_dir = $packPath . '/overrides/';
        $old_dir = $packPath . '/overrides_old/';

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

        if (file_exists($packPath . '/custom')) {
            self::overrideDir($packPath . '/custom', $packPath . '/overrides', true);
        }
    }

    ////////////////////////////////

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

    public function extractTo(string $packPath, string $filename, string $dir)
    {
        $zipPath = $packPath . '/' . $filename;
        $this->console->print('解压文件: ' . $zipPath);

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($packPath . '/' . $dir);

        $this->console->print('文件解压完成：' . $zipPath);
    }

    public static function showSize(int $size) : string
    {
        return " \033[32m[" . HttpClient::showSize($size) . "\033[0m]";
    }

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

    protected static function praseFiles(array $files, array $packinfo) : array
    {
        if (empty($packinfo['modify']) || !is_array($packinfo['modify'])) {
            return $files;
        }

        $removed = [];
        foreach ($packinfo['modify'] as $v) {
            if ($v['type'] == 'rm') {
                $removed[$v['project']] = 1;
            }
        }

        foreach ($files as $k => $file) {
            if (!empty($file['project']) && isset($removed[$file['project']])) {
                unset($files[$k]);
            }
        }

        return $files;
    }
}
