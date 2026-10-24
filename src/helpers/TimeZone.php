<?php

namespace Psf\Helper;

class TimeZone{
    public static function getAllTimeZones($filter, $other = NULL) {
        $identifiers = \DateTimeZone::listIdentifiers($filter);

        $timeZones = [];

        foreach ($identifiers as $identifier) {
            $timeZone = new \DateTimeZone($identifier);
            $offset = $timeZone->getOffset(new \DateTime()) / 3600; // Offset em horas
            $timeZones[] = [
                'identifier' => $identifier,
                'offset' => $offset,
            ];
        }

        usort($timeZones, function ($a, $b) {
            return strcmp($a['identifier'], $b['identifier']);
        });

        return $timeZones;
    }

    public static function getListForSelect($filter, $other = NULL) {
        $timeZones  = TimeZone::getAllTimeZones($filter);

        foreach ($timeZones as $zone) {
            $itens[] = $zone['identifier'] . ' (GMT' . ($zone['offset'] >= 0 ? '+' : '') . $zone['offset'] . ')';
        }

        return $itens ?? [];
    }
}