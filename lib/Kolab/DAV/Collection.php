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

        $level = substr_count($this->path, '/');
        $this->children = array();

        try {
            // @TODO: This should be cached too (out of this class)
            $folders = $this->backend->folder_list();
        }
        catch (Exception $e) {
        }

        // get subfolders
        foreach ($folders as $folder) {
            $f_level = substr_count($folder, '/');

            if (($this->path === '' && $f_level == 0)
                || ($level == $f_level-1 && strpos($folder, $this->path . '/') === 0)
            ) {
                $this->children[] = new Collection(Collection::ROOT_DIRECTORY . '/' . $folder, $this);
            }
        }

        // non-root folder, get files list
        if ($this->path !== '') {
            try {
                $files = $this->backend->file_list($this->path);
            }
            catch (Exception $e) {
            }

            foreach ($files as $filename => $file) {
                $this->children[] = new File(Collection::ROOT_DIRECTORY . '/' . $filename, $this, $file);
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

        // @TODO: optimise this?
        foreach ($this->getChildren() as $child) {
            if ($child->getName() == $name) {
                return $child;
            }
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
