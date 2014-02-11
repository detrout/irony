<?php

/**
 * Temporary File Filter Plugin for the Kolab WebDAV service.
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2013, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Kolab\DAV;

use Sabre\DAV;
use Kolab\DAV\Auth\HTTPBasic;

/**
 * Temporary File Filter Plugin
 *
 * The purpose of this filter is to intercept some of the garbage files
 * operation systems and applications tend to generate when mounting
 * a WebDAV share as a disk.
 */
class TempFilesPlugin extends DAV\TemporaryFileFilterPlugin
{
    protected $baseDir;

    /**
     * Creates the plugin.
     *
     * @param string $baseDir Temp directoy base path
     */
    public function __construct($baseDir)
    {
        $this->baseDir = $baseDir;
    }

    /**
     * This method returns the directory where the temporary files should be stored.
     *
     * @return string
     */
    protected function getDataDir()
    {
        if (!$this->dataDir) {
            $this->dataDir = $this->baseDir . '/' . str_replace('@', '_', HTTPBasic::$current_user);

            if (!is_dir($this->dataDir)) {
                mkdir($this->dataDir);
            }

            // run a cleanup routine on every 100th request
            if (rand(0,100) == 100) {
                $this->cleanup();
            }
        }

        return $this->dataDir;
    }

    /**
     * Tempfile cleanup routine to remove files not touched for 24 hours
     */
    protected function cleanup()
    {
        $expires = time() - 86400;
        foreach (glob($this->dataDir . '/*.tempfile') as $file) {
            if (filemtime($file) < $expires) {
                unlink($file);
            }
        }
    }
}
