<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local\BuildFlavor;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Local\LocalApplication;
use Symfony\Component\Console\Output\OutputInterface;

interface BuildFlavorInterface
{

    /**
     * Get the compatible stack(s) for the build flavor.
     *
     * @return array
     */
    public function getStacks();

    /**
     * Get the possible configuration keys for the build flavor.
     *
     * @return array
     */
    public function getKeys();

    /**
     * Set the output stream for the build flavor.
     *
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output);

    /**
     * Prepare this application to be built.
     *
     * This function should be isometric and not affect the file system.
     *
     * @param string $buildDir      The directory in which the app should be
     *                              built.
     * @param LocalApplication $app The app to build.
     * @param Config $config     CLI configuration.
     * @param array  $settings      Additional settings for the build.
     *     Possible settings include:
     *     - clone (bool, default false) Clone the repository to the build
     *       directory before building, where possible.
     *     - copy (bool, default false) Copy files instead of symlinking them,
     *       where possible.
     *     - abslinks (bool, default false) Use absolute paths in symlinks.
     *     - no-cache (bool, default false) Disable the package cache (if
     *       relevant and if the package manager supports this).
     */
    public function prepare($buildDir, LocalApplication $app, Config $config, array $settings = []);

    /**
     * Set the build directory.
     *
     * @param string $buildDir
     */
    public function setBuildDir($buildDir);

    /**
     * Build this application. Acquire dependencies, plugins, libraries, and
     * submodules.
     */
    public function build();

    /**
     * Move files into place. This could happen straight after the build, or
     * after an old build archive has been extracted.
     */
    public function install();

    /**
     * Get the document root after build.
     *
     * @return string
     */
    public function getWebRoot();

    /**
     * Get the application root after build.
     *
     * @return string
     */
    public function getAppDir();

    /**
     * Find whether the build may be archived.
     *
     * @return bool
     */
    public function canArchive();

    /**
     * Add to the list of files (in the app root) that should not be copied.
     *
     * @param array $ignoredFiles
     */
    public function addIgnoredFiles(array $ignoredFiles);
}
