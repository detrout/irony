<?php

/**
 * Extended CalDAV plugin for the Kolab DAV server
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

namespace Kolab\CalDAV;

use Sabre\DAV;
use Sabre\CalDAV;
use Sabre\VObject;
use Kolab\DAV\Auth\HTTPBasic;


/**
 * Extended CalDAV plugin to tweak data validation
 */
class Plugin extends CalDAV\Plugin
{
    // make already parsed text/calednar blocks available for later use
    public static $parsed_vcalendar;
    public static $parsed_vevent;

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

        $server->subscribeEvent('afterCreateFile', array($this, 'afterWriteContent'));
        $server->subscribeEvent('afterWriteContent', array($this, 'afterWriteContent'));
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
     * @param string $path
     * @return void
     */
    protected function validateICalendar(&$data, $path)
    {
        // If it's a stream, we convert it to a string first.
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        // Converting the data to unicode, if needed.
        $data = DAV\StringUtil::ensureUTF8($data);

        try {
            // modification: Set options to be more tolerant when parsing extended or invalid properties
            $vobj = VObject\Reader::read($data, VObject\Reader::OPTION_FORGIVING | VObject\Reader::OPTION_IGNORE_INVALID_LINES);

            // keep the parsed object in memory for later processing
            if ($vobj->name == 'VCALENDAR') {
                self::$parsed_vcalendar = $vobj;
                foreach ($vobj->getBaseComponents() as $vevent) {
                    if ($vevent->name == 'VEVENT' || $vevent->name == 'VTODO') {
                        self::$parsed_vevent = $vevent;
                        break;
                    }
                }
            }
        }
        catch (VObject\ParseException $e) {
            throw new DAV\Exception\UnsupportedMediaType('This resource requires valid iCalendar 2.0 data. Parse error: ' . $e->getMessage());
        }

        if ($vobj->name !== 'VCALENDAR') {
            throw new DAV\Exception\UnsupportedMediaType('This collection can only support iCalendar objects.');
        }

        // Get the Supported Components for the target calendar
        list($parentPath,$object) = DAV\URLUtil::splitPath($path);
        $calendarProperties = $this->server->getProperties($parentPath,array('{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'));
        $supportedComponents = $calendarProperties['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set']->getValue();

        $foundType = null;
        $foundUID = null;
        foreach($vobj->getComponents() as $component) {
            switch($component->name) {
                case 'VTIMEZONE':
                    continue 2;

                case 'VEVENT':
                case 'VTODO':
                case 'VJOURNAL':
                    if (is_null($foundType)) {
                        $foundType = $component->name;
                        if (!in_array($foundType, $supportedComponents)) {
                            throw new CalDAV\Exception\InvalidComponentType('This calendar only supports ' . implode(', ', $supportedComponents) . '. We found a ' . $foundType);
                        }
                        if (!isset($component->UID)) {
                            throw new DAV\Exception\BadRequest('Every ' . $component->name . ' component must have an UID');
                        }
                        $foundUID = (string)$component->UID;
                    } else {
                        if ($foundType !== $component->name) {
                            throw new DAV\Exception\BadRequest('A calendar object must only contain 1 component. We found a ' . $component->name . ' as well as a ' . $foundType);
                        }
                        if ($foundUID !== (string)$component->UID) {
                            throw new DAV\Exception\BadRequest('Every ' . $component->name . ' in this object must have identical UIDs');
                        }
                    }
                    break;

                default:
                    throw new DAV\Exception\BadRequest('You are not allowed to create components of type: ' . $component->name . ' here');

            }
        }
        if (!$foundType)
            throw new DAV\Exception\BadRequest('iCalendar object must contain at least 1 of VEVENT, VTODO or VJOURNAL');
    }

    /**
     * Returns free-busy information for a specific address. The returned
     * data is an array containing the following properties:
     *
     * calendar-data : A VFREEBUSY VObject
     * request-status : an iTip status code.
     * href: The principal's email address, as requested
     *
     * @param string $email address
     * @param \DateTime $start
     * @param \DateTime $end
     * @param VObject\Component $request
     * @return array
     */
    protected function getFreeBusyForEmail($email, \DateTime $start, \DateTime $end, VObject\Component $request)
    {
        $email = preg_replace('/^mailto:/', '', $email);

        // pass-through the pre-generatd free/busy feed from Kolab's free/busy service
        if ($fburl = \kolab_storage::get_freebusy_url($email)) {
            // use PEAR::HTTP_Request2 for data fetching
            // @include_once('HTTP/Request2.php');

            try {
                $rcube = \rcube::get_instance();
                $request = new \HTTP_Request2($fburl);
                $request->setConfig(array(
                    'store_body'       => true,
                    'follow_redirects' => true,
                    'ssl_verify_peer'  => $rcube->config->get('kolab_ssl_verify_peer', true),
                ));

                $response = $request->send();

                // authentication required
                if ($response->getStatus() == 401) {
                    $request->setAuth(HTTPBasic::$current_user, HTTPBasic::$current_pass);
                    $response = $request->send();
                }

                // success!
                if ($response->getStatus() == 200) {
                    return array(
                        'calendar-data' => $response->getBody(),
                        'request-status' => '2.0;Success',
                        'href' => 'mailto:' . $email,
                    );
                }
            }
            catch (\Exception $e) {
                // ignore failures
            }
        }
        else {
            // generate free/busy data from this user's calendars
            return parent::getFreeBusyForEmail($email, $start, $end, $request);
        }

        // return "not found"
        return array(
            'request-status' => '3.7;Could not find principal',
            'href' => 'mailto:' . $email,
        );
    }
}