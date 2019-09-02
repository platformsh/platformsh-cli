<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local\DependencyManager;

interface DependencyManagerInterface
{
    /**
     * Returns the command name of the dependency manager to be used.
     *
     * @return string
     */
    public function getCommandName();

    /**
     * Checks whether the dependency manager itself is available (installed).
     *
     * @return bool
     */
    public function isAvailable();

    /**
     * Returns help for the user to install the dependency manager itself.
     *
     * @return string
     */
    public function getInstallHelp();

    /**
     * Installs a list of dependencies.
     *
     * @param string $path        A path in which dependencies can be installed,
     *                            or (if $global is true) a path for running
     *                            commands and writing config files.
     * @param array $dependencies An associative array of dependencies with
     *                            their versions.
     * @param bool $global        Whether to install dependencies globally for
     *                            the user or system (i.e. not attached to the
     *                            project).
     */
    public function install($path, array $dependencies, $global = false);

    /**
     * Returns a list of "bin" directories in which dependencies are installed.
     *
     * @param string $path The path prefix for the dependencies.
     *
     * @return array An array of absolute paths.
     */
    public function getBinPaths($path);

    /**
     * Returns a list of environment variables for using installed dependencies.
     *
     * @param string $path The path prefix for the dependencies.
     *
     * @return array An associative array of environment variables.
     */
    public function getEnvVars($path);
}
