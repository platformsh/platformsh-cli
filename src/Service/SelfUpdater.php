<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Humbug\SelfUpdate\Updater;
use Platformsh\Cli\SelfUpdate\ManifestStrategy;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SelfUpdater
{
    protected $input;
    protected $output;
    protected $stdErr;
    protected $config;
    protected $questionHelper;

    protected $timeout = 30;
    protected $allowUnstable = false;
    protected $allowMajor = false;

    /**
     * Updater constructor.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param Config          $cliConfig
     * @param QuestionHelper  $questionHelper
     */
    public function __construct(
        InputInterface $input,
        OutputInterface $output,
        Config $cliConfig,
        QuestionHelper $questionHelper
    ) {
        $this->input = $input;
        $this->output = $output;
        $this->stdErr = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
        $this->config = $cliConfig;
        $this->questionHelper = $questionHelper;
    }

    /**
     * Set the timeout for the version check.
     *
     * @param int $timeout
     *   The timeout in seconds.
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    /**
     * Set the updater to allow unstable versions.
     *
     * @param bool $allowUnstable
     */
    public function setAllowUnstable($allowUnstable = true)
    {
        $this->allowUnstable = $allowUnstable;
    }

    /**
     * Set the updater to allow major version updates.
     *
     * @param bool $allowMajor
     */
    public function setAllowMajor($allowMajor = true)
    {
        $this->allowMajor = $allowMajor;
    }

    /**
     * Run the update.
     *
     * @param string|null $manifestUrl
     * @param string|null $currentVersion
     *
     * @return false|string
     *   The new version number, or false if there was no update.
     */
    public function update($manifestUrl = null, $currentVersion = null)
    {
        $currentVersion = $currentVersion ?: $this->config->getVersion();
        $manifestUrl = $manifestUrl ?: $this->config->get('application.manifest_url');
        $applicationName = $this->config->get('application.name');
        if (!extension_loaded('Phar') || !($localPhar = \Phar::running(false))) {
            $this->stdErr->writeln(sprintf(
                'This instance of the %s was not installed as a Phar archive.',
                $applicationName
            ));

            // Instructions for users who are running a global Composer install.
            if (defined('CLI_ROOT') && file_exists(CLI_ROOT . '/../../autoload.php')) {
                $this->stdErr->writeln("Update using:\n\n  composer global update");
                $this->stdErr->writeln("\nOr you can switch to a Phar install (<options=bold>recommended</>):\n");
                $this->stdErr->writeln("  composer global remove " . $this->config->get('application.package_name'));
                $this->stdErr->writeln("  curl -sS " . $this->config->get('application.installer_url') . " | php\n");
            }

            return false;
        }

        $this->stdErr->writeln(sprintf(
            'Checking for %s updates (current version: <info>%s</info>)',
            $applicationName,
            $currentVersion
        ));

        if (!is_writable($localPhar)) {
            $this->stdErr->writeln('Cannot update as the Phar file is not writable: ' . $localPhar);

            return false;
        }

        $updater = new Updater($localPhar, false);
        $strategy = new ManifestStrategy(ltrim($currentVersion, 'v'), $manifestUrl, $this->allowMajor, $this->allowUnstable);
        $strategy->setManifestTimeout($this->timeout);
        $updater->setStrategyObject($strategy);

        if (!$updater->hasUpdate()) {
            $this->stdErr->writeln('No updates found');
            return false;
        }

        $newVersionString = $updater->getNewVersion();

        if ($notes = $strategy->getUpdateNotes($updater)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('Version <info>%s</info> is available. Update notes:', $newVersionString));
            $this->stdErr->writeln(preg_replace('/^/m', '  ', $notes));
            $this->stdErr->writeln('');
        }

        if (!$this->questionHelper->confirm(sprintf('Update to version %s?', $newVersionString))) {
            return false;
        }

        $this->stdErr->writeln(sprintf('Updating to version %s', $newVersionString));

        $updater->update();

        $this->stdErr->writeln(sprintf(
            'The %s has been successfully updated to version <info>%s</info>',
            $applicationName,
            $newVersionString
        ));

        return $newVersionString;
    }
}
