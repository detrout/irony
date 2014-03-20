<?php

/**
 * Utility class providing a simple API to PHP's APC cache
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

namespace Kolab\Utils;

use \rcube;
use \kolab_storage;
use \rcube_utils;
use \rcube_charset;

/**
 *
 */
class DAVBackend
{
    const IMAP_UID_KEY = '/shared/vendor/kolab/uniqueid';
    const IMAP_UID_KEY_PRIVATE = '/private/vendor/kolab/uniqueid';
    const IMAP_UID_KEY_CYRUS = '/shared/vendor/cmu/cyrus-imapd/uniqueid';

    /**
     * Getter for a kolab_storage_folder with the given UID
     *
     * @param string  Folder UID (saved in annotation)
     * @param string  Kolab folder type (for selecting candidates)
     * @return object \kolab_storage_folder instance
     */
    public static function get_storage_folder($uid, $type)
    {
        foreach (kolab_storage::get_folders($type) as $folder) {
            if (self::get_uid($folder) == $uid)
                return $folder;
        }

        return null;
    }

    /**
     * Helper method to extract folder UID metadata
     *
     * @param object \kolab_storage_folder Folder to get UID for
     * @return string Folder's UID
     */
    public static function get_uid($folder)
    {
        // UID is defined in folder METADATA
        $metakeys = array(self::IMAP_UID_KEY, self::IMAP_UID_KEY_PRIVATE, self::IMAP_UID_KEY_CYRUS);
        $metadata = $folder->get_metadata($metakeys);
        foreach ($metakeys as $key) {
            if (($uid = $metadata[$key])) {
                return $uid;
            }
        }

        // generate a folder UID and set it to IMAP
        $uid = rtrim(chunk_split(md5($folder->name . $folder->get_owner()), 12, '-'), '-');
        self::set_uid($folder, $uid);

        return $uid;
    }

    /**
     * Helper method to set an UID value to the given IMAP folder instance
     *
     * @param object \kolab_storage_folder Folder to set UID
     * @param string Folder's UID
     * @return boolean True on succes, False on failure
     */
    public static function set_uid($folder, $uid)
    {
        if (!($success = $folder->set_metadata(array(self::IMAP_UID_KEY => $uid)))) {
            $success = $folder->set_metadata(array(self::IMAP_UID_KEY_PRIVATE => $uid));
        }
        return $success;
    }

    /**
     * Build an absolute URL with the given parameters
     */
    public static function abs_url($parts = array())
    {
        $schema = 'http';
        $default_port = 80;
        if (rcube_utils::https_check()) {
            $schema = 'https';
            $default_port = 443;
        }
        $url = $schema . '://' . $_SERVER['HTTP_HOST'];

        if ($_SERVER['SERVER_PORT'] != $default_port)
            $url .= ':' . $_SERVER['SERVER_PORT'];

        if (dirname($_SERVER['SCRIPT_NAME']) != '/')
            $url .= dirname($_SERVER['SCRIPT_NAME']);

        $url .= '/' . join('/', array_map('urlencode', $parts));

        return $url;
    }

    /**
     * Updates properties for a recourse (kolab folder)
     *
     * The mutations array uses the propertyName in clark-notation as key,
     * and the array value for the property value. In the case a property
     * should be deleted, the property value will be null.
     *
     * This method must be atomic. If one property cannot be changed, the
     * entire operation must fail.
     *
     * If the operation was successful, true is returned.
     * If the operation failed, detailed information about any
     * failures is returned.
     *
     * @param object $folder kolab_storage_folder instance to operate on
     * @param array $mutations Hash array with propeties to change
     * @return bool|array
     */
    public static function folder_update($folder, array $mutations)
    {
        $errors = array();
        $updates = array();

        foreach ($mutations as $prop => $val) {
            switch ($prop) {
                case '{DAV:}displayname':
                    // restrict renaming to personal folders only
                    if ($folder->get_namespace() == 'personal') {
                        $parts = preg_split('!(\s*/\s*|\s+[»:]\s+)!', $val);
                        $updates['oldname'] = $folder->name;
                        $updates['name'] = array_pop($parts);
                        $updates['parent'] = join('/', $parts);
                    }
                    else {
                        $updates['displayname'] = $val;
                    }
                    break;

                case '{http://apple.com/ns/ical/}calendar-color':
                    $updates['color'] = substr(trim($val, '#'), 0, 6);
                    break;

                case '{urn:ietf:params:xml:ns:caldav}calendar-description':
                default:
                    // unsupported property
                    $errors[403][$prop] = null;
            }
        }

        // execute folder update
        if (!empty($updates)) {
            // 'name' and 'parent' properties are always required
            if (empty($updates['name'])) {
                $parts = explode('/', $folder->name);
                $updates['name'] = rcube_charset::convert(array_pop($parts), 'UTF7-IMAP');
                $updates['parent'] = join('/', $parts);
                $updates['oldname'] = $folder->name;
            }

            if (!kolab_storage::folder_update($updates)) {
                rcube::raise_error(array(
                    'code' => 600, 'type' => 'php',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Error updating properties for folder $folder->name:" . kolab_storage::$last_error),
                    true, false);
                return false;
            }
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * Creates a new resource (i.e. IMAP folder) of a given type
     *
     * If the creation was a success, an id must be returned that can be used to reference
     * this resource in other methods.
     *
     * @param array $properties
     * @param string $type
     * @param string $uid
     * @return false|string
     */
    public function folder_create($type, array $properties, $uid)
    {
        $props = array(
            'type' => $type,
            'name' => '',
            'subscribed' => true,
        );

        foreach ($properties as $prop => $val) {
            switch ($prop) {
                case '{DAV:}displayname':
                    $parts = explode('/', $val);
                    $props['name'] = array_pop($parts);
                    $props['parent'] = join('/', $parts);
                    break;

                case '{http://apple.com/ns/ical/}calendar-color':
                    $props['color'] = substr(trim($val, '#'), 0, 6);
                    break;

                case '{urn:ietf:params:xml:ns:caldav}calendar-description':
                default:
                    // unsupported property
            }
        }

        // use UID as name if it doesn't seem to be a real UID
        // TODO: append number to default "Untitled" folder name if one already exists
        if (empty($props['name'])) {
            $props['name'] = strlen($uid) < 16 ? $uid : 'Untitled';
        }

        if (!($fname = kolab_storage::folder_update($props))) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error creating a new $type folder '$props[name]':" . kolab_storage::$last_error),
                true, false);
            return false;
        }

        // save UID in folder annotations
        if ($folder = kolab_storage::get_folder($fname)) {
            self::set_uid($folder, $uid);
        }

        return $uid;
    }
}
