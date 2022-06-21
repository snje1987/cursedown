#!/usr/bin/env php
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

use FilesystemIterator;
use Phar;

class Builder
{
    /**
     * @var mixed
     */
    protected $root;

    const PHAR_NAME = 'cursedown.phar';

    /**
     * @param $root
     */
    public function __construct($root)
    {
        $this->root = $root;
    }

    public function build()
    {
        $dst = $this->root . '/build';

        if (!file_exists($dst)) {
            mkdir($dst, 0777, true);
        }

        $phar = new Phar($dst . '/' . self::PHAR_NAME, FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME, self::PHAR_NAME);

        $phar->startBuffering();

        $dirs = [
            'src',
            'res',
            'vendor'
        ];

        foreach ($dirs as $dir) {
            $this->addDir($phar, $dir);
        }

        $files = [
            'COPYING',
            'README'
        ];

        foreach ($files as $file) {
            $phar->addFile($this->root . '/' . $file);
        }

        $defaultStub = $phar->createDefaultStub('src/index.php');
        $stub = "#!/usr/bin/env php\n" . $defaultStub;
        $phar->setStub($stub);

        $phar->stopBuffering();

        chmod($dst . '/' . self::PHAR_NAME, 0755);
    }

    /**
     * @param \Phar $phar
     * @param string $dir
     */
    public function addDir($phar, $dir)
    {
        $phar->addEmptyDir($dir);

        $full = $this->root . '/' . $dir;
        $files = scandir($full);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $full . '/' . $file;
            $rel_path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->addDir($phar, $rel_path);
            } else {
                $phar->addFile($path, $rel_path);
            }
        }
    }
}

$root = dirname(__DIR__);

$builder = new Builder($root);
$builder->build();
