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

use Org\Snje\Cursedown\App;

interface Api
{
    public function __construct(App $app);

    public function search(string $name) : array;

    public function info(int $id) : array;

    public function updatePackInfo(int $id, string $packPath, array $packInfo) : array;

    public function getFiles(string $packPath, array $packInfo) : array;
}
