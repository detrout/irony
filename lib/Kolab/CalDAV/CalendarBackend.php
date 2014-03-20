<?php

/**
 * SabreDAV Calendaring backend for Kolab.
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

use \PEAR;
use \rcube;
use \rcube_charset;
use \kolab_storage;
use \libcalendaring;
use Kolab\Utils\DAVBackend;
use Kolab\Utils\VObjectUtils;
use Kolab\DAV\Auth\HTTPBasic;
use Sabre\DAV;
use Sabre\CalDAV;
use Sabre\VObject;

/**
 * Kolab Calendaring backend.
 *
 * Checkout the Sabre\CalDAV\Backend\BackendInterface for all the methods that must be implemented.
 *
 */
class CalendarBackend extends CalDAV\Backend\AbstractBackend
{
    private $calendars;
    private $folders;
    private $aliases;
    private $useragent;
    private $type_component_map = array('event' => 'VEVENT', 'task' => 'VTODO');

    /**
     * Read available calendar folders from server
     */
    private function _read_calendars()
    {
        // already read sources
        if (isset($this->calendars))
            return $this->calendars;

        // get all folders that have "event" type
        $folders = array_merge(kolab_storage::get_folders('event'), kolab_storage::get_folders('task'));
        $this->calendars = $this->folders = $this->aliases = array();

        foreach (kolab_storage::sort_folders($folders) as $folder) {
            $id = DAVBackend::get_uid($folder);
            $this->folders[$id] = $folder;
            $fdata = $folder->get_imap_data();  // fetch IMAP folder data for CTag generation
            $this->calendars[$id] = array(
                'id' => $id,
                'uri' => $id,
                '{DAV:}displayname' => html_entity_decode($folder->get_name(), ENT_COMPAT, RCUBE_CHARSET),
                '{http://apple.com/ns/ical/}calendar-color' => $folder->get_color(),
                '{http://calendarserver.org/ns/}getctag' => sprintf('%d-%d-%d', $fdata['UIDVALIDITY'], $fdata['HIGHESTMODSEQ'], $fdata['UIDNEXT']),
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new CalDAV\Property\SupportedCalendarComponentSet(array($this->type_component_map[$folder->type])),
                '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new CalDAV\Property\ScheduleCalendarTransp('opaque'),
            );
            $this->aliases[$folder->name] = $id;

            // these properties are used for sharing supprt (not yet active)
            if (false && $folder->get_namespace() != 'personal') {
                $rights = $folder->get_myrights();
                $this->calendars[$id]['{http://calendarserver.org/ns/}shared-url'] = '/calendars/' . $folder->get_owner() . '/' . $id;
                $this->calendars[$id]['{http://calendarserver.org/ns/}owner-principal'] = $folder->get_owner();
                $this->calendars[$id]['{http://sabredav.org/ns}read-only'] = strpos($rights, 'i') === false;
            }
        }

        return $this->calendars;
    }

    /**
     * Getter for a kolab_storage_folder representing the calendar for the given ID
     *
     * @param string Calendar ID
     * @return object kolab_storage_folder instance
     */
    public function get_storage_folder($id)
    {
        // resolve alias name
        if ($this->aliases[$id]) {
            $id = $this->aliases[$id];
        }

        if ($this->folders[$id]) {
            return $this->folders[$id];
        }
        else {
            return DAVBackend::get_storage_folder($id, '');
        }
    }

    /**
     * Returns a list of calendars for a principal.
     *
     * Every calendars is an array with the following keys:
     *  * id, a unique id that will be used by other functions to modify the
     *    calendar. This can be the same as the uri or a database key.
     *  * uri, which the basename of the uri with which the calendar is
     *    accessed.
     *  * principaluri. The owner of the calendar. Almost always the same as
     *    principalUri passed to this method.
     *
     * Furthermore it can contain webdav properties in clark notation. A very
     * common one is '{DAV:}displayname'.
     *
     * @param string $principalUri
     * @return array
     */
    public function getCalendarsForUser($principalUri)
    {
        console(__METHOD__, $principalUri);

        $this->_read_calendars();

        $calendars = array();
        foreach ($this->calendars as $id => $cal) {
            $this->calendars[$id]['principaluri'] = $principalUri;
            $calendars[] = $this->calendars[$id];
        }

        return $calendars;
    }

    /**
     * Returns calendar properties for a specific node identified by name/uri
     *
     * @param string Node name/uri
     * @return array Hash array with calendar properties or null if not found
     */
    public function getCalendarByName($calendarUri)
    {
        console(__METHOD__, $calendarUri);

        $this->_read_calendars();
        $id = $calendarUri;

        // resolve aliases (calendar by folder name)
        if ($this->aliases[$calendarUri]) {
            $id = $this->aliases[$calendarUri];
        }

        if ($this->calendars[$id] && empty($this->calendars[$id]['principaluri'])) {
            $this->calendars[$id]['principaluri'] = 'principals/' . HTTPBasic::$current_user;
        }

        return $this->calendars[$id];
    }

    /**
     * Creates a new calendar for a principal.
     *
     * If the creation was a success, an id must be returned that can be used to reference
     * this calendar in other methods, such as updateCalendar.
     *
     * @param string $principalUri
     * @param string $calendarUri
     * @param array $properties
     * @return void
     */
    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
        console(__METHOD__, $calendarUri, $properties);

        return DAVBackend::folder_create('event', $properties, $calendarUri);
    }

    /**
     * Updates properties for a calendar.
     *
     * The mutations array uses the propertyName in clark-notation as key,
     * and the array value for the property value. In the case a property
     * should be deleted, the property value will be null.
     *
     * This method must be atomic. If one property cannot be changed, the
     * entire operation must fail.
     *
     * If the operation was successful, true can be returned.
     * If the operation failed, false can be returned.
     *
     * Deletion of a non-existent property is always successful.
     *
     * Lastly, it is optional to return detailed information about any
     * failures. In this case an array should be returned with the following
     * structure:
     *
     * array(
     *   403 => array(
     *      '{DAV:}displayname' => null,
     *   ),
     *   424 => array(
     *      '{DAV:}owner' => null,
     *   )
     * )
     *
     * In this example it was forbidden to update {DAV:}displayname.
     * (403 Forbidden), which in turn also caused {DAV:}owner to fail
     * (424 Failed Dependency) because the request needs to be atomic.
     *
     * @param mixed $calendarId
     * @param array $mutations
     * @return bool|array
     */
    public function updateCalendar($calendarId, array $mutations)
    {
        console(__METHOD__, $calendarId, $mutations);

        $folder = $this->get_storage_folder($calendarId);
        return DAVBackend::folder_update($folder, $mutations);
    }

    /**
     * Delete a calendar and all it's objects
     *
     * @param mixed $calendarId
     * @return void
     */
    public function deleteCalendar($calendarId)
    {
        console(__METHOD__, $calendarId);

        $folder = $this->get_storage_folder($calendarId);
        if ($folder && !kolab_storage::folder_delete($folder->name)) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error deleting calendar folder $folder->name"),
                true, false);
        }
    }


    /**
     * Returns all calendar objects within a calendar.
     *
     * Every item contains an array with the following keys:
     *   * id - unique identifier which will be used for subsequent updates
     *   * calendardata - The iCalendar-compatible calendar data (optional)
     *   * uri - a unique key which will be used to construct the uri. This can be any arbitrary string.
     *   * lastmodified - a timestamp of the last modification time
     *   * etag - An arbitrary string, surrounded by double-quotes. (e.g.: "abcdef"')
     *   * calendarid - The calendarid as it was passed to this function.
     *   * size - The size of the calendar objects, in bytes.
     *
     * Note that the etag is optional, but it's highly encouraged to return for
     * speed reasons.
     *
     * If neither etag or size are specified, the calendardata will be
     * used/fetched to determine these numbers. If both are specified the
     * amount of times this is needed is reduced by a great degree.
     *
     * @param mixed $calendarId
     * @return array
     */
    public function getCalendarObjects($calendarId)
    {
        console(__METHOD__, $calendarId);

        $query = array();
        $events = array();
        $storage = $this->get_storage_folder($calendarId);
        if ($storage) {
            foreach ((array)$storage->select($query) as $event) {
                $events[] = array(
                    'id' => $event['uid'],
                    'uri' => $event['uid'] . '.ics',
                    'lastmodified' => $event['changed'] ? $event['changed']->format('U') : null,
                    'calendarid' => $calendarId,
                    'etag' => self::_get_etag($event),
                    'size' => $event['_size'],
                );
            }
        }

        return $events;
    }


    /**
     * Returns information from a single calendar object, based on it's object
     * uri.
     *
     * The returned array must have the same keys as getCalendarObjects. The
     * 'calendardata' object is required here though, while it's not required
     * for getCalendarObjects.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @return array
     */
    public function getCalendarObject($calendarId, $objectUri)
    {
        console(__METHOD__, $calendarId, $objectUri);

        $uid = basename($objectUri, '.ics');
        $storage = $this->get_storage_folder($calendarId);

        // attachment content is requested
        if (preg_match('!^(.+).ics:attachment:(\d+):.+$!', $objectUri, $m)) {
            $uid = $m[1]; $part = $m[2];
        }

        if ($storage && ($event = $storage->get_object($uid))) {
            // deliver attachment content directly
            if ($part && !empty($event['_attachments'])) {
                foreach ($event['_attachments'] as $attachment) {
                    if ($attachment['id'] == $part) {
                        header('Content-Type: ' . $attachment['mimetype']);
                        header('Content-Disposition: inline; filename="' . $attachment['name'] . '"');
                        $storage->get_attachment($uid, $part, null, true);
                        exit;
                    }
                }
            }

            // map attributes
            $event['attachments'] = $event['_attachments'];

            // compose an absilute URI for referencing object attachments
            $base_uri = DAVBackend::abs_url(array(
                CalDAV\Plugin::CALENDAR_ROOT,
                preg_replace('!principals/!', '', $this->calendars[$calendarId]['principaluri']),
                $calendarId,
                $event['uid'] . '.ics',
            ));

            // default response
            return array(
                'id' => $event['uid'],
                'uri' => $event['uid'] . '.ics',
                'lastmodified' => $event['changed'] ? $event['changed']->format('U') : null,
                'calendarid' => $calendarId,
                'calendardata' => $this->_to_ical($event, $base_uri, $storage),
                'etag' => self::_get_etag($event),
            );
        }

        return array();
    }


    /**
     * Creates a new calendar object.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @return string|null
     */
    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        console(__METHOD__, $calendarId, $objectUri, $calendarData);

        $uid = basename($objectUri, '.ics');
        $storage = $this->get_storage_folder($calendarId);
        $object = $this->parse_calendar_data($calendarData, $uid);

        if (empty($object) || empty($object['uid'])) {
            throw new DAV\Exception('Parse error: not a valid iCalendar 2.0 object');
        }

        // if URI doesn't match the content's UID, the object might already exist!
        if ($object['uid'] != $uid && $storage->get_object($object['uid'])) {
            $objectUri = $object['uid'] . '.ics';
            Plugin::$redirect_basename = $objectUri;
            return $this->updateCalendarObject($calendarId, $objectUri, $calendarData);
        }

        // map attachments attribute
        $object['_attachments'] = $object['attachments'];
        unset($object['attachments']);

        $success = $storage->save($object, $object['_type']);
        if (!$success) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error saving $object[_type] object to Kolab server"),
                true, false);

            throw new DAV\Exception('Error saving calendar object to backend');
        }

        // send Location: header if URI doesn't match object's UID (Bug #2109)
        if ($object['uid'] != $uid) {
            Plugin::$redirect_basename = $object['uid'].'.ics';
        }

        // return new Etag
        return $success ? self::_get_etag($object) : null;
    }

    /**
     * Updates an existing calendarobject, based on it's uri.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @return string|null
     */
    public function updateCalendarObject($calendarId, $objectUri, $calendarData)
    {
        console(__METHOD__, $calendarId, $objectUri, $calendarData);

        $uid = basename($objectUri, '.ics');
        $storage = $this->get_storage_folder($calendarId);
        $object = $this->parse_calendar_data($calendarData, $uid);

        if (empty($object)) {
            throw new DAV\Exception('Parse error: not a valid iCalendar 2.0 object');
        }

        // sanity check
        if ($object['uid'] != $uid) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error creating calendar object: UID doesn't match object URI"),
                true, false);

            throw new DAV\Exception\NotFound("UID doesn't match object URI");
        }

        // copy meta data (starting with _) from old object
        $old = $storage->get_object($uid);
        foreach ((array)$old as $key => $val) {
            if (!isset($object[$key]) && $key[0] == '_')
                $object[$key] = $val;
        }

        // process attachments
        if (/* user agent known to handle attachments inline */ !empty($object['attachments'])) {
            $object['_attachments'] = $object['attachments'];
            unset($object['attachments']);

            // mark all existing attachments as deleted (update is always absolute)
            foreach ($old['_attachments'] as $key => $attach) {
                $object['_attachments'][$key] = false;
            }
        }

        // save object
        $saved = $storage->save($object, $object['_type'], $uid);
        if (!$saved) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error saving event object to Kolab server"),
                true, false);

            Plugin::$redirect_basename = null;
            throw new DAV\Exception('Error saving event object to backend');
        }

        // return new Etag
        return self::_get_etag($object);
    }

    /**
     * Deletes an existing calendar object.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @return void
     */
    public function deleteCalendarObject($calendarId, $objectUri)
    {
        console(__METHOD__, $calendarId, $objectUri);

        $uid = basename($objectUri, '.ics');
        if ($storage = $this->get_storage_folder($calendarId)) {
            $storage->delete($uid);
        }
    }

    /**
     * Performs a calendar-query on the contents of this calendar.
     *
     * The calendar-query is defined in RFC4791 : CalDAV. Using the
     * calendar-query it is possible for a client to request a specific set of
     * object, based on contents of iCalendar properties, date-ranges and
     * iCalendar component types (VTODO, VEVENT).
     *
     * This method should just return a list of (relative) urls that match this
     * query.
     *
     * The list of filters are specified as an array. The exact array is
     * documented by Sabre\CalDAV\CalendarQueryParser.
     *
     * Note that it is extremely likely that getCalendarObject for every path
     * returned from this method will be called almost immediately after. You
     * may want to anticipate this to speed up these requests.
     *
     * Requests that are extremely common are:
     *   * requests for just VEVENTS
     *   * requests for just VTODO
     *   * requests with a time-range-filter on either VEVENT or VTODO.
     *
     * ..and combinations of these requests. It may not be worth it to try to
     * handle every possible situation and just rely on the (relatively
     * easy to use) CalendarQueryValidator to handle the rest.
     *
     * Note that especially time-range-filters may be difficult to parse. A
     * time-range filter specified on a VEVENT must for instance also handle
     * recurrence rules correctly.
     * A good example of how to interprete all these filters can also simply
     * be found in Sabre\CalDAV\CalendarQueryFilter. This class is as correct
     * as possible, so it gives you a good idea on what type of stuff you need
     * to think of.
     *
     * @param mixed $calendarId
     * @param array $filters
     * @return array
     */
    public function calendarQuery($calendarId, array $filters)
    {
      console(__METHOD__, $calendarId, $filters);

      // build kolab storage query from $filters
      $query = array();
      foreach ((array)$filters['comp-filters'] as $filter) {
          if ($filter['name'] != 'VEVENT')
              continue;
          if (is_array($filter['time-range'])) {
              if (!empty($filter['time-range']['end'])) {
                  $query[] = array('dtstart', '<=', $filter['time-range']['end']);
              }
              if (!empty($filter['time-range']['start'])) {
                  $query[] = array('dtend',   '>=', $filter['time-range']['start']);
              }
          }
      }

      $results = array();
      if ($storage = $this->get_storage_folder($calendarId)) {
          foreach ((array)$storage->select($query) as $event) {
              // TODO: cache the already fetched events in memory (really?)
              $results[] = $event['uid'] . '.ics';
          }
      }

      return $results;
    }

    /**
     * Set User-Agent string of the connected client
     */
    public function setUserAgent($uastring)
    {
        $ua_classes = array(
            'ical'      => 'iCal/\d',
            'outlook'   => 'iCal4OL/\d',
            'lightning' => 'Lightning/\d',
        );

        foreach ($ua_classes as $class => $regex) {
            if (preg_match("!$regex!", $uastring)) {
                $this->useragent = $class;
                break;
            }
        }
    }


    /**********  Data conversion utilities  ***********/

    /**
     * Parse the given iCal string into a hash array kolab_format_event can handle
     *
     * @param string iCal data block
     * @return array Hash array with event properties or null on failure
     */
    private function parse_calendar_data($calendarData, $uid)
    {
        try {
            $ical = libcalendaring::get_ical();

            // use already parsed object
            if (Plugin::$parsed_vevent && Plugin::$parsed_vevent->UID == $uid) {
                $objects = $ical->import_from_vobject(Plugin::$parsed_vcalendar);
            }
            else {
                $objects = $ical->import($calendarData);
            }

            // return the first object
            if (count($objects)) {
                return $objects[0];
            }
        }
        catch (VObject\ParseException $e) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "iCal data parse error: " . $e->getMessage()),
                true, false);
        }

        return null;
    }

    /**
     * Build a valid iCal format block from the given event
     *
     * @param array Hash array with event/task properties from libkolab
     * @param string Absolute URI referenceing this event object
     * @param object RECURRENCE-ID property when serializing a recurrence exception
     * @return mixed VCALENDAR string containing the VEVENT data
     *    or VObject\VEvent object with a recurrence exception instance
     * @see: \libvcalendar::export()
     */
    private function _to_ical($event, $base_uri, $storage, $recurrence_id = null)
    {
        $ical = libcalendaring::get_ical();
        $ical->set_prodid('-//Kolab//iRony DAV Server ' . KOLAB_DAV_VERSION . '//Sabre//Sabre VObject ' . VObject\Version::VERSION . '//EN');
        $ical->set_agent($this->useragent == 'ical' ? 'Apple' : '');

        // list attachments as absolute URIs for Thunderbird
        if ($this->useragent == 'lightning') {
            $ical->set_attach_uri($base_uri . ':attachment:{{id}}:{{name}}');
            $get_attachment = null;
        }
        else {   // embed attachments for others
            $get_attachment = function($id, $event) use ($storage) {
                return $storage->get_attachment($event['uid'], $id);
            };
        }

        return $ical->export(array($event), null, false, $get_attachment);
    }

    /**
     * Generate an Etag string from the given event data
     *
     * @param array Hash array with event properties from libkolab
     * @return string Etag string
     */
    private static function _get_etag($event)
    {
        return sprintf('"%s-%d"', substr(md5($event['uid']), 0, 16), $event['_msguid']);
    }

}
