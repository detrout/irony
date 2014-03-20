<?php

/**
 * Extended CalDAV IMip handler for the Kolab DAV server
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

use \rcube;
use \rcube_utils;
use \Mail_mime;

use Sabre\VObject;
use Sabre\CalDAV;
use Sabre\DAV;

/**
 * iMIP handler.
 *
 * This class is responsible for sending out iMIP messages. iMIP is the
 * email-based transport for iTIP. iTIP deals with scheduling operations for
 * iCalendar objects.
 *
 * If you want to customize the email that gets sent out, you can do so by
 * extending this class and overriding the sendMessage method.
 *
 * @copyright Copyright (C) 2007-2013 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class IMip extends CalDAV\Schedule\IMip
{
    public function __construct()
    {
        
    }
    
    /**
     * Sends one or more iTip messages through email.
     *
     * @param string $originator Originator Email
     * @param array $recipients Array of email addresses
     * @param VObject\Component $vObject
     * @param string $principal Principal Url of the originator
     * @return void
     */
    public function sendMessage($originator, array $recipients, VObject\Component $vObject, $principal)
    {
        $ics = $vObject->serialize();
        console(__METHOD__, $originator, $recipients, $principal, $ics);

        $rcube = rcube::get_instance();
        $sender = $rcube->user->get_identity();
        $sender_email = $sender['email'] ?: $rcube->get_user_email();
        $sender_name  = $sender['name']  ?: $rcube->get_user_name();

        foreach($recipients as $recipient) {
            $subject = 'KolabDAV iTIP message';
            switch (strtoupper($vObject->METHOD)) {
                case 'REPLY' :
                    $subject = 'Response for: ' . $vObject->VEVENT->SUMMARY;
                    break;
                case 'REQUEST' :
                    $subject = 'Invitation for: ' .$vObject->VEVENT->SUMMARY;
                    break;
                case 'CANCEL' :
                    $subject = 'Cancelled event: ' . $vObject->VEVENT->SUMMARY;
                    break;
            }

            $sender = rcube_utils::idn_to_ascii($sender_email);
            $from = format_email_recipient($sender, $sender_name);
            $mailto = rcube_utils::idn_to_ascii($recipient);

            // compose multipart message using PEAR:Mail_Mime
            $message = new Mail_mime("\r\n");
            $message->setParam('text_encoding', 'quoted-printable');
            $message->setParam('head_encoding', 'quoted-printable');
            $message->setParam('head_charset', RCMAIL_CHARSET);
            $message->setParam('text_charset', RCMAIL_CHARSET . ";\r\n format=flowed");

            // compose common headers array
            $headers = array(
                'To' => $mailto,
                'From' => $from,
                'Date' => date('r'),
                'Reply-To' => $originator,
                'Message-ID' => $rcube->gen_message_id(),
                'X-Sender' => $sender,
                'Subject' => $subject,
            );
            if ($agent = $rcube->config->get('useragent'))
                $headers['User-Agent'] = $agent;

            $message->headers($headers);
            $message->setContentType('text/calendar', array('method' => strval($vObject->method), 'charset' => RCMAIL_CHARSET));
            $message->setTXTBody($ics);

            // send message through Roundcube's SMTP feature
            if (!$rcube->deliver_message($message, $sender, $mailto, $smtp_error)) {
                rcube::raise_error(array(
                    'code' => 600, 'type' => 'php',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Failed to send iTIP message to " . $mailto),
                    true, false);
            }
        }
    }

    /**
     * This function is reponsible for sending the actual email.
     *
     * @param string $to Recipient email address
     * @param string $subject Subject of the email
     * @param string $body iCalendar body
     * @param array $headers List of headers
     * @return void
     */
    protected function mail($to, $subject, $body, array $headers)
    {
        //mail($to, $subject, $body, implode("\r\n", $headers));
    }

}
