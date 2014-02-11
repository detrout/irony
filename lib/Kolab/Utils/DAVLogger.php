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

use \rcube;
use Sabre\DAV;
use Kolab\DAV\Auth\HTTPBasic;


/**
 * Utility class to log debug information about processed DAV requests
 */
class DAVLogger extends DAV\ServerPlugin
{
    const CONSOLE = 1;
    const HTTP_REQUEST = 2;
    const HTTP_RESPONSE = 4;

    private $rcube;
    private $server;
    private $method;
    private $loglevel;


    /**
     * Default constructor
     */
    public function __construct($level = 1)
    {
        $this->rcube = rcube::get_instance();
        $this->loglevel = $level;
    }

    /**
     * This initializes the plugin.
     * This method should set up the required event subscriptions.
     *
     * @param Server $server
     */
    public function initialize(DAV\Server $server)
    {
        $this->server = $server;

        $server->subscribeEvent('beforeMethod', array($this, '_beforeMethod'), 15);
        $server->subscribeEvent('exception', array($this, '_exception'));
        $server->subscribeEvent('exit', array($this, '_exit'));

        // replace $server->httpResponse with a derived class that can do logging
        $server->httpResponse = new HTTPResponse();
   }

    /**
     * Handler for 'beforeMethod' events
     */
    public function _beforeMethod($method, $uri)
    {
        $this->method = $method;

        // turn on per-user http logging if the destination file exists
        if ($this->loglevel < 2 && $this->rcube->config->get('kolabdav_user_debug', false)
            && ($log_dir = $this->user_log_dir()) && file_exists($log_dir . '/httpraw')) {
            $this->loglevel |= (self::HTTP_REQUEST | self::HTTP_RESPONSE);
        }

        // log full HTTP request data
        if ($this->loglevel & self::HTTP_REQUEST) {
            $request = $this->server->httpRequest;
            $content_type = $request->getHeader('CONTENT_TYPE');
            if (strpos($content_type, 'text/') === 0) {
                $http_body = $request->getBody(true);

                // Hack for reading php:://input because that stream can only be read once.
                // This is why we re-populate the request body with the existing data.
                $request->setBody($http_body);
            }
            else if (!empty($content_type)) {
                $http_body = '[binary data]';
            }

            // catch all headers
            $http_headers = array();
            foreach (apache_request_headers() as $hdr => $value) {
                $http_headers[$hdr] = "$hdr: $value";
            }

            $this->write_log('httpraw', $request->getMethod() . ' ' . $request->getUri() . ' ' . $_SERVER['SERVER_PROTOCOL'] . "\n" .
               join("\n", $http_headers) . "\n\n" . $http_body);
        }

        // log to console
        if ($this->loglevel & self::CONSOLE) {
           $this->write_log('console', $method . ' ' . $uri);
        }
    }

    /**
     * Handler for 'exception' events
     */
    public function _exception($e)
    {
        // log to console
        $this->console(get_class($e) . ' (EXCEPTION)', $e->getMessage() /*, $e->getTraceAsString()*/);
    }

    /**
     * Handler for 'exit' events
     */
    public function _exit()
    {
        if ($this->loglevel & self::CONSOLE) {
            $time = microtime(true) - KOLAB_DAV_START;

            if (function_exists('memory_get_usage'))
               $mem = round(memory_get_usage() / 1024) . 'K';
            if (function_exists('memory_get_peak_usage'))
               $mem .= '/' . round(memory_get_peak_usage() / 1024) . 'K';

            $this->write_log('console', sprintf("/%s: %0.4f sec; %s", $this->method, $time, $mem));
        }

        // log full HTTP reponse
        if ($this->loglevel & self::HTTP_RESPONSE) {
            $this->write_log('httpraw', "RESPONSE: " . $this->server->httpResponse->dump());
        }
    }

    /**
     * Wrapper for rcube::cosole() to write per-user logs
     */
    public function console(/* ... */)
    {
        if ($this->loglevel & self::CONSOLE) {
            $msg = array();
            foreach (func_get_args() as $arg) {
                $msg[] = !is_string($arg) ? var_export($arg, true) : $arg;
            }

            $this->write_log('console', join(";\n", $msg));
        }
    }

    /**
     * Wrapper for rcube::write_log() that can write per-user logs
     */
    public function write_log($filename, $msg)
    {
        // dump data per user
        if ($this->rcube->config->get('kolabdav_user_debug', false)) {
            if ($this->user_log_dir()) {
               $filename = HTTPBasic::$current_user . '/' . $filename;
            }
            else {
               return;  // don't log
            }
        }

        rcube::write_log($filename, $msg);
    }

    /**
     * Get the per-user log directory
     */
    private function user_log_dir()
    {
        $log_dir = $this->rcube->config->get('log_dir', RCUBE_INSTALL_PATH . 'logs');
        $user_log_dir = $log_dir . '/' . HTTPBasic::$current_user;

        return HTTPBasic::$current_user && is_writable($user_log_dir) ? $user_log_dir : false;
    }
} 