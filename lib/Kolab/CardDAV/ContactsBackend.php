<?php

/**
 * SabreDAV Contacts backend for Kolab.
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

use \rcube;
use \rcube_charset;
use \kolab_storage;
use Sabre\DAV;
use Sabre\CardDAV;
use Sabre\VObject;
use Kolab\Utils\DAVBackend;
use Kolab\Utils\VObjectUtils;

/**
 * Kolab Contacts backend.
 *
 * Checkout the Sabre\CardDAV\Backend\BackendInterface for all the methods that must be implemented.
 */
class ContactsBackend extends CardDAV\Backend\AbstractBackend
{
    private $sources;
    private $folders;
    private $aliases;
    private $useragent;


    /**
     * Read available contact folders from server
     */
    private function _read_sources()
    {
        // already read sources
        if (isset($this->sources))
            return $this->sources;

        // get all folders that have "contact" type
        $folders = kolab_storage::get_folders('contact');
        $this->sources = $this->folders = $this->aliases = array();

        foreach (kolab_storage::sort_folders($folders) as $folder) {
            $id = DAVBackend::get_uid($folder);
            $fdata = $folder->get_imap_data();  // fetch IMAP folder data for CTag generation
            $this->folders[$id] = $folder;
            $this->sources[$id] = array(
                'id' => $id,
                'uri' => $id,
                '{DAV:}displayname' => html_entity_decode($folder->get_name(), ENT_COMPAT, RCUBE_CHARSET),
                '{http://calendarserver.org/ns/}getctag' => sprintf('%d-%d-%d', $fdata['UIDVALIDITY'], $fdata['HIGHESTMODSEQ'], $fdata['UIDNEXT']),
                '{urn:ietf:params:xml:ns:caldav}supported-address-data' => new CardDAV\Property\SupportedAddressData(),
            );
            $this->aliases[$folder->name] = $id;

            // map default folder to the magic 'all' resource
            if ($folder->default)
                $this->aliases['__all__'] = $id;
        }

        return $this->sources;
    }


    /**
     * Getter for a kolab_storage_folder representing the address book for the given ID
     *
     * @param string Folder ID
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
            return DAVBackend::get_storage_folder($id, 'contact');
        }
    }


    /**
     * Returns the list of addressbooks for a specific user.
     *
     * @param string $principalUri
     * @return array
     */
    public function getAddressBooksForUser($principalUri)
    {
        console(__METHOD__, $principalUri, $this->useragent);

        $this->_read_sources();

        // special case for the apple address book which only supports one (!) address book
        if ($this->useragent == 'macosx' && count($this->sources) > 1) {
            $source = $this->getAddressBookByName('__all__');
            $source['principaluri'] = $principalUri;
            return array($source);
        }

        $addressBooks = array();
        foreach ($this->sources as $id => $source) {
            $source['principaluri'] = $principalUri;
            $addressBooks[] = $source;
        }

        return $addressBooks;
    }

    /**
     * Returns properties for a specific node identified by name/uri
     *
     * @param string Node name/uri
     * @return array Hash array with addressbook properties or null if not found
     */
    public function getAddressBookByName($addressBookUri)
    {
        console(__METHOD__, $addressBookUri);

        $this->_read_sources();
        $id = $addressBookUri;

        // return the magic *single* address book for Apple's Address Book App
        if ($id == '__all__') {
            $ctags = array();
            foreach ($this->sources as $source) {
                $ctags[] = $source['{http://calendarserver.org/ns/}getctag'];
            }

            return array(
                'id' => '__all__',
                'uri' => '__all__',
                '{DAV:}displayname' => 'All',
                '{http://calendarserver.org/ns/}getctag' => join(':', $ctags),
                '{urn:ietf:params:xml:ns:caldav}supported-address-data' => new CardDAV\Property\SupportedAddressData(),
            );
        }

        // resolve aliases (addressbook by folder name)
        if ($this->aliases[$addressBookUri]) {
            $id = $this->aliases[$addressBookUri];
        }

        return $this->sources[$id];
    }

    /**
     * Updates an addressbook's properties
     *
     * See Sabre\DAV\IProperties for a description of the mutations array, as
     * well as the return value.
     *
     * @param mixed $addressBookId
     * @param array $mutations
     * @see Sabre\DAV\IProperties::updateProperties
     * @return bool|array
     */
    public function updateAddressBook($addressBookId, array $mutations)
    {
        console(__METHOD__, $addressBookId, $mutations);

        if ($addressBookId == '__all__')
            return false;

        $folder = $this->get_storage_folder($addressBookId);
        return $folder ? DAVBackend::folder_update($folder, $mutations) : false;
    }

    /**
     * Creates a new address book
     *
     * @param string $principalUri
     * @param string $url Just the 'basename' of the url.
     * @param array $properties
     * @return void
     */
    public function createAddressBook($principalUri, $url, array $properties)
    {
        console(__METHOD__, $principalUri, $url, $properties);

        return DAVBackend::folder_create('contact', $properties, $url);
    }

    /**
     * Deletes an entire addressbook and all its contents
     *
     * @param int $addressBookId
     * @return void
     */
    public function deleteAddressBook($addressBookId)
    {
        console(__METHOD__, $addressBookId);

        if ($addressBookId == '__all__')
            return;

        $folder = $this->get_storage_folder($addressBookId);
        if ($folder && !kolab_storage::folder_delete($folder->name)) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error deleting calendar folder $folder->name"),
                true, false);
        }
    }

    /**
     * Returns all cards for a specific addressbook id.
     *
     * This method should return the following properties for each card:
     *   * carddata - raw vcard data
     *   * uri - Some unique url
     *   * lastmodified - A unix timestamp
     *   * etag - A unique etag. This must change every time the card changes.
     *   * size - The size of the card in bytes.
     *
     * If these last two properties are provided, less time will be spent
     * calculating them. If they are specified, you can also ommit carddata.
     * This may speed up certain requests, especially with large cards.
     *
     * @param mixed $addressBookId
     * @return array
     */
    public function getCards($addressBookId)
    {
        console(__METHOD__, $addressBookId);

        // recursively fetch contacts from all folders
        if ($addressBookId == '__all__') {
            $cards = array();
            foreach ($this->sources as $id => $source) {
                $cards = array_merge($cards, $this->getCards($id));
            }
            return $cards;
        }

        $groups_support = $this->useragent != 'thunderbird';
        $query = array(array('type', '=', $groups_support ? array('contact','distribution-list') : 'contact'));
        $cards = array();
        if ($storage = $this->get_storage_folder($addressBookId)) {
            foreach ((array)$storage->select($query) as $contact) {
                $cards[] = array(
                    'id' => $contact['uid'],
                    'uri' => $contact['uid'] . '.vcf',
                    'lastmodified' => $contact['changed']->format('U'),
                    'etag' => self::_get_etag($contact),
                    'size' => $contact['_size'],
                );
            }
        }

        return $cards;
    }

    /**
     * Returns a specfic card.
     *
     * The same set of properties must be returned as with getCards. The only
     * exception is that 'carddata' is absolutely required.
     *
     * @param mixed $addressBookId
     * @param string $cardUri
     * @return array
     */
    public function getCard($addressBookId, $cardUri)
    {
        console(__METHOD__, $addressBookId, $cardUri);

        $uid = basename($cardUri, '.vcf');

        // search all folders for the given card
        if ($addressBookId == '__all__') {
            $contact = $this->get_card_by_uid($uid, $storage);
        }
        else {
            $storage = $this->get_storage_folder($addressBookId);
            $contact = $storage->get_object($uid, '*');
        }

        if ($contact) {
            return array(
                'id' => $contact['uid'],
                'uri' => $contact['uid'] . '.vcf',
                'lastmodified' => $contact['changed']->format('U'),
                'carddata' => $this->_to_vcard($contact),
                'etag' => self::_get_etag($contact),
            );
        }

        return array();
    }

    /**
     * Creates a new card.
     *
     * The addressbook id will be passed as the first argument. This is the
     * same id as it is returned from the getAddressbooksForUser method.
     *
     * The cardUri is a base uri, and doesn't include the full path. The
     * cardData argument is the vcard body, and is passed as a string.
     *
     * It is possible to return an ETag from this method. This ETag is for the
     * newly created resource, and must be enclosed with double quotes (that
     * is, the string itself must contain the double quotes).
     *
     * You should only return the ETag if you store the carddata as-is. If a
     * subsequent GET request on the same card does not have the same body,
     * byte-by-byte and you did return an ETag here, clients tend to get
     * confused.
     *
     * @param mixed $addressBookId
     * @param string $cardUri
     * @param string $cardData
     * @return string|null
     */
    public function createCard($addressBookId, $cardUri, $cardData)
    {
        console(__METHOD__, $addressBookId, $cardUri, $cardData);

        $uid = basename($cardUri, '.vcf');
        $storage = $this->get_storage_folder($addressBookId);
        $object = $this->parse_vcard($cardData, $uid);

        if (empty($object) || empty($object['uid'])) {
            throw new DAV\Exception('Parse error: not a valid VCard object');
        }

        // if URI doesn't match the content's UID, the object might already exist!
        $cardUri = $object['uid'] . '.vcf';
        if ($object['uid'] != $uid && $this->getCard($addressBookId, $cardUri)) {
            Plugin::$redirect_basename = $cardUri;
            return $this->updateCard($addressBookId, $cardUri, $cardData);
        }

        $success = $storage->save($object, $object['_type']);
        if (!$success) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error saving contact object to Kolab server"),
                true, false);

            throw new DAV\Exception('Error saving contact card to backend');
        }

        // send Location: header if URI doesn't match object's UID (Bug #2109)
        if ($object['uid'] != $uid) {
            Plugin::$redirect_basename = $cardUri;
        }

        // return new Etag
        return $success ? self::_get_etag($object) : null;
    }

    /**
     * Updates a card.
     *
     * The addressbook id will be passed as the first argument. This is the
     * same id as it is returned from the getAddressbooksForUser method.
     *
     * The cardUri is a base uri, and doesn't include the full path. The
     * cardData argument is the vcard body, and is passed as a string.
     *
     * It is possible to return an ETag from this method. This ETag should
     * match that of the updated resource, and must be enclosed with double
     * quotes (that is: the string itself must contain the actual quotes).
     *
     * If you don't return an ETag, you can just return null.
     *
     * @param mixed $addressBookId
     * @param string $cardUri
     * @param string $cardData
     * @return string|null
     */
    public function updateCard($addressBookId, $cardUri, $cardData)
    {
        console(__METHOD__, $addressBookId, $cardUri, $cardData);

        $uid = basename($cardUri, '.vcf');
        $object = $this->parse_vcard($cardData, $uid);

        // sanity check
        if ($object['uid'] != $uid) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error creating contact object: UID doesn't match object URI"),
                true, false);

            throw new DAV\Exception\NotFound("UID doesn't match object URI");
        }

        if ($addressBookId == '__all__') {
            $old = $this->get_card_by_uid($uid, $storage);
        }
        else {
            if ($storage = $this->get_storage_folder($addressBookId))
                $old = $storage->get_object($uid);
        }

        if (!$storage) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Unable to find storage folder for contact $addressBookId/$cardUri"),
                true, false);

            throw new DAV\Exception\NotFound("Invalid address book URI");
        }

        if (!$this->is_writeable($storage)) {
            throw new DAV\Exception\Forbidden('Insufficient privileges to update this card');
        }

        // copy meta data (starting with _) from old object
        foreach ((array)$old as $key => $val) {
            if (!isset($object[$key]) && $key[0] == '_')
                $object[$key] = $val;
        }

        // save object
        $saved = $storage->save($object, $object['_type'], $uid);
        if (!$saved) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error saving contact object to Kolab server"),
                true, false);

            Plugin::$redirect_basename = null;
            throw new DAV\Exception('Error saving contact card to backend');
        }

        // return new Etag
        return self::_get_etag($object);
    }

    /**
     * Deletes a card
     *
     * @param mixed $addressBookId
     * @param string $cardUri
     * @return bool
     */
    public function deleteCard($addressBookId, $cardUri)
    {
        console(__METHOD__, $addressBookId, $cardUri);

        $uid = basename($cardUri, '.vcf');

        if ($addressBookId == '__all__') {
            $this->get_card_by_uid($uid, $storage);
        }
        else {
            $storage = $this->get_storage_folder($addressBookId);
        }

        if (!$storage || !$this->is_writeable($storage)) {
            throw new DAV\Exception\MethodNotAllowed('Insufficient privileges to delete this card');
        }

        if ($storage) {
            return $storage->delete($uid);
        }

        return false;
    }


    /**
     * Set User-Agent string of the connected client
     */
    public function setUserAgent($uastring)
    {
        $ua_classes = array(
            'thunderbird' => 'Thunderbird/\d',
            'macosx'      => '(Mac OS X/.+)?AddressBook/\d(.+\sCardDAVPlugin)?',
            'ios'         => '(iOS/\d|[Dd]ata[Aa]ccessd/\d)',
        );

        foreach ($ua_classes as $class => $regex) {
            if (preg_match("!$regex!", $uastring)) {
                $this->useragent = $class;
                break;
            }
        }
    }


    /**
     * Find an object and the containing folder by UID
     *
     * @param string Object UID
     * @param object Return parameter for the kolab_storage_folder instance
     * @return array|false
     */
    private function get_card_by_uid($uid, &$storage)
    {
        $obj = kolab_storage::get_object($uid, 'contact');
        if ($obj) {
            $storage = kolab_storage::get_folder($obj['_mailbox']);
            return $obj;
        }

        return false;
    }

    /**
     * Internal helper method to determine whether the given kolab_storage_folder is writeable
     *
     */
    private function is_writeable($storage)
    {
        $rights = $storage->get_myrights();
        return (strpos($rights, 'i') !== false || $storage->get_namespace() == 'personal');
    }

    /**
     * Helper method to determine whether the connected client is an Apple device
     */
    private function is_apple()
    {
        return $this->useragent == 'macosx' || $this->useragent == 'ios';
    }


    /**********  Data conversion utilities  ***********/

    private $phonetypes = array(
        'main'    => 'voice',
        'homefax' => 'fax',
        'workfax' => 'fax',
        'mobile'  => 'cell',
        'other'   => 'textphone',
    );
    
    private $improtocols = array(
        'jabber' => 'xmpp',
    );


    /**
     * Parse the given VCard string into a hash array kolab_format_contact can handle
     *
     * @param string VCard data block
     * @return array Hash array with contact properties or null on failure
     */
    private function parse_vcard($cardData, $uid)
    {
        try {
            // use already parsed object
            if (Plugin::$parsed_vcard && Plugin::$parsed_vcard->UID == $uid) {
                $vobject = Plugin::$parsed_vcard;
            }
            else {
                VObject\Property::$classMap['REV'] = 'Sabre\\VObject\\Property\\DateTime';
                $vobject = VObject\Reader::read($cardData, VObject\Reader::OPTION_FORGIVING | VObject\Reader::OPTION_IGNORE_INVALID_LINES);
            }

            if ($vobject && $vobject->name == 'VCARD') {
                $contact = $this->_to_array($vobject);
                if (!empty($contact['uid'])) {
                    return $contact;
                }
            }
        }
        catch (VObject\ParseException $e) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "VCard data parse error: " . $e->getMessage()),
                true, false);
        }

        return null;
    }


    /**
     * Build a valid VCard format block from the given contact record
     *
     * @param array Hash array with contact properties from libkolab
     * @return string VCARD string containing the contact data
     */
    private function _to_vcard($contact)
    {
        $vc = VObject\Component::create('VCARD');
        $vc->version = '3.0';
        $vc->prodid = '-//Kolab//iRony DAV Server ' . KOLAB_DAV_VERSION . '//Sabre//Sabre VObject ' . VObject\Version::VERSION . '//EN';

        $vc->add('UID', $contact['uid']);
        $vc->add('FN', $contact['name']);

        // distlists are KIND:group
        if ($contact['_type'] == 'distribution-list') {
            // group cards are actually vcard version 4
            if (!$this->is_apple()) {
                $vc->version = '4.0';
                $prop_prefix = '';
            }
            else {
                // prefix group properties for Apple
                $prop_prefix = 'X-ADDRESSBOOKSERVER-';
            }

            $vc->add($prop_prefix . 'KIND', 'group');

            foreach ((array)$contact['member'] as $member) {
                if ($member['uid'])
                    $value = 'urn:uuid:' . $member['uid'];
                else if ($member['email'] && $member['name'])
                    $value = urlencode(sprintf('mailto:"%s" <%s>', addcslashes($member['name'], '"'), $member['email']));
                else if ($member['email'])
                    $value = urlencode('mailto:' . $member['email']);
                $vc->add($prop_prefix . 'MEMBER', $value);
            }
        }
        else {
            $n = VObject\Property::create('N');
            $n->setParts(array($contact['surname'], $contact['firstname'], $contact['middlename'], $contact['prefix'], $contact['suffix']));
            $vc->add($n);
        }

        if (!empty($contact['nickname']))
            $vc->add('NICKNAME', $contact['nickname']);
        if (!empty($contact['jobtitle']))
            $vc->add('TITLE', $contact['jobtitle']);
        if (!empty($contact['profession']))
            $vc->add('X-PROFESSION', $contact['profession']);

        if (!empty($contact['organization']) || !empty($contact['department'])) {
            $org = VObject\Property::create('ORG');
            $org->setParts(array($contact['organization'], $contact['department']));
            $vc->add($org);
        }

        // TODO: save as RELATED
        if (!empty($contact['assistant']))
            $vc->add('X-ASSISTANT', join(',', (array)$contact['assistant']));
        if (!empty($contact['manager']))
            $vc->add('X-MANAGER', join(',', (array)$contact['manager']));
        if (!empty($contact['spouse']))
            $vc->add('X-SPOUSE', $contact['spouse']);
        if (!empty($contact['children']))
            $vc->add('X-CHILDREN', join(',', (array)$contact['children']));

        foreach ((array)$contact['email'] as $email) {
            $vc->add('EMAIL', $email['address'], array('type' => rtrim('INTERNET,' . strtoupper($email['type']), ',')));
        }

        foreach ((array)$contact['phone'] as $phone) {
            $type = $this->phonetypes[$phone['type']] ?: $phone['type'];
            $vc->add('TEL', $phone['number'], array('type' => strtoupper($type)));
        }

        foreach ((array)$contact['website'] as $website) {
            $vc->add('URL', $website['url'], array('type' => strtoupper($website['type'])));
        }

        $improtocolmap = array_flip($this->improtocols);
        foreach ((array)$contact['im'] as $im) {
            list($prot, $val) = explode(':', $im);
            if ($val) $vc->add('x-' . ($improtocolmap[$prot] ?: $prot), $val);
            else      $vc->add('IMPP', $im);
        }

        foreach ((array)$contact['address'] as $adr) {
            $vadr = VObject\Property::create('ADR', null, array('type' => strtoupper($adr['type'])));
            $vadr->setParts(array('','', $adr['street'], $adr['locality'], $adr['region'], $adr['code'], $adr['country']));
            $vc->add($vadr);
        }

        if (!empty($contact['notes']))
            $vc->add('NOTE', $contact['notes']);

        if (!empty($contact['gender']))
            $vc->add('SEX', $contact['gender']);

        // convert date cols to DateTime objects
        foreach (array('birthday','anniversary') as $key) {
            if (!empty($contact[$key]) && !$contact[$key] instanceof \DateTime) {
                try {
                    $contact[$key] = new \DateTime('@' . \rcube_utils::strtotime($contact[$key]));
                }
                catch (\Exception $e) {
                    $contact[$key] = null;
                }
            }
        }

        if (!empty($contact['birthday']) && $contact['birthday'] instanceof \DateTime) {
            // FIXME: Date values are ignored by Thunderbird
            $contact['birthday']->_dateonly = true;
            $vc->add(VObjectUtils::datetime_prop('BDAY', $contact['birthday'], false));
        }
        if (!empty($contact['anniversary']) && $contact['anniversary'] instanceof \DateTime) {
            $contact['anniversary']->_dateonly = true;
            $vc->add(VObjectUtils::datetime_prop('ANNIVERSARY', $contact['anniversary'], false));
        }

        if (!empty($contact['categories'])) {
            $cat = VObject\Property::create('CATEGORIES');
            $cat->setParts((array)$contact['categories']);
            $vc->add($cat);
        }

        if (!empty($contact['freebusyurl']))
            $vc->add('FBURL', $contact['freebusyurl']);

        if (!empty($contact['photo'])) {
            $vc->PHOTO = base64_encode($contact['photo']);
            $vc->PHOTO->add('BASE64', null);
        }

        // add custom properties
        foreach ((array)$contact['x-custom'] as $prop) {
            $vc->add($prop[0], $prop[1]);
        }

        // send anniversary field as itemN.X-ABDATE
        if ($this->is_apple() && !empty($contact['anniversary'])) {
            $vc->add(VObjectUtils::datetime_prop('iRony.X-ABDATE', $contact['anniversary'], false));
            $vc->add('iRony.X-ABLabel', '_$!<Anniversary>!$_');
            unset($vc->ANNIVERSARY);
        }

        if (!empty($contact['changed']))
            $vc->add(VObjectUtils::datetime_prop('REV', $contact['changed'], true));

        return $vc->serialize();
    }

    /**
     * Convert the given Sabre\VObject\Component\Vcard object to a libkolab compatible contact format
     *
     * @param object Vcard object to convert
     * @return array Hash array with contact properties
     */
    private function _to_array($vc)
    {
        $contact = array(
            '_type' => 'contact',
            'uid'  => strval($vc->UID),
            'name' => strval($vc->FN),
            'x-custom' => array(),
        );

        if ($vc->REV) {
            try { $contact['changed'] = $vc->REV->getDateTime(); }
            catch (\Exception $e) {
                try { $contact['changed'] = new \DateTime(strval($vc->REV)); }
                catch (\Exception $e) { }
            }
        }

        // map Apple proprietary anniversary field to regular field
        foreach ($vc->select('X-ABDATE') as $prop) {
            $labelkey = $prop->group ? $prop->group . '.X-ABLABEL' : 'X-ABLABEL';
            $labels = $vc->select($labelkey);
            if (!empty($labels) && ($label = reset($labels)) && strtolower(trim($label->value, '_$!<>')) == 'anniversary') {
                $prop->group = null;
                $prop->name = 'ANNIVERSARY';
                unset($vc->{$labelkey});
                break;
            }
        }

        $phonetypemap = array_flip($this->phonetypes);

        // map attributes to internal fields
        foreach ($vc->children as $prop) {
            if (!($prop instanceof VObject\Property))
                continue;

            switch ($prop->name) {
                case 'N':
                    list($contact['surname'], $contact['firstname'], $contact['middlename'], $contact['prefix'], $contact['suffix']) = $prop->getParts();
                    break;

                case 'NOTE':
                    $contact['notes'] = $prop->value;
                    break;

                case 'TITLE':
                case 'NICKNAME':
                    $contact[strtolower($prop->name)] = $prop->value;
                    break;

                case 'ORG':
                    list($contact['organization'], $contact['department']) = $prop->getParts();
                    break;

                case 'CATEGORY':
                case 'CATEGORIES':
                    $contact['categories'] = $prop->getParts();
                    break;

                case 'EMAIL':
                    $types = array_values(self::array_filter($prop->offsetGet('type'), 'internet,pref', true));
                    $contact['email'][] = array('address' => $prop->value, 'type' => strtolower($types[0] ?: 'other'));
                    break;

                case 'URL':
                    $types = array_values(self::array_filter($prop->offsetGet('type'), 'internet,pref', true));
                    $contact['website'][] = array('url' => $prop->value, 'type' => strtolower($types[0]));
                    break;

                case 'TEL':
                    $types = array_values(self::array_filter($prop->offsetGet('type'), 'internet,pref', true));
                    $type = strtolower($types[0]);
                    $contact['phone'][] = array('number' => $prop->value, 'type' => $phonetypemap[$type] ?: $type);
                    break;

                case 'ADR':
                    $type = $prop->offsetGet('type');
                    $adr = array('type' => strtolower($type));
                    list(,, $adr['street'], $adr['locality'], $adr['region'], $adr['code'], $adr['country']) = $prop->getParts();
                    $contact['address'][] = $adr;
                    break;

                case 'BDAY':
                    $contact['birthday'] = new \DateTime($prop->value);
                    $contact['birthday']->_dateonly = true;
                    break;

                case 'ANNIVERSARY':
                case 'X-ANNIVERSARY':
                    $contact['anniversary'] = new \DateTime($prop->value);
                    $contact['anniversary']->_dateonly = true;
                    break;

                case 'SEX':
                case 'X-GENDER':
                    $contact['gender'] = $prop->value;
                    break;

                case 'X-PROFESSION':
                case 'X-SPOUSE':
                    $contact[strtolower(substr($prop->name, 2))] = $prop->value;
                    break;

                case 'X-MANAGER':
                case 'X-ASSISTANT':
                case 'X-CHILDREN':
                    $contact[strtolower(substr($prop->name, 2))] = explode(',', $prop->value);
                    break;

                case 'X-JABBER':
                case 'X-ICQ':
                case 'X-MSN':
                case 'X-AIM':
                case 'X-YAHOO':
                case 'X-SKYPE':
                    $protocol = strtolower(substr($prop->name, 2));
                    $contact['im'][] = ($this->improtocols[$protocol] ?: $protocol) . ':' . preg_replace('/^[a-z]+:/i', '', $prop->value);
                    break;

                case 'IMPP':
                    $type = strtolower((string)$prop->offsetGet('X-SERVICE-TYPE'));
                    $protocol = $type && !preg_match('/^[a-z]+:/i', $prop->value) ? ($this->improtocols[$type] ?: $type) . ':' : '';
                    $contact['im'][] = $protocol . urldecode($prop->value);
                    break;

                case 'PHOTO':
                    $param = $prop->offsetGet('encoding') ?: $prop->parameters[0];
                    if ($param->value && (strtolower($param->value) == 'b' || strtolower($param->value) == 'base64') || strtolower($param->name) == 'base64') {
                        $contact['photo'] = base64_decode($prop->value);
                    }
                    break;

                case 'KIND':
                case 'X-ADDRESSBOOKSERVER-KIND':
                    if (strtolower($prop->value) == 'group') {
                        $contact['_type'] = 'distribution-list';
                    }
                    break;

                case 'MEMBER':
                case 'X-ADDRESSBOOKSERVER-MEMBER':
                    if (strpos($prop->value, 'urn:uuid:') === 0) {
                        $contact['member'][] = array('uid' => substr($prop->value, 9));
                    }
                    else if (strpos($prop->value, 'mailto:') === 0) {
                        $member = reset(\rcube_mime::parse_address_list(urldecode(substr($prop->value, 7))));
                        if ($member['address'])
                            $contact['member'][] = array('email' => $member['address'], 'name' => $member['name']);
                    }
                    break;

                case 'CUSTOM1':
                case 'CUSTOM2':
                case 'CUSTOM3':
                case 'CUSTOM4':
                default:
                    if (substr($prop->name, 0, 2) == 'X-' || substr($prop->name, 0, 6) == 'CUSTOM') {
                        $prefix = $prop->group ? $prop->group . '.' : '';
                        $contact['x-custom'][] = array($prefix . $prop->name, strval($prop->value));
                    }
                    break;
            }
        }

        if (is_array($contact['im']))
            $contact['im'] = array_unique($contact['im']);

        return $contact;
    }

    /**
     * Extract array values by a filter
     *
     * @param array Array to filter
     * @param keys Array or comma separated list of values to keep
     * @param boolean Invert key selection: remove the listed values
     *
     * @return array The filtered array
     */
    private static function array_filter($arr, $values, $inverse = false)
    {
        if (!is_array($values)) {
            $values = explode(',', $values);
        }

        $result = array();
        $keep   = array_flip((array)$values);

        if (!empty($arr)) {
            foreach ($arr as $key => $val) {
                if ($inverse != isset($keep[strtolower($val)])) {
                    $result[$key] = $val;
                }
            }
        }

        return $result;
    }

    /**
     * Generate an Etag string from the given contact data
     *
     * @param array Hash array with contact properties from libkolab
     * @return string Etag string
     */
    private static function _get_etag($contact)
    {
        return sprintf('"%s-%d"', substr(md5($contact['uid']), 0, 16), $contact['_msguid']);
    }

}
