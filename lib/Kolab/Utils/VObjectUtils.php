<?php

/**
 * Utility class providing functions for VObject data encoding
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

use Sabre\VObject\Property;

/**
 * Helper class proviting utility functions for VObject data encoding
 */
class VObjectUtils
{

    /**
     * Create a Sabre\VObject\Property instance from a PHP DateTime object
     *
     * @param string Property name
     * @param object DateTime
     */
    public static function datetime_prop($name, $dt, $utc = false)
    {
        $vdt = new Property\DateTime($name);
        $vdt->setDateTime($dt, $dt->_dateonly ? Property\DateTime::DATE : ($utc ? Property\DateTime::UTC : Property\DateTime::LOCALTZ));
        return $vdt;
    }

    /**
     * Copy values from one hash array to another using a key-map
     */
    public static function map_keys($values, $map)
    {
        $out = array();
        foreach ($map as $from => $to) {
            if (isset($values[$from]))
                $out[$to] = $values[$from];
        }
        return $out;
    }

}