<?php

/**
 * SabreDAV File Backend implementation for Kolab.
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
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

use \Exception;

/**
 * Collection class
 */
class Collection extends \Kolab\DAV\Node implements \Sabre\DAV\ICollection
{
    const ROOT_DIRECTORY = 'files';

    public $children;


    function getChildren()
    {
        // @TODO: maybe children array is too big to keep it in memory?
        if (is_array($this->children)) {
            return $this->children;
        }

        $path_len       = strlen($this->path);
        $this->children = array();

        try {
            // @TODO: This should be cached too (out of this class)
            $folders = $this->backend->folder_list();
        }
        catch (Exception $e) {
        }


        // get subfolders
        foreach ($folders as $folder) {
            // need root-folders or subfolders of specified folder
            if (!$path_len || ($pos = strpos($folder, $this->path . '/')) === 0) {
                $virtual = false;

                // remove path suffix, the list might contain folders (roots) that
                // do not exist e.g.:
                //     Files
                //     Files/Sub
                //     Other Users/machniak/Files
                //     Other Users/machniak/Files/Sub
                // the list is sorted so we can do this in such a way
                if ($pos = strpos($folder, '/', $path_len + 1)) {
                    $folder  = substr($folder, 0, $pos);
                    $virtual = true;
                }

                if (!array_key_exists($folder, $this->children)) {
                    $path = Collection::ROOT_DIRECTORY . '/' . $folder;
                    if ($path_len) {
                        $folder = substr($folder, $path_len + 1);
                    }

                    $this->children[$folder] = new Collection($path, $this, array('virtual' => $virtual));
                }
            }
        }

        // non-root existing folder, get files list
        if ($path_len && empty($this->data['virtual'])) {
            try {
                $files = $this->backend->file_list($this->path);
            }
            catch (Exception $e) {
            }

            foreach ($files as $filename => $file) {
                $path = Collection::ROOT_DIRECTORY . '/' . $filename;
                // remove path prefix
                $filename = substr($filename, $path_len + 1);

                $this->children[$filename] = new File($path, $this, $file);
            }
        }

        return $this->children;
    }

    /**
     * Returns a child object, by its name.
     *
     * This method makes use of the getChildren method to grab all the child
     * nodes, and compares the name.
     * Generally its wise to override this, as this can usually be optimized
     *
     * This method must throw Sabre\DAV\Exception\NotFound if the node does not
     * exist.
     *
     * @param string $name
     * @throws Sabre\DAV\Exception\NotFound
     * @return INode
     */
    public function getChild($name)
    {
        // no support for hidden system files
        if ($name[0] == '.') {
            throw new \Sabre\DAV\Exception\NotFound('File not found: ' . $name);
        }

        $children = $this->getChildren();

        if (array_key_exists($name, $children)) {
            return $children[$name];
        }

        throw new \Sabre\DAV\Exception\NotFound('File not found: ' . $name);
    }

    /**
     * Checks if a child-node exists.
     *
     * It is generally a good idea to try and override this. Usually it can be optimized.
     *
     * @param string $name
     * @return bool
     */
    public function childExists($name)
    {
        try {
            $this->getChild($name);
            return true;
        }
        catch (\Sabre\DAV\Exception\NotFound $e) {
            return false;
        }
    }

    /**
     * Creates a new file in the directory
     *
     * Data will either be supplied as a stream resource, or in certain cases
     * as a string. Keep in mind that you may have to support either.
     *
     * After succesful creation of the file, you may choose to return the ETag
     * of the new file here.
     *
     * The returned ETag must be surrounded by double-quotes (The quotes should
     * be part of the actual string).
     *
     * If you cannot accurately determine the ETag, you should not return it.
     * If you don't store the file exactly as-is (you're transforming it
     * somehow) you should also not return an ETag.
     *
     * This means that if a subsequent GET to this new file does not exactly
     * return the same contents of what was submitted here, you are strongly
     * recommended to omit the ETag.
     *
     * @param string $name Name of the file
     * @param resource|string $data Initial payload
     * @return null|string
     */
    public function createFile($name, $data = null)
    {
        // no support for hidden system files
        if ($name[0] == '.') {
            throw new \Sabre\DAV\Exception\Forbidden('Hidden files are not accepted');
        }

        $filename = $this->path . '/' . $name;
        $filedata = $this->fileData($name, $data);

        try {
            $this->backend->file_create($filename, $filedata);
        }
        catch (Exception $e) {
//            throw new \Sabre\DAV\Exception\Forbidden($e->getMessage());
        }

        // reset cache
        $this->children = null;
    }

    /**
     * Creates a new subdirectory
     *
     * @param string $name
     * @throws Exception\Forbidden
     * @return void
     */
    public function createDirectory($name)
    {
        // no support for hidden system files
        if ($name[0] == '.') {
            throw new \Sabre\DAV\Exception\Forbidden('Hidden files are not accepted');
        }

        $folder = $this->path . '/' . $name;

        try {
            $this->backend->folder_create($folder);
        }
        catch (Exception $e) {
            throw new \Sabre\DAV\Exception\Forbidden($e->getMessage());
        }

        // reset cache
        $this->children = null;
    }

}
