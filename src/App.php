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
use Minifw\Console\Console;
use Minifw\Console\Cmd;

/**
 * @document_url https://modpacksch.docs.apiary.io/
 */
class App {

    /**
     * @var HttpClient
     */
    protected $client;
    protected $action;
    protected $args;
    protected $console;
    protected $config;

    const DEFAULT_ARGS = [
        'search' => [
            'name' => [//整合包名称
                'default' => null,
                'alias' => ['s'],
                'params' => ['string'],
            ],
        ],
        'download' => [
            'id' => [//整合包ID
                'default' => null,
                'alias' => ['id'],
                'params' => ['int'],
            ],
            'path' => [//保存路径
                'default' => null,
                'alias' => ['p'],
                'params' => ['dir'],
            ],
            'curse' => [
                'default' => null,
                'alias' => ['c'],
                'params' => ['bool'],
            ],
        ],
        'modify' => [
            'path' => [//保存路径
                'default' => '.',
                'alias' => ['p'],
                'params' => ['dir'],
            ],
            'rm' => [//模组列表
                'default' => [],
                'alias' => ['r'],
                'params' => [['type' => 'array', 'data_type' => 'int']],
            ],
        ],
    ];
    const API_URL = 'https://api.modpacks.ch/public/';
    const CACHE_DIR = DATA_DIR . '/cache';

    public function __construct() {
        $this->config = new Config(DATA_DIR . '/config.json');
        if ($this->config->get('debug')) {
            define('DEBUG', 1);
        }
        else {
            define('DEBUG', 0);
        }

        $this->console = new Console();
        $this->client = new HttpClient($this->console);
    }

    public function run($argv) {
        try {
            array_shift($argv);
            $this->parse_args($argv);
            $this->do_job();
        }
        catch (Exception $ex) {
            $msg = $ex->getMessage();
            if (DEBUG) {
                $msg = $ex->getFile() . '[' . $ex->getLine() . ']: ' . $msg;
            }

            $this->console->reset()->print($msg);
            $this->print_help();
        }
    }

    /////////////////////////////////////

    public static function clear_dir($path, $remove = false) {
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
                self::clear_dir($full);
                rmdir($full);
            }
            else {
                unlink($full);
            }
        }

        if ($remove) {
            rmdir($path);
        }
    }

    public static function merge_dir($from, $to) {
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
                continue;
            }

            rename($from . '/' . $file, $to . '/' . $file);
        }
    }

    public static function override_dir($from, $to, $copy = false) {
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
                        self::clear_dir($dst, true);
                    }
                    if ($copy) {
                        copy($src, $dst);
                    }
                    else {
                        rename($src, $dst);
                    }
                }
                else {
                    if (is_file($dst)) {
                        unlink($dst);
                    }
                    self::override_dir($src, $dst);
                }
            }
            else {
                if ($copy) {
                    if (is_file($src)) {
                        copy($src, $dst);
                    }
                    else {
                        self::override_dir($src, $dst);
                    }
                }
                else {
                    rename($src, $dst);
                }
            }
        }
    }

    public static function file_is_ok($info, $path) {
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

    protected function print_help() {
        $help_file = APP_ROOT . '/res/help.txt';

        $data = file_get_contents($help_file);

        $this->console->print($data);
    }

    protected function parse_args($argv) {
        $this->action = Cmd::get_action($argv, self::DEFAULT_ARGS);
        array_shift($argv);

        $cfg = self::DEFAULT_ARGS[$this->action];
        $this->args = Cmd::get_args($argv, $cfg);
    }

    ////////////////////////////////////////////////

    protected function do_job() {
        $function = 'do_' . $this->action;
        call_user_func([$this, $function]);
    }

    protected function do_search() {
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
            $msg = 'ID: ' . implode(', ', $body['packs']);
            $this->console->print($msg);
        }
    }

    protected function do_modify() {
        if ($this->args['path'] === '.') {
            $this->args['path'] = Cmd::get_full_path('.');
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
            $this->action_dependency_modify($pack_path, $rm);
        }
    }

    protected function do_download() {
        if (empty($this->args['path'])) {
            throw new Exception('参数[path]不能为空');
        }

        $id = null;
        $pack_path = Cmd::get_full_path($this->args['path']);

        if (!empty($this->args['id'])) {
            $id = strval($this->args['id']);
        }

        $is_curse = null;
        if (isset($this->args['curse'])) {
            $is_curse = $this->args['curse'];
        }

        $pack_path = $this->action_download_modpack($id, $pack_path, $is_curse);
        $this->action_download_dependency($pack_path);
        $this->action_restore_change($pack_path);

        if (file_exists($pack_path . '/overrides_old')) {
            self::clear_dir($pack_path . '/overrides_old', true);
        }
        rename($pack_path . '/curse_new.json', $pack_path . '/curse.json');
        rename($pack_path . '/manifest_new.json', $pack_path . '/manifest.json');
    }

    //////////////////////////////////////////

    protected function action_download_modpack($id, $pack_dir, $is_curse) {
        $curse_old = [];

        $info_file = $pack_dir . '/curse.json';
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
            }
            else {
                throw new Exception('缺少整合包ID');
            }
        }

        if ($is_curse === null) {
            if (!empty($curse_old) && isset($curse_old['is_curse'])) {
                $is_curse = $curse_old['is_curse'];
            }
            else {
                $is_curse = false;
            }
        }

        if ($is_curse) {
            $url = self::API_URL . 'curseforge/' . $id;
        }
        else {
            $url = self::API_URL . 'modpack/' . $id;
        }

        $this->console->set_status('获取整合包信息...');
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
            if (strtolower($file['type']) != 'release') {
                continue;
            }

            $new_id = intval($file['id']);
            if ($new_id > $new_file_id) {
                $new_file_id = $new_id;
            }
        }

        if (empty($new_file_id)) {
            throw new Exception('未找到可下载的文件');
        }

        if ($is_curse) {
            $url = self::API_URL . 'curseforge/' . $id;
        }
        else {
            $url = self::API_URL . 'modpack/' . $id;
        }

        $url .= '/' . $new_file_id;

        $this->console->set_status('获取版本信息...');
        $data = $this->client->get($url, [], 'json');

        $manifest = $data['body'];
        if (empty($manifest['files'])) {
            throw new Exception('信息获取失败');
        }

        if (!file_exists($pack_dir)) {
            mkdir($pack_dir, 0777, true);
        }

        $this->console->reset();

        $curse = $curse_old;
        $curse['id'] = strval($id);
        $curse['file_id'] = $new_file_id;
        $curse['is_curse'] = $is_curse;

        $new_path = $pack_dir . '/overrides';

        if ($curse_old['file_id'] != $curse['file_id'] || !is_dir($new_path)) {
            if (is_dir($new_path)) {
                $old_path = $new_path . '_old';
                if (!file_exists($old_path)) {
                    rename($new_path, $old_path);
                }
            }
        }

        file_put_contents($pack_dir . '/manifest_new.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($pack_dir . '/curse_new.json', json_encode($curse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $pack_dir;
    }

    protected function action_download_dependency($pack_path) {
        $pack_info = self::load_pack_info($pack_path, true);

        $mainfest = $pack_info['manifest'];
        $curse = $pack_info['curse'];

        $this->console->print('正在下载模组');

        $files = self::prase_dependency($mainfest['files'], $curse);

        $total = count($files);
        $i = 0;
        foreach ($files as $file) {
            $this->action_download_file($pack_path, $file, (++$i) . '/' . $total);
        }
    }

    protected function action_download_file($pack_path, $file, $progress = '') {
        if (!file_exists($pack_path)) {
            mkdir($pack_path, 0777, true);
        }

        $new_dir = $pack_path . '/overrides/';
        $old_dir = $pack_path . '/overrides_old/';

        if ($progress !== '') {
            $progress = "[$progress] ";
        }

        if ($file['type'] == 'mod' || $file['type'] == 'resource' || $file['type'] == 'config' || $file['type'] == 'script') {
            $new_path = $new_dir . $file['path'] . $file['name'];
            $old_path = $old_dir . $file['path'] . $file['name'];
            $this->action_download_with_cache($file, $new_path, $old_path, $progress);
        }
        elseif ($file['type'] == 'cf-extract') {
            $new_path = $pack_path . '/' . $file['name'];

            $this->console->set_status('下载地址：' . $file['url']);
            $this->console->print($progress . '下载文件：' . $file['name'] . self::show_size($file['size']));
            $this->client->download($file['url'], $new_path);
            $this->console->reset();

            $this->console->set_status('解压文件...');

            $zip = new \ZipArchive();
            $zip->open($new_path);
            $zip->extractTo($pack_path . '/' . $file['path']);

            $this->console->reset()->print('文件解压完成：' . $file['name']);
        }
        else {
            throw new Exception('manifest数据不合法' . $file['type']);
        }
    }

    protected function action_download_with_cache($info, $new_path, $old_path, $progress) {
        $dir = dirname($new_path);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($new_path) && self::file_is_ok($info, $new_path)) {
            $this->console->reset()->print($progress . '文件已存在：' . basename($new_path) . self::show_size($info['size']));
            return;
        }
        elseif (file_exists($old_path) && self::file_is_ok($info, $old_path)) {
            $this->console->reset()->print($progress . '文件未更新：' . basename($new_path) . self::show_size($info['size']));
            rename($old_path, $new_path);
        }
        else {
            $this->console->set_status('下载地址：' . $info['url']);
            $this->console->print($progress . '下载文件：' . $info['name'] . self::show_size($info['size']));
            $this->client->download($info['url'], $new_path);
            $this->console->reset();
        }
    }

    protected function action_restore_change($pack_path) {
        $new_dir = $pack_path . '/overrides/';
        $old_dir = $pack_path . '/overrides_old/';

        $dirs = ['saves'];
        foreach ($dirs as $dir) {
            if (file_exists($old_dir . '/' . $dir)) {
                self::override_dir($old_dir . '/' . $dir, $new_dir . '/' . $dir);
            }
        }

        $dirs = ['config', 'journeymap'];
        foreach ($dirs as $dir) {
            if (file_exists($old_dir . '/' . $dir)) {
                self::merge_dir($old_dir . '/' . $dir, $new_dir . '/' . $dir);
            }
        }

        $files = ['options.txt'];
        foreach ($files as $file) {
            if (file_exists($old_dir . '/' . $file)) {
                rename($old_dir . '/' . $file, $new_dir . '/' . $file);
            }
        }

        if (file_exists($pack_path . '/custom')) {
            self::override_dir($pack_path . '/custom', $pack_path . '/overrides', true);
        }
    }

    //////////////////////////////////////////////////////

    protected function action_dependency_modify($pack_path, $rm = []) {
        $pack_info = self::load_pack_info($pack_path);

        $curse = $pack_info['curse'];

        if (!is_array($curse['modify'])) {
            $curse['modify'] = [];
        }

        foreach ($rm as $proj_id) {
            $curse['modify'][$proj_id] = [
                'type' => 'rm',
                'id' => strval($proj_id),
            ];
        }

        file_put_contents($pack_path . '/curse.json', json_encode($curse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->console->print('编辑完成');
    }

    ////////////////////////////////////////////////////////////////////

    protected static function prase_dependency($origin, $curse) {
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

    protected static function load_pack_info($pack_path, $is_new = false) {
        if ($is_new) {
            $curse_path = $pack_path . '/curse_new.json';
        }
        else {
            $curse_path = $pack_path . '/curse.json';
        }

        $json = file_get_contents($curse_path);
        $curse = json_decode($json, true);

        if (empty($curse)) {
            throw new Exception('curse.json格式错误');
        }

        if ($is_new) {
            $file_path = $pack_path . '/manifest_new.json';
        }
        else {
            $file_path = $pack_path . '/manifest.json';
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
            'curse' => $curse,
        ];
    }

    ///////////////////////////////////////////////////////////////////

    public static function show_size($size) {
        return " \033[32m[" . HttpClient::show_size($size) . "\033[0m]";
    }

}
