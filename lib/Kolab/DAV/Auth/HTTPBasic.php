<?php

/**
 * SabreDAV Auth Backend implementation for Kolab.
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

namespace Kolab\DAV\Auth;

use \rcube;
use \rcube_user;
use \rcube_utils;
use Kolab\Utils\CacheAPC;

/**
 *
 */
class HTTPBasic extends \Sabre\DAV\Auth\Backend\AbstractBasic
{
    // Make the current user name available to all classes
    public static $current_user = null;
    public static $current_pass = null;

    /**
     * Validates a username and password
     *
     * This method should return true or false depending on if login
     * succeeded.
     *
     * @param string $username
     * @param string $password
     * @return bool
     */
    protected function validateUserPass($username, $password)
    {
        $rcube = rcube::get_instance();

        // use shared cache for kolab_auth plugin result (username canonification)
        $cache     = $rcube->get_cache_shared('kolabdav_auth');
        $cache_key = md5($username . '::' . $password);

        if (!$cache || !($auth = $cache->get($cache_key))) {
            $auth = $rcube->plugins->exec_hook('authenticate', array(
                'host'  => $this->_select_host($username),
                'user'  => $username,
                'pass'  => $password,
            ));

            if ($cache) {
                $cache->set($cache_key, array(
                    'user'  => $auth['user'],
                    'host'  => $auth['host'],
                ));
            }
        }
        else {
            $auth['pass'] = $password;
        }

        // authenticate user against the IMAP server
        $user_id = $this->_login($auth['user'], $auth['pass'], $auth['host']);

        if ($user_id) {
            self::$current_user = $auth['user'];
            self::$current_pass = $auth['pass'];

            return true;
        }

        return false;
    }

    /**
     * Returns information about the currently logged in username.
     *
     * If nobody is currently logged in, this method should return null.
     *
     * @return string|null
     */
    public function getCurrentUser()
    {
        // return the canonic user name
        return self::$current_user;
    }

    /**
     * Storage host selection
     */
    protected function _select_host($username)
    {
        // Get IMAP host
        $rcube = rcube::get_instance();
        $host  = $rcube->config->get('default_host', 'localhost');

        if (is_array($host)) {
            list($user, $domain) = explode('@', $username);

            // try to select host by mail domain
            if (!empty($domain)) {
                foreach ($host as $storage_host => $mail_domains) {
                    if (is_array($mail_domains) && in_array_nocase($domain, $mail_domains)) {
                        $host = $storage_host;
                        break;
                    }
                    else if (stripos($storage_host, $domain) !== false || stripos(strval($mail_domains), $domain) !== false) {
                        $host = is_numeric($storage_host) ? $mail_domains : $storage_host;
                        break;
                    }
                }
            }

            // take the first entry if $host is not found
            if (is_array($host)) {
                list($key, $val) = each($default_host);
                $host = is_numeric($key) ? $val : $key;
            }
        }

        return rcube_utils::parse_host($host);
    }

    /**
     * Authenticates a user in IMAP and returns Roundcube user ID.
     */
    protected function _login($username, $password, $host)
    {
        if (empty($username)) {
            return null;
        }

        $rcube        = rcube::get_instance();
        $storage      = $rcube->get_storage();
        $login_lc     = $rcube->config->get('login_lc');
        $default_port = $rcube->config->get('default_port', 143);

        // parse $host
        $a_host = parse_url($host);
        if ($a_host['host']) {
            $host = $a_host['host'];
            $ssl = (isset($a_host['scheme']) && in_array($a_host['scheme'], array('ssl','imaps','tls'))) ? $a_host['scheme'] : null;
            if (!empty($a_host['port'])) {
                $port = $a_host['port'];
            }
            else if ($ssl && $ssl != 'tls' && (!$default_port || $default_port == 143)) {
                $port = 993;
            }
        }

        if (!$port) {
            $port = $default_port;
        }

        // Convert username to lowercase. If storage backend
        // is case-insensitive we need to store always the same username
        if ($login_lc) {
            if ($login_lc == 2 || $login_lc === true) {
                $username = mb_strtolower($username);
            }
            else if (strpos($username, '@')) {
                // lowercase domain name
                list($local, $domain) = explode('@', $username);
                $username = $local . '@' . mb_strtolower($domain);
            }
        }

        // Here we need IDNA ASCII
        // Only rcube_contacts class is using domain names in Unicode
        $host     = rcube_utils::idn_to_ascii($host);
        $username = rcube_utils::idn_to_ascii($username);

        // user already registered?
        if ($user = rcube_user::query($username, $host)) {
            $username = $user->data['username'];
        }

        // authenticate user in IMAP
        if (!$storage->connect($host, $username, $password, $port, $ssl)) {
            return null;
        }

        // No user in database, but IMAP auth works
        if (!is_object($user)) {
            if ($rcube->config->get('auto_create_user')) {
                // create a new user record
                $user = rcube_user::create($username, $host);

                if (!$user) {
                    rcube::raise_error(array(
                        'code' => 620, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__,
                        'message' => "Failed to create a user record",
                    ), true, false);
                    return null;
                }
            }
            else {
                rcube::raise_error(array(
                    'code' => 620, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Access denied for new user $username. 'auto_create_user' is disabled",
                ), true, false);
                return null;
            }
        }

        // overwrite config with user preferences
        $rcube->user = $user;
        $rcube->config->set_user_prefs((array)$rcube->user->get_prefs());
        $rcube->password = $password;

        setlocale(LC_ALL, 'en_US.utf8', 'en_US.UTF-8');

        return $user->ID;
    }
}
