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
use Minifw\Common\File;
use Minifw\Console\Utils;

class Config
{
    public function __construct(string $path)
    {
        $this->path = strval($path);

        if (file_exists($this->path)) {
            $this->load();
        }

        $this->save();
    }

    public function load() : self
    {
        $json = file_get_contents($this->path);
        $data = json_decode($json, true);

        if ($data !== null) {
            $this->mergeConfig($data);
        }

        return $this;
    }

    public function save() : self
    {
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $file = new File($this->path);
        $file->putContent($json);

        return $this;
    }

    public function get(string $name)
    {
        if (isset($this->data[$name])) {
            return $this->data[$name];
        }

        return null;
    }

    public function show(string $name) : string
    {
        if (isset($this->data[$name])) {
            $value = $this->data[$name];

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return 'null';
    }

    public function set(string $name, $value) : self
    {
        if ($value === null) {
            if (isset($this->data[$name])) {
                unset($this->data[$name]);
            }
        } elseif (!isset(self::$dataType[$name])) {
            throw new Exception('配置项不存在: ' . $name);
        }

        switch (self::$dataType[$name]) {
            case self::TYPE_BOOL:
                if (!is_bool($value)) {
                    if (is_string($value)) {
                        if ($value == 'false' || $value == '0') {
                            $value = false;
                        } else {
                            $value = (bool) $value;
                        }
                    } elseif ($value === null) {
                        $value = false;
                    } else {
                        $value = (bool) $value;
                    }
                }
                break;
            case self::TYPE_STRING:
                if (!is_string($value)) {
                    throw new Exception('参数不合法');
                }
                break;
            case self::TYPE_INT:
                if (!preg_match('/^\\d+$/', $value)) {
                    throw new Exception('参数不合法');
                }
                $value = (int) $value;
                break;
            case self::TYPE_FILE:
                if (!is_string($value)) {
                    throw new Exception('参数不合法');
                }
                if (!file_exists($value)) {
                    throw new Exception('文件不存在');
                }
                $value = Utils::getFullPath($value);
                break;
            case self::TYPE_PATH:
                if (!is_string($value)) {
                    throw new Exception('参数不合法');
                }
                $value = Utils::getFullPath($value);
                break;
            case self::TYPE_API:
                if (!is_string($value)) {
                    throw new Exception('参数不合法');
                }
                if (!in_array($value, self::$apiEnum)) {
                    throw new Exception('api必须是[' . implode(',', self::$apiEnum) . ']其中之一');
                }
                break;
            default:
                throw new Exception('参数不合法');
        }

        $this->data[$name] = $value;

        return $this;
    }

    ///////////////////////////////////////

    protected function mergeConfig(array $newData) : void
    {
        foreach (self::$dataType as $name => $type) {
            if (array_key_exists($name, $newData)) {
                try {
                    $this->set($name, $newData[$name]);
                } catch (Exception $ex) {
                }
            }
        }
    }
    protected string $path;
    protected array $data = [
        'debug' => false,
        'api' => 'curseforge',
        'curseforge_api_key' => '',
    ];
    protected static array $dataType = [
        'debug' => self::TYPE_BOOL,
        'api' => self::TYPE_API,
        'curseforge_api_key' => self::TYPE_STRING,
    ];
    const TYPE_BOOL = 1;
    const TYPE_STRING = 2;
    const TYPE_INT = 3;
    const TYPE_FILE = 4;
    const TYPE_PATH = 5;
    const TYPE_API = 6;
    protected static array $apiEnum = App::API_LIST;
}
