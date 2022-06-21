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

class Config
{
    /**
     * @param $config_file
     */
    public function __construct($config_file)
    {
        $this->config_file = strval($config_file);

        if (file_exists($this->config_file)) {
            $this->load();
        }

        $this->save();
    }

    public function load()
    {
        $json = file_get_contents($this->config_file);
        $data = json_decode($json, true);

        $this->mergeConfig($data);
    }

    public function save()
    {
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $file = new \Minifw\Common\File($this->config_file);
        $file->putContent($json);
    }

    /**
     * @param $name
     * @return mixed
     */
    public function get($name)
    {
        if (isset($this->data[$name])) {
            return $this->data[$name];
        }

        return null;
    }

    /**
     * @param $name
     * @param $value
     */
    public function set($name, $value)
    {
        if ($value === null) {
            if (isset($this->data[$name])) {
                unset($this->data[$name]);
            }
        } else {
            $this->data[$name] = $value;
        }
    }

    ///////////////////////////////////////

    /**
     * @param $new_data
     */
    protected function mergeConfig($new_data)
    {
        if (!empty($new_data['debug']) && $new_data['debug']) {
            $this->data['debug'] = true;
        }
    }

    /**
     * @var mixed
     */
    protected $config_file;
    /**
     * @var array
     */
    protected $data = [
        'debug' => false
    ];
}
