<?php

/**
 * SabreDAV UserAddressBooks derived class for the Kolab.
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
use Sabre\DAVACL;

/**
 * UserAddressBooks class
 *
 * The UserAddressBooks collection contains a list of addressbooks associated with a user
 */
class UserAddressBooks extends \Sabre\CardDAV\UserAddressBooks implements DAV\IExtendedCollection, DAVACL\IACL
{
    /**
     * Returns a list of addressbooks
     *
     * @return array
     */
    public function getChildren()
    {
        $addressbooks = $this->carddavBackend->getAddressbooksForUser($this->principalUri);
        $objs = array();
        foreach($addressbooks as $addressbook) {
            $objs[] = new AddressBook($this->carddavBackend, $addressbook);
        }
        return $objs;
    }

    /**
     * Returns a single addressbook, by name
     *
     * @param string $name
     * @return \AddressBook
     */
    public function getChild($name)
    {
        if ($addressbook = $this->carddavBackend->getAddressBookByName($name)) {
            $addressbook['principaluri'] = $this->principalUri;
            return new AddressBook($this->carddavBackend, $addressbook);
        }

        throw new DAV\Exception\NotFound('Addressbook with name \'' . $name . '\' could not be found');
    }

}
