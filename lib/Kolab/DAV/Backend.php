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

use \rcube;

class Backend
{
    protected static $instance;
    protected $api;
    protected $conf;
    protected $app_name = 'Kolab File API';
    protected $config = array(
        'date_format' => 'Y-m-d H:i',
        'language'    => 'en_US',
    );


    /**
     * This implements the 'singleton' design pattern
     *
     * @return Backend The one and only instance
     */
    static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new Backend();
            self::$instance->init();
        }

        return self::$instance;
    }

    /**
     * Private constructor
     */
    protected function __construct()
    {
        $rcube = rcube::get_instance();
        $this->conf = $rcube->config;
    }

    /**
     * Returns file API backend
     */
    public function get_backend()
    {
        return $this->api;
    }

    /**
     * Initialise backend class
     */
    protected function init()
    {
        $driver = $this->conf->get('fileapi_backend', 'kolab');
        $class  = $driver . '_file_storage';

        $this->api = new $class;
        $this->api->configure($this->config);
    }

    /*
     * Returns API capabilities
     */
    protected function capabilities()
    {
        foreach ($this->api->capabilities() as $name => $value) {
            // skip disabled capabilities
            if ($value !== false) {
                $caps[$name] = $value;
            }
        }

        return $caps;
    }
}
