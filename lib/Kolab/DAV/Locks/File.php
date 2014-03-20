<?php

/**
 * File-based Lock manager for the Kolab WebDAV service.
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

namespace Kolab\DAV\Locks;

use Kolab\DAV\Auth\HTTPBasic;
use Sabre\DAV\Locks\LockInfo;
use Sabre\DAV\Locks\Backend\AbstractBackend;

/**
 * The Lock manager that maintains a lock file per user in the local file system.
 */
class File extends AbstractBackend
{
    /**
     * The storage directory prefix
     *
     * @var string
     */
    private $basePath;

    /**
     * The directory to storage the file in
     *
     * @var string
     */
    private $dataDir;

    /**
     * Constructor
     *
     * @param string $basePath base path to store lock files
     */
    public function __construct($basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * Returns a list of Sabre\DAV\Locks\LockInfo objects
     *
     * This method should return all the locks for a particular uri, including
     * locks that might be set on a parent uri.
     *
     * If returnChildLocks is set to true, this method should also look for
     * any locks in the subtree of the uri for locks.
     *
     * @param string $uri
     * @param bool $returnChildLocks
     * @return array
     */
    public function getLocks($uri, $returnChildLocks)
    {
        console(__METHOD__, $uri, $returnChildLocks);

        $newLocks = array();
        $locks = $this->getData();

        foreach ($locks as $lock) {
            if ($lock->uri === $uri ||
                // deep locks on parents
                ($lock->depth != 0 && strpos($uri, $lock->uri . '/') === 0) ||
                // locks on children
                ($returnChildLocks && (strpos($lock->uri, $uri . '/') === 0)) ) {
                $newLocks[] = $lock;
            }
        }

        // Checking if we can remove any of these locks
        foreach ($newLocks as $k => $lock) {
            if (time() > $lock->timeout + $lock->created)
                unset($newLocks[$k]);
        }

        return $newLocks;
    }

    /**
     * Locks a uri
     *
     * @param string $uri
     * @param LockInfo $lockInfo
     * @return bool
     */
    public function lock($uri, LockInfo $lockInfo)
    {
        console(__METHOD__, $uri, $lockInfo);

        // We're making the lock timeout 30 minutes
        $lockInfo->timeout = 1800;
        $lockInfo->created = time();
        $lockInfo->uri = $uri;
        $lockInfo->owner = trim($lockInfo->owner);

        $locks = $this->getData();
        $now = time();

        foreach ($locks as $k => $lock) {
            if (($lock->token == $lockInfo->token) || ($now > $lock->timeout + $lock->created)) {
                unset($locks[$k]);
            }
        }
        $locks[] = $lockInfo;
        $this->putData($locks);

        return true;
    }

    /**
     * Removes a lock from a uri
     *
     * @param string $uri
     * @param LockInfo $lockInfo
     * @return bool
     */
    public function unlock($uri, LockInfo $lockInfo)
    {
        console(__METHOD__, $uri, $lockInfo);

        $locks = $this->getData();
        foreach ($locks as $k => $lock) {
            if ($lock->token == $lockInfo->token) {
                unset($locks[$k]);
                $this->putData($locks);
                return true;
            }
        }

        return false;
    }

    /**
     * Loads the lockdata from the filesystem.
     *
     * @return array
     */
    protected function getData()
    {
        $locksFile = $this->getLocksFile();
        if (!file_exists($locksFile))
            return array();

        // opening up the file, and creating a shared lock
        $handle = fopen($locksFile, 'r');
        flock($handle, LOCK_SH);

        // Reading data until the eof
        $data = stream_get_contents($handle);

        // We're all good
        fclose($handle);

        // Unserializing and checking if the resource file contains data for this file
        $data = unserialize($data);
        return $data ?: array();

    }

    /**
     * Compose a full path to the lock file of the current user
     */
    protected function getLocksFile()
    {
        if (!$this->dataDir) {
            $this->dataDir = $this->basePath . '/' . str_replace('@', '_', HTTPBasic::$current_user);

            if (!is_dir($this->dataDir))
                mkdir($this->dataDir);
        }

        return $this->dataDir . '/locks';
    }

    /**
     * Saves the lockdata
     *
     * @param array $newData
     * @return void
     */
    protected function putData(array $newData)
    {
        // opening up the file, and creating an exclusive lock
        $handle = fopen($this->getLocksFile(), 'a+');
        flock($handle, LOCK_EX);

        // We can only truncate and rewind once the lock is acquired.
        ftruncate($handle, 0);
        rewind($handle);

        fwrite($handle, serialize($newData));
        fclose($handle);
    }

}

