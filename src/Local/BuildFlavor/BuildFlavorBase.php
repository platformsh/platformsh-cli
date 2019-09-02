<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local\BuildFlavor;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Local\LocalApplication;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BuildFlavorBase implements BuildFlavorInterface
{

    /**
     * Files from the app root to ignore during install.
     *
     * @var string[]
     */
    protected $ignoredFiles = [];

    /**
     * Special destinations for installation.
     *
     * @var array
     *   An array of filenames in the app root, mapped to destinations. The
     *   destinations are filenames supporting the replacements:
     *     "{webroot}" - see getWebRoot() (usually /app/public on Platform.sh)
     *     "{approot}" - the $buildDir (usually /app on Platform.sh)
     */
    protected $specialDestinations = [];

    /** @var LocalApplication */
    protected $app;

    /** @var array */
    protected $settings = [];

    /** @var string  */
    protected $buildDir;

    /** @var bool */
    protected $copy = false;

    /** @var OutputInterface */
    protected $output;

    /** @var OutputInterface */
    protected $stdErr;

    /** @var Filesystem */
    protected $fsHelper;

    /** @var Git */
    protected $gitHelper;

    /** @var Shell */
    protected $shellHelper;

    /** @var Config */
    protected $config;

    /** @var string */
    protected $appRoot;

    /** @var string */
    private $documentRoot;

    /**
     * Whether all app files have just been symlinked or copied to the build.
     *
     * @var bool
     */
    private $buildInPlace = false;

    /**
     * @param object     $fsHelper
     * @param Shell|null $shellHelper
     * @param object     $gitHelper
     */
    public function __construct($fsHelper = null, Shell $shellHelper = null, $gitHelper = null)
    {
        $this->shellHelper = $shellHelper ?: new Shell();
        $this->fsHelper = $fsHelper ?: new Filesystem($this->shellHelper);
        $this->gitHelper = $gitHelper ?: new Git($this->shellHelper);
        $this->stdErr = $this->output = new NullOutput();

        $this->specialDestinations = [
            "favicon.ico" => "{webroot}",
            "robots.txt" => "{webroot}",
        ];

        // Platform.sh has '.platform.app.yaml', but we need to be stricter.
        $this->ignoredFiles = ['.*', ];
    }

    /**
     * @inheritdoc
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
        $this->stdErr = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
        $this->shellHelper->setOutput($output);
    }

    /**
     * @inheritdoc
     */
    public function addIgnoredFiles(array $ignoredFiles)
    {
        $this->ignoredFiles = array_merge($this->ignoredFiles, $ignoredFiles);
    }

    /**
     * @inheritdoc
     */
    public function prepare($buildDir, LocalApplication $app, Config $config, array $settings = [])
    {
        $this->app = $app;
        $this->appRoot = $app->getRoot();
        $this->documentRoot = $app->getDocumentRoot();
        $this->settings = $settings;
        $this->config = $config;

        if ($this->config->get('local.copy_on_windows')) {
            $this->fsHelper->setCopyOnWindows(true);
        }
        $this->ignoredFiles[] = $this->config->get('local.web_root');

        $this->setBuildDir($buildDir);

        if (!empty($settings['clone'])) {
            $settings['copy'] = true;
        }

        $this->copy = !empty($settings['copy']);
        $this->fsHelper->setRelativeLinks(empty($settings['abslinks']));
    }

    /**
     * {@inheritdoc}
     */
    public function setBuildDir($buildDir)
    {
        $this->buildDir = $buildDir;
    }

    /**
     * Process the defined special destinations.
     */
    protected function processSpecialDestinations()
    {
        foreach ($this->specialDestinations as $sourcePattern => $relDestination) {
            $matched = glob($this->appRoot . '/' . $sourcePattern, GLOB_NOSORT);
            if (!$matched) {
                continue;
            }
            if ($relDestination === '{webroot}' && $this->buildInPlace) {
                continue;
            }

            // On Platform.sh these replacements would be a bit different.
            $absDestination = str_replace(
                ['{webroot}', '{approot}'],
                [$this->getWebRoot(), $this->buildDir],
                $relDestination
            );

            foreach ($matched as $source) {
                // Ignore the source if it's in ignoredFiles.
                $relSource = str_replace($this->appRoot . '/', '', $source);
                if (in_array($relSource, $this->ignoredFiles)) {
                    continue;
                }
                $destination = $absDestination;
                // Do not overwrite directories with files.
                if (!is_dir($source) && is_dir($destination)) {
                    $destination = $destination . '/' . basename($source);
                }
                // Ignore if source and destination are the same.
                if ($destination === $source) {
                    continue;
                }
                if ($this->copy) {
                    $this->stdErr->writeln("Copying $relSource to $relDestination");
                } else {
                    $this->stdErr->writeln("Symlinking $relSource to $relDestination");
                }
                // Delete existing files, emitting a warning.
                if (file_exists($destination)) {
                    $this->stdErr->writeln(
                        sprintf(
                            "Overriding existing path '%s' in destination",
                            str_replace($this->buildDir . '/', '', $destination)
                        )
                    );
                    $this->fsHelper->remove($destination);
                }
                if ($this->copy) {
                    $this->fsHelper->copy($source, $destination);
                } else {
                    $this->fsHelper->symlink($source, $destination);
                }
            }
        }
    }

    /**
     * Get the directory containing files shared between builds.
     *
     * This will be 'shared' for a single-application project, or
     * 'shared/<appName>' when there are multiple applications.
     *
     * @return string|false
     */
    protected function getSharedDir()
    {
        $shared = $this->app->getSourceDir() . '/' . $this->config->get('local.shared_dir');
        if (!$this->app->isSingle()) {
            $shared .= '/' . preg_replace('/[^a-z0-9\-_]+/i', '-', $this->app->getName());
        }
        $this->fsHelper->mkdir($shared);

        return $shared;
    }

    /**
     * @inheritdoc
     */
    public function getWebRoot()
    {
        return $this->buildDir . '/' . $this->documentRoot;
    }

    /**
     * @return string
     */
    public function getAppDir()
    {
        return $this->buildDir;
    }

    /**
     * Copy, or symlink, files from the app root to the build directory.
     *
     * @return string
     *   The absolute path to the build directory where files have been copied.
     */
    protected function copyToBuildDir()
    {
        $this->buildInPlace = true;
        $buildDir = $this->buildDir;
        if ($this->app->shouldMoveToRoot()) {
            $buildDir .= '/' . $this->documentRoot;
        }
        if (!empty($this->settings['clone'])) {
            $this->cloneToBuildDir($buildDir);
        } elseif ($this->copy) {
            $this->fsHelper->copyAll($this->appRoot, $buildDir, $this->ignoredFiles, true);
        } else {
            $this->fsHelper->symlink($this->appRoot, $buildDir);
        }

        return $buildDir;
    }

    /**
     * Clone the app to the build directory via Git.
     *
     * @param string $buildDir
     */
    private function cloneToBuildDir($buildDir)
    {
        $gitRoot = $this->gitHelper->getRoot($this->appRoot, true);
        $ref = $this->gitHelper->execute(['rev-parse', 'HEAD'], $gitRoot, true);

        $cloneArgs = ['--recursive', '--shared'];
        $tmpRepo = $buildDir . '-repo';
        if (file_exists($tmpRepo)) {
            $this->fsHelper->remove($tmpRepo, true);
        }
        $this->gitHelper->cloneRepo($gitRoot, $tmpRepo, $cloneArgs, true);
        $this->gitHelper->checkOut($ref, $tmpRepo, true, true);
        $this->fsHelper->remove($tmpRepo . '/.git');

        $appDir = $tmpRepo . '/' . substr($this->appRoot, strlen($gitRoot));
        if (!rename($appDir, $buildDir)) {
            throw new \RuntimeException(sprintf('Failed to move app from %s to %s', $appDir, $buildDir));
        }
        $this->fsHelper->remove($tmpRepo);
    }

    /**
     * @inheritdoc
     */
    public function install()
    {
        $this->processSharedFileMounts();
    }

    /**
     * Process shared file mounts in the application.
     *
     * For each "mount", this creates a corresponding directory in the project's
     * shared files directory, and symlinks it into the appropriate path in the
     * build.
     */
    protected function processSharedFileMounts()
    {
        $sharedDir = $this->getSharedDir();
        if ($sharedDir === false) {
            return;
        }

        // If the build directory is a symlink, then skip, so that we don't risk
        // modifying the user's repository.
        if (is_link($this->buildDir)) {
            return;
        }

        $sharedFileMounts = $this->app->getSharedFileMounts();
        if (empty($sharedFileMounts)) {
            return;
        }

        $sharedDirRelative = $this->config->get('local.shared_dir');
        $this->stdErr->writeln('Creating symbolic links to mimic shared file mounts');
        foreach ($sharedFileMounts as $appPath => $sharedPath) {
            $target = $sharedDir . '/' . $sharedPath;
            $targetRelative = $sharedDirRelative . '/' . $sharedPath;
            $link = $this->buildDir . '/' . $appPath;
            if (file_exists($link) && !is_link($link)) {
                $this->stdErr->writeln('  Removing existing file <comment>' . $appPath . '</comment>');
                $this->fsHelper->remove($link);
            }
            if (!file_exists($target)) {
                $this->fsHelper->mkdir($target, 0775);
            }
            $this->stdErr->writeln(
                '  Symlinking <info>' . $appPath . '</info> to <info>' . $targetRelative . '</info>'
            );
            $this->fsHelper->symlink($target, $link);
        }
    }

    /**
     * Create a settings.local.php for a Drupal site.
     *
     * This helps with database setup, etc.
     */
    protected function installDrupalSettingsLocal()
    {
        $sitesDefault = $this->getWebRoot() . '/sites/default';
        $shared = $this->getSharedDir();
        $settingsLocal = $sitesDefault . '/settings.local.php';

        if ($shared !== false && is_dir($sitesDefault) && !file_exists($settingsLocal)) {
            $sharedSettingsLocal = $shared . '/settings.local.php';
            $relative = $this->config->get('local.shared_dir') . '/settings.local.php';
            if (!file_exists($sharedSettingsLocal)) {
                $this->stdErr->writeln("Creating file: <info>$relative</info>");
                $this->fsHelper->copy(CLI_ROOT . '/resources/drupal/settings.local.php.dist', $sharedSettingsLocal);
                $this->stdErr->writeln(
                    'Edit this file to add your database credentials and other Drupal configuration.'
                );
            } else {
                $this->stdErr->writeln("Symlinking <info>$relative</info> into sites/default");
            }
            $this->fsHelper->symlink($sharedSettingsLocal, $settingsLocal);
        }
    }

    /**
     * @inheritdoc
     */
    public function getKeys()
    {
        return ['default'];
    }

    /**
     * @inheritdoc
     */
    public function canArchive()
    {
        return !$this->buildInPlace || $this->copy;
    }

    /**
     * Create a default .gitignore file for the app.
     *
     * @param string $source The path to a default .gitignore file, relative to
     *                       the 'resources' directory.
     */
    protected function copyGitIgnore($source)
    {
        $source = CLI_ROOT . '/resources/' . $source;
        $gitRoot = $this->gitHelper->getRoot($this->appRoot);
        if (!$gitRoot) {
            return;
        }
        $appGitIgnore = $this->appRoot . '/.gitignore';
        if (!file_exists($appGitIgnore) && !file_exists($gitRoot . '/.gitignore')) {
            $this->stdErr->writeln("Creating a .gitignore file");
            copy($source, $appGitIgnore);
        }
    }
}
