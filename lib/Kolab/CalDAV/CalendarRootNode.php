<?php

/**
 * SabreDAV CalendarRootNode derived class for the Kolab.
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

use \Sabre\CalDAV;
use \Sabre\DAVACL\PrincipalBackend;
use \Sabre\DAVACL\AbstractPrincipalCollection;

/**
 * Calendars collection
 *
 * This object is responsible for generating a list of calendar-homes for each
 * user.
 *
 */
class CalendarRootNode extends AbstractPrincipalCollection
{
    /**
     * CalDAV backend
     *
     * @var Sabre\CalDAV\Backend\BackendInterface
     */
    protected $caldavBackend;

    /**
     * Constructor
     *
     * This constructor needs both an authentication and a caldav backend.
     *
     * By default this class will show a list of calendar collections for
     * principals in the 'principals' collection. If your main principals are
     * actually located in a different path, use the $principalPrefix argument
     * to override this.
     *
     * @param PrincipalBackend\BackendInterface $principalBackend
     * @param Backend\BackendInterface $caldavBackend
     * @param string $principalPrefix
     */
    public function __construct(PrincipalBackend\BackendInterface $principalBackend, CalDAV\Backend\BackendInterface $caldavBackend, $principalPrefix = 'principals')
    {
        parent::__construct($principalBackend, $principalPrefix);
        $this->caldavBackend = $caldavBackend;
    }

    /**
     * Returns the nodename
     *
     * We're overriding this, because the default will be the 'principalPrefix',
     * and we want it to be Sabre\CalDAV\Plugin::CALENDAR_ROOT
     *
     * @return string
     */
    public function getName()
    {
        return CalDAV\Plugin::CALENDAR_ROOT;
    }

    /**
     * This method returns a node for a principal.
     *
     * The passed array contains principal information, and is guaranteed to
     * at least contain a uri item. Other properties may or may not be
     * supplied by the authentication backend.
     *
     * @param array $principal
     * @return \Sabre\DAV\INode
     */
    public function getChildForPrincipal(array $principal)
    {
        return new UserCalendars($this->caldavBackend, $principal);
    }

}
