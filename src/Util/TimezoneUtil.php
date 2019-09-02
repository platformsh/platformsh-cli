<?php
declare(strict_types=1);

namespace Platformsh\Cli\Util;

class TimezoneUtil
{
    /**
     * Get the timezone intended by the user.
     *
     * The timezone is detected with the following priorities:
     *
     * 1. A value previously set via date_default_timezone_set(), which can
     *    only be known if it is not the default, UTC.
     * 2. The value of the ini setting 'date.timezone'.
     * 3. The value of the TZ environment variable, if set.
     * 4. A best guess at the system timezone: see self::detectSystemTimezone().
     * 5. Default to the value of date_default_timezone_get(), which at this
     *    stage will almost definitely be UTC.
     *
     * @return string
     */
    public static function getTimezone()
    {
        // Suppress the PHP warning, "It is not safe to rely on the system's
        // timezone settings".
        $currentTz = @date_default_timezone_get();
        if ($currentTz !== 'UTC') {
            return $currentTz;
        }

        if (ini_get('date.timezone')) {
            return ini_get('date.timezone');
        }

        if (getenv('TZ')) {
            return (string) getenv('TZ');
        }

        if ($systemTz = self::detectSystemTimezone()) {
            return $systemTz;
        }

        return $currentTz;
    }

    /**
     * Detect the system timezone, restoring functionality from PHP < 5.4.
     *
     * @return string|false
     */
    private static function detectSystemTimezone()
    {
        // Mac OS X (and older Linuxes): /etc/localtime is a symlink to the
        // timezone in /usr/share/zoneinfo or /var/db/timezone/zoneinfo.
        if (is_link('/etc/localtime')) {
            $filename = readlink('/etc/localtime');
            $prefixes = [
                '/usr/share/zoneinfo/',
                '/var/db/timezone/zoneinfo/',
            ];
            foreach ($prefixes as $prefix) {
                if (strpos($filename, $prefix) === 0) {
                    return substr($filename, strlen($prefix));
                }
            }
        }

        // Ubuntu and Debian.
        if (file_exists('/etc/timezone')) {
            $data = file_get_contents('/etc/timezone');
            if ($data !== false) {
                return trim($data);
            }
        }

        // RHEL and CentOS.
        if (file_exists('/etc/sysconfig/clock')) {
            $data = parse_ini_file('/etc/sysconfig/clock');
            if (!empty($data['ZONE'])) {
                return trim($data['ZONE']);
            }
        }

        return false;
    }
}
