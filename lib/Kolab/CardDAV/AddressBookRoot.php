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

namespace Kolab\CardDAV;

use Sabre\DAVACL;
use Sabre\CardDav\Backend;

/**
 * AddressBook rootnode
 *
 * This object lists a collection of users, which can contain addressbooks.
 */
class AddressBookRoot extends DAVACL\AbstractPrincipalCollection
{
    /**
     * Principal Backend
     *
     * @var Sabre\DAVACL\PrincipalBackend\BackendInteface
     */
    protected $principalBackend;

    /**
     * CardDAV backend
     *
     * @var Backend\BackendInterface
     */
    protected $carddavBackend;

    /**
     * Constructor
     *
     * This constructor needs both a principal and a carddav backend.
     *
     * By default this class will show a list of addressbook collections for
     * principals in the 'principals' collection. If your main principals are
     * actually located in a different path, use the $principalPrefix argument
     * to override this.
     *
     * @param DAVACL\PrincipalBackend\BackendInterface $principalBackend
     * @param Backend\BackendInterface $carddavBackend
     * @param string $principalPrefix
     */
    public function __construct(DAVACL\PrincipalBackend\BackendInterface $principalBackend, Backend\BackendInterface $carddavBackend, $principalPrefix = 'principals')
    {
        $this->carddavBackend = $carddavBackend;
        parent::__construct($principalBackend, $principalPrefix);
    }

    /**
     * Returns the name of the node
     *
     * @return string
     */
    public function getName()
    {
        return \Sabre\CardDAV\Plugin::ADDRESSBOOK_ROOT;
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
        return new UserAddressBooks($this->carddavBackend, $principal['uri']);
    }

}
