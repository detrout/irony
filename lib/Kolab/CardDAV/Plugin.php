<?php

/**
 * Extended CardDAV plugin for the Kolab DAV server
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

namespace Kolab\CardDAV;

use Sabre\DAV;
use Sabre\CardDAV;
use Sabre\VObject;


/**
 * Extended CardDAV plugin to tweak data validation
 */
class Plugin extends CardDAV\Plugin
{
    // make already parsed vcard blocks available for later use
    public static $parsed_vcard;

    // allow the backend to force a redirect Location
    public static $redirect_basename;

    /**
     * Initializes the plugin
     *
     * @param DAV\Server $server
     * @return void
     */
    public function initialize(DAV\Server $server)
    {
        parent::initialize($server);

        $server->subscribeEvent('beforeMethod', array($this, 'beforeMethod'));
        $server->subscribeEvent('afterCreateFile', array($this, 'afterWriteContent'));
        $server->subscribeEvent('afterWriteContent', array($this, 'afterWriteContent'));
    }

    /**
     * Handler for beforeMethod events
     */
    public function beforeMethod($method, $uri)
    {
        if ($method == 'PUT' && $this->server->httpRequest->getHeader('If-None-Match') == '*') {
            // In-None-Match: * is only valid with PUT requests creating a new resource.
            // SOGo Conenctor for Thunderbird also sends it with update requests which then fail
            // in the Server::checkPreconditions().
            // See https://issues.kolab.org/show_bug.cgi?id=2589 and http://www.sogo.nu/bugs/view.php?id=1624
            // This is a work-around for the buggy SOGo connector and should be removed once fixed.
            if (strpos($this->server->httpRequest->getHeader('User-Agent'), 'Thunderbird/') > 0) {
                unset($_SERVER['HTTP_IF_NONE_MATCH']);
            }
        }
    }

    /**
     * Inject some additional HTTP response headers
     */
    public function afterWriteContent($uri, $node)
    {
        // send Location: header to corrected URI
        if (self::$redirect_basename) {
            $path = explode('/', $uri);
            array_pop($path);
            array_push($path, self::$redirect_basename);
            $this->server->httpResponse->setHeader('Location', $this->server->getBaseUri() . join('/', array_map('urlencode', $path)));
            self::$redirect_basename = null;
        }
    }

    /**
     * Checks if the submitted iCalendar data is in fact, valid.
     *
     * An exception is thrown if it's not.
     *
     * @param resource|string $data
     * @return void
     */
    protected function validateVCard(&$data)
    {
        // If it's a stream, we convert it to a string first.
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        // Converting the data to unicode, if needed.
        $data = DAV\StringUtil::ensureUTF8($data);

        try {
            VObject\Property::$classMap['REV'] = 'Sabre\\VObject\\Property\\DateTime';
            $vobj = VObject\Reader::read($data, VObject\Reader::OPTION_FORGIVING | VObject\Reader::OPTION_IGNORE_INVALID_LINES);

            if ($vobj->name == 'VCARD')
                $this->parsed_vcard = $vobj;
        }
        catch (VObject\ParseException $e) {
            throw new DAV\Exception\UnsupportedMediaType('This resource only supports valid vcard data. Parse error: ' . $e->getMessage());
        }

        if ($vobj->name !== 'VCARD') {
            throw new DAV\Exception\UnsupportedMediaType('This collection can only support vcard objects.');
        }

        if (!isset($vobj->UID)) {
            throw new DAV\Exception\BadRequest('Every vcard must have a UID.');
        }
    }

}