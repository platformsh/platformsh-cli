<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\Shell;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class LocalBuild
{

    // Some changes may not be backwards-compatible with previous build
    // archives. Increment this number as breaking changes are released.
    const BUILD_VERSION = 3;

    /** @var array */
    protected $settings = [];

    /** @var OutputInterface */
    protected $output;

    /** @var Filesystem */
    protected $fsHelper;

    /** @var Git */
    protected $gitHelper;

    /** @var Shell */
    protected $shellHelper;

    /** @var DependencyInstaller */
    protected $dependencyInstaller;

    /** @var Config */
    protected $config;

    /**
     * LocalBuild constructor.
     *
     * @param Config|null                                    $config
     * @param OutputInterface|null                           $output
     * @param \Platformsh\Cli\Service\Shell|null             $shell
     * @param \Platformsh\Cli\Service\Filesystem|null        $fs
     * @param \Platformsh\Cli\Service\Git|null               $git
     * @param \Platformsh\Cli\Local\DependencyInstaller|null $dependencyInstaller
     */
    public function __construct(
        Config $config = null,
        OutputInterface $output = null,
        Shell $shell = null,
        Filesystem $fs = null,
        Git $git = null,
        DependencyInstaller $dependencyInstaller = null
    ) {
        $this->config = $config ?: new Config();
        $this->output = $output ?: new ConsoleOutput();
        if ($this->output instanceof ConsoleOutputInterface) {
            $this->output = $this->output->getErrorOutput();
        }
        $this->shellHelper = $shell ?: new Shell($this->output);
        $this->fsHelper = $fs ?: new Filesystem($this->shellHelper);
        $this->gitHelper = $git ?: new Git($this->shellHelper);
        $this->dependencyInstaller = $dependencyInstaller ?: new DependencyInstaller($this->output, $this->shellHelper);
    }

    /**
     * Build a project from any source directory, targeting any destination.
     *
     * @param array  $settings    An array of build settings.
     *     Possible settings:
     *     - clone (bool, default false) Clone the repository to the build
     *       directory before building, where possible.
     *     - copy (bool, default false) Copy files instead of symlinking them,
     *       where possible.
     *     - abslinks (bool, default false) Use absolute paths in symlinks.
     *     - no-archive (bool, default false) Do not archive or use an archive of
     *       the build.
     *     - no-cache (bool, default false) Disable the package cache (if
     *       relevant and if the package manager supports this).
     *     - no-clean (bool, default false) Disable cleaning up old builds or
     *       old build archives.
     *     - no-build-hooks (bool, default false) Disable running build hooks.
     *     - no-deps (bool, default false) Disable installing build
     *       dependencies.
     *     - concurrency (int) Specify a concurrency for Drush Make, if
     *       applicable (when using the Drupal build flavor).
     *     - working-copy (bool, default false) Specify the --working-copy
     *       option to Drush Make, if applicable.
     *     - lock (bool, default false) Create or update a lock
     *       file via Drush Make, if applicable.
     *     - run-deploy-hooks (bool, default false) Run deploy and/or
     *       post_deploy hooks.
     * @param string $sourceDir   The absolute path to the source directory.
     * @param string $destination Where the web root(s) will be linked (absolute
     *                            path).
     * @param array  $apps        An array of application names to build.
     *
     * @throws \Exception on failure
     *
     * @return bool
     */
    public function build(array $settings, $sourceDir, $destination = null, array $apps = [])
    {
        $this->settings = $settings;
        $this->fsHelper->setRelativeLinks(empty($settings['abslinks']));

        if (file_exists($sourceDir . '/.git')) {
            (new LocalProject())->writeGitExclude($sourceDir);
        }

        $ids = [];
        $success = true;
        foreach (LocalApplication::getApplications($sourceDir, $this->config) as $app) {
            $id = $app->getId();
            $ids[] = $id;
            if ($apps && !in_array($id, $apps)) {
                continue;
            }
            $success = $this->buildApp($app, $destination) && $success;
        }
        $notFounds = array_diff($apps, $ids);
        if ($notFounds) {
            foreach ($notFounds as $notFound) {
                $this->output->writeln("Application not found: <comment>$notFound</comment>");
            }
        }
        if (empty($settings['no-clean'])) {
            $this->output->writeln("Cleaning up...");
            $this->cleanBuilds($sourceDir);
            $this->cleanArchives($sourceDir);
        }

        return $success;
    }

    /**
     * Get a hash of the application files.
     *
     * This should change if any of the application files or build settings
     * change.
     *
     * @param string $appRoot
     *
     * @return string|false
     */
    public function getTreeId($appRoot)
    {
        $hashes = [];

        // Get a hash representing all the files in the application, excluding
        // the project config folder (configured in service.project_config_dir).
        $tree = $this->gitHelper->execute(['ls-files', '-s'], $appRoot);
        if ($tree === false) {
            return false;
        }
        $tree = preg_replace(
            '#^|\n[^\n]+?' . preg_quote($this->config->get('service.project_config_dir')) . '\n|$#',
            "\n",
            $tree
        );
        $hashes[] = sha1($tree);

        // Include the hashes of untracked and modified files.
        $others = $this->gitHelper->execute(
            [
                'ls-files',
                '--modified',
                '--others',
                '--exclude-standard',
                '-x ' . $this->config->get('service.project_config_dir'),
                '.',
            ],
            $appRoot
        );
        if ($others === false) {
            return false;
        }
        if (is_array($others)) {
            $count = 0;
            foreach (explode("\n", $others) as $filename) {
                if ($count > 5000) {
                    return false;
                }
                $filename = "$appRoot/$filename";
                if (is_file($filename)) {
                    $hashes[] = sha1_file($filename);
                    $count++;
                }
            }
        }

        // Include relevant build settings.
        $relevant = ['abslinks', 'copy', 'clone', 'no-cache', 'working-copy', 'lock', 'no-deps'];
        $settings = array_intersect_key($this->settings, array_flip($relevant));
        $hashes[] = serialize($settings);

        $hashes[] = self::BUILD_VERSION;

        // Combine them all.
        return sha1(implode(' ', $hashes));
    }

    /**
     * Build a single application.
     *
     * @param LocalApplication $app
     * @param string|null      $destination
     *
     * @return bool
     */
    protected function buildApp($app, $destination = null)
    {
        $verbose = $this->output->isVerbose();

        $sourceDir = $app->getSourceDir();
        $destination = $destination ?: $sourceDir . '/' . $this->config->get('local.web_root');
        $appRoot = $app->getRoot();
        $appConfig = $app->getConfig();
        $appId = $app->getId();

        $buildFlavor = $app->getBuildFlavor();

        // Find the right build directory.
        $buildName = $app->isSingle() ? 'default' : str_replace('/', '-', $appId);

        $tmpBuildDir = $sourceDir . '/' . $this->config->get('local.build_dir') . '/' . $buildName . '-tmp';

        if (file_exists($tmpBuildDir)) {
            if (!$this->fsHelper->remove($tmpBuildDir)) {
                $this->output->writeln(sprintf('Failed to remove directory <error>%s</error>', $tmpBuildDir));

                return false;
            }
        }

        // If the destination is inside the source directory, ensure it isn't
        // copied or symlinked into the build.
        if (strpos($destination, $sourceDir) === 0) {
            $buildFlavor->addIgnoredFiles([
                ltrim(substr($destination, strlen($sourceDir)), '/'),
            ]);
        }

        // Warn about a mismatched PHP version.
        if (isset($appConfig['type']) && strpos($appConfig['type'], ':')) {
            list($stack, $version) = explode(':', $appConfig['type'], 2);
            if ($stack === 'php' && version_compare($version, PHP_VERSION, '>')) {
                $this->output->writeln(sprintf(
                    '<comment>Warning:</comment> the application <comment>%s</comment> expects PHP %s, but the system version is %s.',
                    $appId,
                    $version,
                    PHP_VERSION
                ));
            }
        }

        $buildFlavor->setOutput($this->output);

        $buildFlavor->prepare($tmpBuildDir, $app, $this->config, $this->settings);

        $archive = false;
        if (empty($this->settings['no-archive']) && empty($this->settings['no-cache'])) {
            $treeId = $this->getTreeId($appRoot);
            if ($treeId) {
                if ($verbose) {
                    $this->output->writeln("Tree ID: $treeId");
                }
                $archive = $sourceDir . '/' . $this->config->get('local.archive_dir') . '/' . $treeId . '.tar.gz';
            }
        }

        $success = true;

        if ($archive && file_exists($archive)) {
            $message = "Extracting archive for application <info>$appId</info>";
            $this->output->writeln($message);
            $this->fsHelper->extractArchive($archive, $tmpBuildDir);
        } else {
            $message = "Building application <info>$appId</info>";
            if (isset($appConfig['type'])) {
                $message .= ' (runtime type: ' . $appConfig['type'] . ')';
            }
            $this->output->writeln($message);

            // Install dependencies.
            if (isset($appConfig['dependencies'])) {
                $depsDir = $sourceDir . '/' . $this->config->get('local.dependencies_dir');
                if (!empty($this->settings['no-deps'])) {
                    $this->output->writeln('Skipping build dependencies');
                } else {
                    $result = $this->dependencyInstaller->installDependencies(
                        $depsDir,
                        $appConfig['dependencies']
                    );
                    $success = $success && $result;
                }

                // Use the dependencies' PATH and other environment variables
                // for the rest of this process (for the build and build hooks).
                $this->dependencyInstaller->putEnv($depsDir, $appConfig['dependencies']);
            }

            $buildFlavor->build();

            if ($this->runPostBuildHooks($appConfig, $buildFlavor->getAppDir()) === false) {
                // The user may not care if build hooks fail, but we should
                // not archive the result.
                $archive = false;
                $success = false;
            }

            if ($archive && $buildFlavor->canArchive()) {
                $this->output->writeln("Saving build archive");
                if (!is_dir(dirname($archive))) {
                    mkdir(dirname($archive));
                }
                $this->fsHelper->archiveDir($tmpBuildDir, $archive);
            }
        }

        // The build is complete. Move the directory.
        $buildDir = substr($tmpBuildDir, 0, strlen($tmpBuildDir) - 4);
        if (file_exists($buildDir)) {
            if (empty($this->settings['no-backup']) && is_dir($buildDir) && !is_link($buildDir)) {
                $previousBuildArchive = dirname($buildDir) . '/' . basename($buildDir) . '-old.tar.gz';
                $this->output->writeln("Backing up previous build to: " . $previousBuildArchive);
                $this->fsHelper->archiveDir($buildDir, $previousBuildArchive);
            }
            if (!$this->fsHelper->remove($buildDir, true)) {
                $this->output->writeln(sprintf('Failed to remove directory <error>%s</error>', $buildDir));

                return false;
            }
        }
        if (!rename($tmpBuildDir, $buildDir)) {
            $this->output->writeln(sprintf(
                'Failed to move temporary build directory into <error>%s</error>',
                $buildDir
            ));

            return false;
        }

        $buildFlavor->setBuildDir($buildDir);
        $buildFlavor->install();

        $this->runDeployHooks($appConfig, $buildDir);

        $webRoot = $buildFlavor->getWebRoot();

        // Symlink the built web root ($webRoot) into www or www/appId.
        if (!is_dir($webRoot)) {
            $this->output->writeln("\nWeb root not found: <error>$webRoot</error>\n");

            return false;
        }

        $localWebRoot = $app->getLocalWebRoot($destination);
        $this->fsHelper->symlink($webRoot, $localWebRoot);

        $message = "\nBuild complete for application <info>$appId</info>";
        $this->output->writeln($message);
        $this->output->writeln("Web root: <info>$localWebRoot</info>\n");

        return $success;
    }

    /**
     * Run post-build hooks.
     *
     * @param array  $appConfig
     * @param string $buildDir
     *
     * @return bool|null
     *   False if the build hooks fail, true if they succeed, null if not
     *   applicable.
     */
    protected function runPostBuildHooks(array $appConfig, $buildDir)
    {
        if (!isset($appConfig['hooks']['build'])) {
            return null;
        }
        if (!empty($this->settings['no-build-hooks'])) {
            $this->output->writeln('Skipping post-build hooks');
            return null;
        }
        $this->output->writeln('Running post-build hooks');

        return $this->runHook($appConfig['hooks']['build'], $buildDir);
    }

    /**
     * Run deploy and post_deploy hooks.
     *
     * @param array  $appConfig
     * @param string $appDir
     *
     * @return bool|null
     *   False if the deploy hooks fail, true if they succeed, null if not
     *   applicable.
     */
    protected function runDeployHooks(array $appConfig, $appDir)
    {
        if (empty($this->settings['run-deploy-hooks'])) {
            return null;
        }
        if (empty($appConfig['hooks']['deploy']) && empty($appConfig['hooks']['post_deploy'])) {
            $this->output->writeln('No deploy or post_deploy hooks found');
            return null;
        }
        $result = null;
        if (!empty($appConfig['hooks']['deploy'])) {
            $this->output->writeln('Running deploy hooks');
            $result = $this->runHook($appConfig['hooks']['deploy'], $appDir);
        }
        if (!empty($appConfig['hooks']['post_deploy']) && $result !== false) {
            $this->output->writeln('Running post_deploy hooks');
            $result = $this->runHook($appConfig['hooks']['post_deploy'], $appDir);
        }

        return $result;
    }

    /**
     * Run a user-defined hook.
     *
     * @param string|array $hook
     * @param string       $dir
     *
     * @return bool
     */
    protected function runHook($hook, $dir)
    {
        $code = $this->shellHelper->executeSimple(
            implode("\n", (array) $hook),
            $dir
        );
        if ($code !== 0) {
            $this->output->writeln("<comment>The hook failed with the exit code: $code</comment>");
            return false;
        }

        return true;
    }

    /**
     * Remove old builds.
     *
     * This preserves the currently active build.
     *
     * @param string   $projectRoot
     * @param int|null $maxAge
     * @param int      $keepMax
     * @param bool     $includeActive
     * @param bool     $quiet
     *
     * @return int[]
     *   The numbers of deleted and kept builds.
     */
    public function cleanBuilds($projectRoot, $maxAge = null, $keepMax = 10, $includeActive = false, $quiet = true)
    {
        // Find all the potentially active symlinks, which might be www itself
        // or symlinks inside www. This is so we can avoid deleting the active
        // build(s).
        $blacklist = [];
        if (!$includeActive) {
            $blacklist = $this->getActiveBuilds($projectRoot);
        }

        return $this->cleanDirectory(
            $projectRoot . '/' . $this->config->get('local.build_dir'),
            $maxAge,
            $keepMax,
            $blacklist,
            $quiet
        );
    }

    /**
     * @param string $projectRoot
     *
     * @throws \Exception If it cannot be determined whether or not a symlink
     *                    points to a genuine active build.
     *
     * @return array The absolute paths to any active builds in the project.
     */
    protected function getActiveBuilds($projectRoot)
    {
        $www = $projectRoot . '/' . $this->config->get('local.web_root');
        if (!file_exists($www)) {
            return [];
        }
        $links = [$www];
        if (!is_link($www) && is_dir($www)) {
            $finder = new Finder();
            /** @var \Symfony\Component\Finder\SplFileInfo $file */
            foreach ($finder->in($www)
                            ->directories()
                            ->depth(0) as $file) {
                $links[] = $file->getPathname();
            }
        }
        $activeBuilds = [];
        $buildsDir = $projectRoot . '/' . $this->config->get('local.build_dir');
        foreach ($links as $link) {
            if (is_link($link) && ($target = readlink($link))) {
                // Make the target into an absolute path.
                $target = $target[0] === DIRECTORY_SEPARATOR ? $target : realpath(dirname($link) . '/' . $target);
                if (!$target) {
                    continue;
                }
                // Ignore the target if it doesn't point to a build in 'builds'.
                if (strpos($target, $buildsDir) === false) {
                    continue;
                }
                // The target should just be one level below the 'builds'
                // directory, not more.
                while (dirname($target) != $buildsDir) {
                    $target = dirname($target);
                    if (strpos($target, $buildsDir) === false) {
                        throw new \Exception('Error resolving active build directory');
                    }
                }
                $activeBuilds[] = $target;
            }
        }

        return $activeBuilds;
    }

    /**
     * Remove old build archives.
     *
     * @param string   $projectRoot
     * @param int|null $maxAge
     * @param int      $keepMax
     * @param bool     $quiet
     *
     * @return int[]
     *   The numbers of deleted and kept builds.
     */
    public function cleanArchives($projectRoot, $maxAge = null, $keepMax = 10, $quiet = true)
    {
        return $this->cleanDirectory(
            $projectRoot . '/' . $this->config->get('local.archive_dir'),
            $maxAge,
            $keepMax,
            [],
            $quiet
        );
    }

    /**
     * Remove old files from a directory.
     *
     * @param string   $directory
     * @param int|null $maxAge
     * @param int      $keepMax
     * @param array    $blacklist
     * @param bool     $quiet
     *
     * @return int[]
     */
    protected function cleanDirectory($directory, $maxAge = null, $keepMax = 5, array $blacklist = [], $quiet = true)
    {
        if (!is_dir($directory)) {
            return [0, 0];
        }
        $files = glob($directory . '/*');
        if (!$files) {
            return [0, 0];
        }
        // Sort files by modified time (descending).
        usort(
            $files,
            function ($a, $b) {
                return filemtime($a) < filemtime($b);
            }
        );
        $now = time();
        $numDeleted = 0;
        $numKept = 0;
        foreach ($files as $filename) {
            if (in_array($filename, $blacklist)) {
                $numKept++;
                continue;
            }
            if (($keepMax !== null && $numKept >= $keepMax)
                || ($maxAge !== null && $now - filemtime($filename) > $maxAge)) {
                if (!$quiet) {
                    $this->output->writeln("Deleting: " . basename($filename));
                }
                if ($this->fsHelper->remove($filename)) {
                    $numDeleted++;
                } elseif (!$quiet) {
                    $this->output->writeln("Failed to delete: <error>" . basename($filename) . "</error>");
                }
            } else {
                $numKept++;
            }
        }

        return [$numDeleted, $numKept];
    }
}
