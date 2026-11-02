<?php

namespace Oweb\Analytics;

/**
 * Matches an IP address against a single address or a CIDR range (IPv4 and IPv6)
 */
class IpRange
{
    public static function contains(string $range, string $ip): bool
    {
        [$subnet, $bits] = array_pad(explode('/', trim($range), 2), 2, null);

        $subnet = @inet_pton($subnet);
        $ip     = @inet_pton($ip);

        if ($subnet === false || $ip === false || strlen($subnet) !== strlen($ip)) {
            return false;
        }

        $max  = strlen($ip) * 8;
        $bits = $bits === null ? $max : (int)$bits;

        if ($bits < 0 || $bits > $max) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rest  = $bits % 8;

        if (substr($subnet, 0, $bytes) !== substr($ip, 0, $bytes)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $rest) & 0xFF;

        return (ord($subnet[$bytes]) & $mask) === (ord($ip[$bytes]) & $mask);
    }
}
