<?php

/**
 * Utility class logging DAV requests
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

use Sabre\DAV;


/**
 * Utility class to log debug information about processed DAV requests
 */
class DAVLogger extends DAV\ServerPlugin
{
    private $server;
    private $method;

    /**
     * This initializes the plugin.
     * This method should set up the required event subscriptions.
     *
     * @param Server $server
     */
   public function initialize(DAV\Server $server)
   {
       $this->server = $server;

       $server->subscribeEvent('beforeMethod', array($this, '_beforeMethod'));
       $server->subscribeEvent('exception', array($this, '_exception'));
       $server->subscribeEvent('exit', array($this, '_exit'));
   }

   /**
    * Handler for 'beforeMethod' events
    */
   public function _beforeMethod($method, $uri)
   {
       $this->method = $method;

       // log to console
       console($method . ' ' . $uri);
   }

   /**
    * Handler for 'exception' events
    */
   public function _exception($e)
   {
       // log to console
       console(get_class($e) . ' (EXCEPTION)', $e->getMessage() /*, $e->getTraceAsString()*/);
   }

   /**
    * Handler for 'exit' events
    */
   public function _exit()
   {
       $time = microtime(true) - KOLAB_DAV_START;

       if (function_exists('memory_get_usage'))
         $mem = round(memory_get_usage() / 1024) . 'K';
       if (function_exists('memory_get_peak_usage'))
         $mem .= '/' . round(memory_get_peak_usage() / 1024) . 'K';

       console(sprintf("/%s: %0.4f sec; %s", $this->method, $time, $mem));
   }
} 