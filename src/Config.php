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

class Config
{
    public function __construct(string $configFile)
    {
        $this->configFile = strval($configFile);

        if (file_exists($this->configFile)) {
            $this->load();
        }

        $this->save();
    }

    public function load() : void
    {
        $json = file_get_contents($this->configFile);
        $data = json_decode($json, true);

        $this->mergeConfig($data);
    }

    public function save() : void
    {
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $file = new \Minifw\Common\File($this->configFile);
        $file->putContent($json);
    }

    public function get(string $name)
    {
        if (isset($this->data[$name])) {
            return $this->data[$name];
        }

        return null;
    }

    public function set(string $name, $value) : void
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

    protected function mergeConfig(array $newData)
    {
        if (!empty($newData['debug']) && $newData['debug']) {
            $this->data['debug'] = true;
        }
    }
    protected string $configFile;
    protected array $data = [
        'debug' => false
    ];
}
