<?php
declare(strict_types=1);

namespace Platformsh\Cli\Util;

class PortUtil
{
    protected static $unsafePorts = [
        2049, // nfs
        3659, // apple-sasl / PasswordServer
        4045, // lockd
        6000, // X11
        6665, // Alternate IRC [Apple addition]
        6666, // Alternate IRC [Apple addition]
        6667, // Standard IRC [Apple addition]
        6668, // Alternate IRC [Apple addition]
        6669, // Alternate IRC [Apple addition]
    ];

    /**
     * Get the next available valid port.
     *
     * @param int         $start    The starting port number.
     * @param string|null $hostname The hostname, defaults to 127.0.0.1.
     * @param int|null    $end      The maximum port number to try (defaults to
     *                              $start + 30);
     *
     * @throws \Exception on failure
     *
     * @return int
     */
    public static function getPort($start = 3000, $hostname = null, $end = null)
    {
        $end = $end ?: $start + 30;
        for ($port = $start; $port <= $end; $port++) {
            if (static::validatePort($port) && !static::isPortInUse($port, $hostname)) {
                return $port;
            }
        }
        throw new \Exception(sprintf('Failed to find an available port between %d and %d', $start, $end));
    }

    /**
     * Validate a port number.
     *
     * @param int|string $port
     *
     * @return bool
     */
    public static function validatePort($port)
    {
        if (!is_numeric($port) || $port <= 1023 || $port > 65535) {
            return false;
        }

        return !in_array($port, self::$unsafePorts);
    }

    /**
     * Check whether a port is open.
     *
     * @param int         $port
     * @param string|null $hostname
     *
     * @return bool
     */
    public static function isPortInUse($port, $hostname = null)
    {
        $fp = @fsockopen($hostname !== null ? $hostname : '127.0.0.1', $port, $errno, $errstr, 10);
        if ($fp !== false) {
            fclose($fp);

            return true;
        }

        return false;
    }
}
