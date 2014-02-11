<?php

/**
 * Utility class representing a HTTP response with logging capabilities
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

namespace Kolab\Utils;

/**
 * This class represents a HTTP response.
 */
class HTTPResponse extends \Sabre\HTTP\Response
{
    private $status;
    private $body = '';
    private $headers = array();

    /**
     * Sends an HTTP status header to the client.
     *
     * @param int $code HTTP status code
     * @return bool
     */
    public function sendStatus($code)
    {
        $this->status = $this->getStatusMessage($code, $this->defaultHttpVersion);
        return parent::sendStatus($code);
    }

    /**
     * Sets an HTTP header for the response
     *
     * @param string $name
     * @param string $value
     * @param bool $replace
     * @return bool
     */
    public function setHeader($name, $value, $replace = true) {
        $this->headers[$name] = $value;
        return parent::setHeader($name, $value, $replace);
    }

    /**
     * Sends the entire response body
     *
     * This method can accept either an open filestream, or a string.
     *
     * @param mixed $body
     * @return void
     */
    public function sendBody($body)
    {
        if (is_resource($body)) {
            fpassthru($body);
            $this->body = '[binary data]';
        }
        else {
            echo $body;
            $this->body .= $body;
        }
    }

    /**
     * Dump the response data for logging
     */
    public function dump()
    {
        $result_headers = '';
        foreach ($this->headers as $hdr => $value) {
            $result_headers .= "\n$hdr: " . $value;
        }

        return $this->status . $result_headers . "\n\n" . $this->body;
    }
}