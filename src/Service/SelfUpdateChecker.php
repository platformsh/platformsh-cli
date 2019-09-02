<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SelfUpdateChecker
{
    private static $checkedUpdates = false;

    private $config;
    private $input;
    private $questionHelper;
    private $selfUpdater;
    private $shell;
    private $state;
    private $stdErr;

    public function __construct(
        Config $config,
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        SelfUpdater $selfUpdater,
        Shell $shell,
        State $state
    ) {
        $this->config = $config;
        $this->input = $input;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->questionHelper = $questionHelper;
        $this->selfUpdater = $selfUpdater;
        $this->shell = $shell;
        $this->state = $state;
    }

    public function checkUpdates()
    {
        // Avoid checking more than once in this process.
        if (self::$checkedUpdates) {
            return;
        }
        self::$checkedUpdates = true;

        // Check that the Phar extension is available.
        if (!extension_loaded('Phar')) {
            return;
        }

        // Get the filename of the Phar, or stop if this instance of the CLI is
        // not a Phar.
        $pharFilename = \Phar::running(false);
        if (!$pharFilename) {
            return;
        }

        // Check if the file is writable.
        if (!is_writable($pharFilename)) {
            return;
        }

        // Check if updates are configured.
        if (!$this->config->get('updates.check')) {
            return;
        }

        // Determine an embargo time, after which updates can be checked.
        $timestamp = time();
        $embargoTime = $timestamp - $this->config->get('updates.check_interval');

        // Stop if updates were last checked after the embargo time.
        if ($this->state->get('updates.last_checked') > $embargoTime) {
            return;
        }

        // Stop if the Phar was updated after the embargo time.
        if (filemtime($pharFilename) > $embargoTime) {
            return;
        }

        // Ensure classes are auto-loaded if they may be needed after the
        // update.
        $currentVersion = $this->config->getVersion();

        $this->selfUpdater->setAllowMajor(true);
        $this->selfUpdater->setTimeout(5);

        try {
            $newVersion = $this->selfUpdater->update(null, $currentVersion);
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'Failed to download') !== false) {
                $this->stdErr->writeln('<error>' . $e->getMessage() . '</error>');
                $newVersion = false;
            } else {
                throw $e;
            }
        }

        $this->state->set('updates.last_checked', $timestamp);

        // If the update was successful, and it's not a major version change,
        // then prompt the user to continue after updating.
        if ($newVersion !== false) {
            $exitCode = 0;
            list($currentMajorVersion,) = explode('.', $currentVersion, 2);
            list($newMajorVersion,) = explode('.', $newVersion, 2);
            if ($newMajorVersion === $currentMajorVersion
                && $this->input instanceof ArgvInput
                && is_executable($pharFilename)) {
                /** @noinspection PhpUndefinedMethodInspection */
                $originalCommand = $this->input->__toString();
                $questionText = "\n"
                    . 'Original command: <info>' . $originalCommand . '</info>'
                    . "\n\n" . 'Continue?';
                if ($this->questionHelper->confirm($questionText)) {
                    $this->stdErr->writeln('');
                    $exitCode = $this->shell->executeSimple(escapeshellarg($pharFilename) . ' ' . $originalCommand);
                }
            }
            exit($exitCode);
        }

        $this->stdErr->writeln('');
    }
}
