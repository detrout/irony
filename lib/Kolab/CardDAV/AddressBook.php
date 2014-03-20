<?php

/**
 * SabreDAV AddressBook derived class to encapsulate a Kolab storage folder
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

use \PEAR;
use Sabre\DAV;
use Sabre\DAVACL;
use Sabre\CardDAV\Backend;

/**
 * The AddressBook class represents a CardDAV addressbook, owned by a specific user
 *
 */
class AddressBook extends \Sabre\CardDAV\AddressBook implements \Sabre\CardDAV\IAddressBook, DAV\IProperties, DAVACL\IACL
{
    public $id;
    public $storage;
    public $ready = false;


    /**
     * Constructor
     *
     * @param Backend\BackendInterface $carddavBackend
     * @param array $addressBookInfo
     */
    public function __construct(Backend\BackendInterface $carddavBackend, array $addressBookInfo)
    {
        parent::__construct($carddavBackend, $addressBookInfo);

        $this->id = $addressBookInfo['id'];
        $this->storage = $carddavBackend->get_storage_folder($this->id);
        $this->ready = $this->id == '__all__' || (is_object($this->storage) && is_a($this->storage, 'kolab_storage_folder'));
    }


    /**
     * Renames the addressbook
     *
     * @param string $newName
     * @return void
     */
    public function setName($newName)
    {
        // TODO: implement this
        throw new DAV\Exception\MethodNotAllowed('Renaming addressbooks is not yet supported');
    }


    /**
     * Returns the owner principal
     *
     * This must be a url to a principal, or null if there's no owner
     *
     * @return string|null
     */
    public function getOwner()
    {
        if (!$this->storage || $this->storage->get_namespace() == 'personal') {
            return $this->addressBookInfo['principaluri'];
        }
        else {
            return 'principals/' . $this->storage->get_owner();
        }
    }

    /**
     * Returns a card
     *
     * @param string $name
     * @return \ICard
     *
    public function getChild($name)
    {
        $obj = $this->carddavBackend->getCard($this->addressBookInfo['id'], $name);
        if (!$obj) throw new DAV\Exception\NotFound('Card not found');
        return new Card($this->carddavBackend,$this->addressBookInfo, $obj);
    }*/

    /**
     * Returns a list of ACE's for this node.
     *
     * Each ACE has the following properties:
     *   * 'privilege', a string such as {DAV:}read or {DAV:}write. These are
     *     currently the only supported privileges
     *   * 'principal', a url to the principal who owns the node
     *   * 'protected' (optional), indicating that this ACE is not allowed to
     *      be updated.
     *
     * @return array
     */
    public function getACL()
    {
        // return ACL information based on IMAP MYRIGHTS
        $rights = $this->storage ? $this->storage->get_myrights() : null;
        if ($rights && !PEAR::isError($rights)) {
            // user has at least read access to calendar folders listed
            $acl = array(
                array(
                    'privilege' => '{DAV:}read',
                    'principal' => $this->addressBookInfo['principaluri'],
                    'protected' => true,
                ),
            );

            $owner = $this->getOwner();
            $is_owner = $owner == $this->addressBookInfo['principaluri'];

            if ($is_owner || strpos($rights, 'i') !== false) {
                $acl[] = array(
                    'privilege' => '{DAV:}write',
                    'principal' => $this->addressBookInfo['principaluri'],
                    'protected' => true,
                );
            }

            return $acl;
        }
        else {
            // fallback to default ACL rules based on ownership
            return parent::getACL();
        }
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
