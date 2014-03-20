<?php

/**
 * 503 Service Unavailable exception
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

use Sabre\DAV;

/**
 * 503 Service Unavailable
 *
 * This exception is thrown in case the service
 * is currently not available (e.g. down for maintenance).
 */
class ServiceUnavailable extends DAV\Exception\ServiceUnavailable
{
    private $retry_after = 600;

    function __construct($message, $retry = 600)
    {
        parent::__construct($message);

        $this->retry_after = $retry;
    }

    /**
     * This method allows the exception to return any extra HTTP response headers.
     *
     * The headers must be returned as an array.
     *
     * @param Server $server
     * @return array
     */
    public function getHTTPHeaders(DAV\Server $server)
    {
        return array(
            'Retry-After' => $this->retry_after,
        );
    }
}
