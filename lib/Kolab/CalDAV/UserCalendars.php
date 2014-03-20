<?php

/**
 * SabreDAV UserCalendars derived class for the Kolab.
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
use Sabre\DAVACL;
use Sabre\CalDAV\Backend;
use Sabre\CalDAV\Schedule;
use Kolab\CalDAV\Calendar;

/**
 * The UserCalenders class contains all calendars associated to one user
 *
 */
class UserCalendars extends \Sabre\CalDAV\UserCalendars implements DAV\IExtendedCollection, DAVACL\IACL
{
    private $outbox;

    /**
     * Returns a list of calendars
     *
     * @return array
     */
    public function getChildren()
    {
        $calendars = $this->caldavBackend->getCalendarsForUser($this->principalInfo['uri']);
        $objs = array();
        foreach ($calendars as $calendar) {
            // TODO: (later) add sharing support by implenting this all
            if ($this->caldavBackend instanceof Backend\SharingSupport) {
                if (isset($calendar['{http://calendarserver.org/ns/}shared-url'])) {
                    $objs[] = new SharedCalendar($this->caldavBackend, $calendar);
                }
                else {
                    $objs[] = new ShareableCalendar($this->caldavBackend, $calendar);
                }
            }
            else {
                $objs[] = new Calendar($this->caldavBackend, $calendar);
            }
        }

        // add support for scheduling AKA free/busy
        $objs[] = new Schedule\Outbox($this->principalInfo['uri']);

        // TODO: add notification support (check with clients first, if anybody supports it)
        if ($this->caldavBackend instanceof Backend\NotificationSupport) {
            $objs[] = new Notifications\Collection($this->caldavBackend, $this->principalInfo['uri']);
        }

        return $objs;
    }

    /**
     * Returns a single calendar, by name
     *
     * @param string $name
     * @return Calendar
     */
    public function getChild($name)
    {
        if ($name == 'outbox') {
            return new Schedule\Outbox($this->principalInfo['uri']);
        }
        if ($calendar = $this->caldavBackend->getCalendarByName($name)) {
            $calendar['principaluri'] = $this->principalInfo['uri'];
            return new Calendar($this->caldavBackend, $calendar);
        }

        throw new DAV\Exception\NotFound('Calendar with name \'' . $name . '\' could not be found');
    }

    /**
     * Checks if a calendar exists.
     *
     * @param string $name
     * @return bool
     */
    public function childExists($name)
    {
        if ($this->caldavBackend->getCalendarByName($name)) {
            return true;
        }
        return false;
    }

    /**
     * Returns a list of ACE's for this node.
     *
     * Each ACE has the following properties:
     *   - 'privilege', a string such as {DAV:}read or {DAV:}write. These are currently the only supported privileges
     *   - 'principal', a url to the principal who owns the node
     *   - 'protected' (optional), indicating that this ACE is not allowed to be updated.
     *
     * @return array
     */
    public function getACL()
    {
        // define rights for the user's calendar root (which is in fact INBOX)
        return array(
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'],
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}write',
                'principal' => $this->principalInfo['uri'],
                'protected' => true,
            ),
/* TODO: implement sharing support
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-write',
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}write',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-write',
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-read',
                'protected' => true,
            ),
*/
        );
    }

    /**
     * Updates the ACL
     *
     * This method will receive a list of new ACE's.
     *
     * @param array $acl
     * @return void
     */
    public function setACL(array $acl)
    {
        // TODO: implement this
        throw new DAV\Exception\MethodNotAllowed('Changing ACL is not yet supported');
    }

}
