<?php

/**
 * Logon screen modification for failed logins
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
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

class loginfail extends rcube_plugin
{
    public $task    = 'login';
    public $noajax  = true;
    public $noframe = true;

    /**
     * Plugin initialization
     */
    public function init()
    {
        $this->add_hook('template_object_loginform', array($this, 'logon_page_content'));
        $this->add_hook('login_failed', array($this, 'login_failed'));
        $this->add_hook('send_page', array($this, 'send_page'));
    }

    /**
     * Detect 'Login failed' error
     */
    public function login_failed($args)
    {
        // from index.php
        $error_codes = array(
            RCMAIL::ERROR_STORAGE,
            RCMAIL::ERROR_COOKIES_DISABLED,
            RCMAIL::ERROR_INVALID_REQUEST,
            RCMAIL::ERROR_INVALID_HOST,
        );

        // 'Login failed' error?
        if (!in_array($args['code'], $error_codes)) {
            $this->failed_login = true;
        }
    }

    /**
     * Login form object container handler.
     */
    public function logon_page_content($args)
    {

        $file = $this->home . '/logon_page.html';

        if (file_exists($file)) {
            $html = file_get_contents($file);
        }

        if ($html) {
            $rcmail = rcube::get_instance();

            // Parse content with templates engine, so we can use e.g. localization
            $html = $rcmail->output->just_parse($html);

            // Add the content at the end of the BODY
            $rcmail->output->add_footer($html);
        }

        return $args;
    }

    /**
     * HTML content handler
     */
    public function send_page($args)
    {
        // Replace 'Username' label with 'Email' label on login form
        $label = $this->gettext('email');
        $args['content'] = preg_replace('/(<label for="rcmloginuser">)[^<]+/', '\\1'.$label, $args['content']);

        // Replace 'Login failed' message
        if ($this->failed_login) {
            $file = $this->home . '/logon_error.html';

            if (file_exists($file)) {
                $html = file_get_contents($file);
            }

            if ($html) {
                $rcmail = rcube::get_instance();

                // Parse content with templates engine, so we can use e.g. localization
//                $this->add_texts('localization/');
//                $html = $rcmail->output->just_parse($html);

                // disable default error message
//                $args['content'] = preg_replace('/rcmail\.display_message\([^)]+\);/', '', $args['content']);

                // inject extended error box
                $args['content'] = preg_replace('/(<div id="message"><\/div>)/', '\\1'.$html, $args['content']);
            }
        }

        return $args;
    }
}
