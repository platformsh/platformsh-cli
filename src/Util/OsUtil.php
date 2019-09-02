<?php
declare(strict_types=1);

namespace Platformsh\Cli\Util;

class OsUtil
{
    /**
     * @return bool
     */
    public static function isWindows()
    {
        return defined('PHP_WINDOWS_VERSION_BUILD');
    }

    /**
     * @return bool
     */
    public static function isOsX()
    {
        return stripos(PHP_OS, 'Darwin') !== false;
    }

    /**
     * Escapes a shell argument for POSIX shells, even when run on Windows.
     *
     * PHP's escapeshellarg() function adapts its output depending on the
     * system. So to escape arguments consistently for remote non-Windows
     * systems, we need our own method.
     *
     * @param string $arg
     *
     * @return string
     */
    public static function escapePosixShellArg($arg)
    {
        return "'" . str_replace("'", "'\\''", $arg) . "'";
    }
}
