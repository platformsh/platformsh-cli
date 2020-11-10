<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\SshCert\Certifier;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\Snippeter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Process\Process;

class SshConfig {
    private $config;
    private $fs;
    private $stdErr;
    private $sshKey;
    private $certifier;
    private $openSshVersion;

    public function __construct(Config $config, Filesystem $fs, OutputInterface $output, SshKey $sshKey, Certifier $certifier)
    {
        $this->config = $config;
        $this->fs = $fs;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->sshKey = $sshKey;
        $this->certifier = $certifier;
    }

    /**
     * Creates or updates session-specific SSH configuration.
     *
     * @return bool
     *   True if there is any session configuration, false otherwise.
     */
    public function configureSessionSsh()
    {
        if (!$this->supportsInclude()) {
            return false;
        }

        // Backwards compatibility: delete the old SSH configuration file.
        $legacy = $this->getCliSshDir() . DIRECTORY_SEPARATOR . 'sess-cli-default.config';
        if (\file_exists($legacy)) {
            $this->fs->remove($legacy);
        }

        $domainWildcards = $this->config->get('api.ssh_domain_wildcards');

        $lines = [];

        if ($certificate = $this->certifier->getExistingCertificate()) {
            $executable = $this->config->get('application.executable');
            $refreshCommand = sprintf('%s ssh-cert:load --refresh-only --yes --quiet', $executable);
            if (!OsUtil::isWindows()) {
                $refreshCommand .= ' 2>/dev/null';
            }
            // Use Match solely to run the refresh command.
            $lines[] = '# Auto-refresh the SSH certificate:';
            if ($domainWildcards) {
                $lines[] = sprintf('Match host "%s" exec "%s"', \implode(',', $domainWildcards), $refreshCommand);
            } else {
                $lines[] = sprintf('Match exec "%s"', $refreshCommand);
            }
            $lines[] = '';

            // Indentation in the SSH config is for readability (it has no other effect).
            $lines[] = '# Include the certificate and its key:';
            $lines[] = sprintf('CertificateFile %s', $this->formatFilePath($certificate->certificateFilename()));
            $lines[] = sprintf('IdentityFile %s', $this->formatFilePath($certificate->privateKeyFilename()));
            $lines[] = '';
        }


        $hostBlock = '';
        if ($domainWildcards) {
            $hostBlock = 'Host ' . implode(' ', $domainWildcards);
        }

        $sessionIdentityFile = $this->sshKey->selectIdentity();
        if ($sessionIdentityFile !== null) {
            $lines[] = $hostBlock;
            $lines[] = '# This SSH key was detected as corresponding to the session:';
            $lines[] = sprintf('IdentityFile %s', $this->formatFilePath($sessionIdentityFile));
            $lines[] = '';
        }

        // Add default files if there is no preferred session identity file.
        if ($sessionIdentityFile === null && ($defaultFiles = $this->getUserDefaultSshIdentityFiles())) {
            $lines[] = '# Include SSH "default" identity files:';
            foreach ($defaultFiles as $identityFile) {
                $lines[] = $hostBlock;
                $lines[] = sprintf('IdentityFile %s', $this->formatFilePath($identityFile));
            }
            $lines[] = '';
        }

        $sessionSpecificFilename = $this->getSessionSshDir() . DIRECTORY_SEPARATOR . 'config';
        $includerFilename = $this->getCliSshDir() . DIRECTORY_SEPARATOR . 'session.config';
        if (empty($lines)) {
            if (\file_exists($includerFilename) || \file_exists($sessionSpecificFilename)) {
                $this->fs->remove([$includerFilename, $sessionSpecificFilename]);
            }
            return false;
        }

        $this->writeSshIncludeFile($sessionSpecificFilename, $lines);

        $includerLines = [
            '# This file is included from your SSH config file (~/.ssh/config).',
            '# In turn, it includes the configuration for the currently active CLI session.',
            '# It is updated automatically when certain CLI commands are run.',
        ];

        $wildcards = $this->config->get('api.ssh_domain_wildcards');
        if (count($wildcards)) {
            $includerLines[] = 'Host ' . implode(' ', $wildcards);
            $includerLines[] = '  Include ' . $sessionSpecificFilename;
            $this->writeSshIncludeFile(
                $includerFilename,
                $includerLines
            );
        }

        return true;
    }

    /**
     * Formats a file path for an SSH config option.
     *
     * This should be applied to the IdentityFile and CertificateFile option
     * values. See the ssh_config(5) man page: https://www.freebsd.org/cgi/man.cgi?ssh_config%285%29
     *
     * Note: a previous version of this method replaced the home directory with
     * SSH's percent syntax (%d). However, this does not work on systems where
     * the HOME environment variable is set to a directory other than the
     * current user's (notably on GitHub Actions containers), because the
     * OpenSSH client does not support reading HOME.
     *
     * @param string $path
     *
     * @return string
     */
    public function formatFilePath($path)
    {
        // Escape paths containing a space.
        if (\strpos($path, ' ') !== false) {
            // The three quote marks in the middle mean: end quote, literal quote mark, start quote.
            return '"' . \str_replace('"', '"""', $path) . '"';
        }
        return $path;
    }

    /**
     * Creates or updates an SSH config include file.
     *
     * @param string $filename
     * @param array  $lines
     * @param bool   $allowDelete
     */
    private function writeSshIncludeFile($filename, array $lines, $allowDelete = true)
    {
        if (empty($lines) && $allowDelete && \file_exists($filename)) {
            $this->stdErr->writeln(sprintf('Deleting SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERBOSE);
            $this->fs->remove($filename);
            return;
        }
        $contents = implode(PHP_EOL, $lines) . PHP_EOL;
        if (!\file_exists($filename)) {
            $this->stdErr->writeln(sprintf('Creating SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERBOSE);
            $this->fs->writeFile($filename, $contents, false);
        } elseif (\file_get_contents($filename) !== $contents) {
            $this->stdErr->writeln(sprintf('Updating SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERBOSE);
            $this->fs->writeFile($filename, $contents, false);
        } else {
            $this->stdErr->writeln(sprintf('Validated SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERY_VERBOSE);
        }
        $this->chmod($filename, 0600);
    }

    /**
     * Returns the directory containing session-specific SSH configuration.
     *
     * @return string
     */
    public function getSessionSshDir()
    {
        return $this->config->getSessionDir(true) . DIRECTORY_SEPARATOR . 'ssh';
    }

    /**
     * Adds configuration to the user's global SSH config file (~/.ssh/config).
     *
     * @param QuestionHelper $questionHelper
     *
     * @return bool
     */
    public function addUserSshConfig(QuestionHelper $questionHelper)
    {
        if (!$this->supportsInclude()) {
            return false;
        }

        $filename = $this->getUserSshConfigFilename();

        $wildcards = $this->config->get('api.ssh_domain_wildcards');
        if (!$wildcards) {
            return true;
        }

        $suggestedConfig = \implode("\n", [
            'Host ' . \implode(' ', $wildcards),
            '  Include ' . $this->getCliSshDir() . DIRECTORY_SEPARATOR . '*.config',
            'Host *',
        ]);

        $manualMessage = 'To configure SSH manually, add the following lines to: <comment>' . $filename . '</comment>'
            . "\n" . $suggestedConfig;

        if (file_exists($filename)) {
            $currentContents = file_get_contents($filename);
            if ($currentContents === false) {
                $this->stdErr->writeln('Failed to read file: <comment>' . $filename . '</comment>');
                return false;
            }
            if (strpos($currentContents, $suggestedConfig) !== false) {
                $this->stdErr->writeln('Validated SSH configuration file: <info>' . $filename . '</info>', OutputInterface::VERBOSITY_VERBOSE);
                return true;
            }
            $this->stdErr->writeln('Checking SSH configuration file: <info>' . $filename . '</info>');
            if (!$questionHelper->confirm('Do you want to update the file automatically?')) {
                $this->stdErr->writeln($manualMessage);
                return false;
            }
            $creating = false;
        } else {
            if (!$questionHelper->confirm('Do you want to create an SSH configuration file automatically?')) {
                $this->stdErr->writeln($manualMessage);
                return false;
            }
            $currentContents = '';
            $creating = true;
        }

        $newContents = $this->getUserSshConfigChanges($currentContents, $suggestedConfig);
        $this->fs->writeFile($filename, $newContents);

        if ($creating) {
            $this->stdErr->writeln('Configuration file created successfully: <info>' . $filename . '</info>');
        } else {
            $this->stdErr->writeln('Configuration file updated successfully: <info>' . $filename . '</info>');
        }

        $this->chmod($filename, 0600);

        return true;
    }

    /**
     * Deletes session-specific SSH configuration files.
     *
     * Called from the logout command.
     */
    public function deleteSessionConfiguration()
    {
        $files = [
            $this->getSessionSshDir() . DIRECTORY_SEPARATOR . 'config',
            $this->getCliSshDir() . DIRECTORY_SEPARATOR . 'session.config',
        ];
        if (array_filter($files, '\\file_exists') !== []) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Deleting all session SSH configuration');
            $this->fs->remove($files);
        }
    }

    /**
     * Change file permissions and emit a warning on failure.
     *
     * @param string $filename
     * @param int $mode
     *
     * @return bool
     */
    private function chmod($filename, $mode)
    {
        if (!@chmod($filename, $mode)) {
            $this->stdErr->writeln('Warning: failed to change permissions on file: <comment>' . $filename . '</comment>');
            return false;
        }
        return true;
    }

    /**
     * Returns the directory for CLI-specific SSH configuration files.
     *
     * @return string
     */
    private function getCliSshDir()
    {
        return $this->config->getWritableUserDir() . DIRECTORY_SEPARATOR . 'ssh';
    }

    /**
     * Calculates changes to a user's global SSH config file.
     *
     * @param string $currentConfig
     *   The current file contents.
     * @param string $newConfig
     *   The new config that should be inserted. Pass an empty string to delete
     *   the configuration.
     *
     * @return string The new file contents.
     */
    private function getUserSshConfigChanges($currentConfig, $newConfig)
    {
        $serviceName = (string)$this->config->get('service.name');
        $begin = '# BEGIN: ' . $serviceName . ' certificate configuration' . PHP_EOL;
        $end = PHP_EOL . '# END: ' . $serviceName . ' certificate configuration';

        return (new Snippeter())->updateSnippet($currentConfig, $newConfig, $begin, $end);
    }

    /**
     * Returns the path to the user's global SSH config file.
     *
     * @return string
     */
    private function getUserSshConfigFilename()
    {
        return $this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh' . DIRECTORY_SEPARATOR . 'config';
    }

    /**
     * Returns the user's default SSH identity files (if they exist).
     *
     * @todo prioritise account-specific identity files
     *
     * These are the filenames that are used by SSH when there is no
     * IdentityFile specified, and when there is no SSH agent.
     *
     * @see https://man.openbsd.org/ssh#i
     *
     * @return array
     */
    public function getUserDefaultSshIdentityFiles()
    {
        $dir = $this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh';
        if (!\is_dir($dir)) {
            return [];
        }
        $basenames = [
            'id_rsa',
            'id_ecdsa_sk',
            'id_ecdsa',
            'id_ed25519_sk',
            'id_ed25519',
            'id_dsa',
        ];
        $files = [];
        foreach ($basenames as $basename) {
            $filename = $dir . DIRECTORY_SEPARATOR . $basename;
            if (\file_exists($filename) && \file_exists($filename . '.pub')) {
                $files[] = $filename;
            }
        }

        return $files;
    }

    /**
     * Removes certificate configuration from the user's global SSH config file.
     *
     * @todo use this? maybe in an uninstall command
     */
    public function removeUserSshConfig()
    {
        $sshConfigFile = $this->getUserSshConfigFilename();
        if (!file_exists($sshConfigFile)) {
            return;
        }
        $currentSshConfig = file_get_contents($sshConfigFile);
        if ($currentSshConfig === false) {
            return;
        }
        $newConfig = $this->getUserSshConfigChanges($currentSshConfig, '');
        if ($newConfig === $currentSshConfig) {
            return;
        }
        $this->stdErr->writeln('Removing configuration from SSH configuration file: <info>' . $sshConfigFile . '</info>');

        try {
            $this->fs->writeFile($sshConfigFile, $newConfig);
            $this->stdErr->writeln('Configuration successfully removed');
        } catch (IOException $e) {
            $this->stdErr->writeln('The configuration could not be automatically removed: ' . $e->getMessage());
        }
    }

    /**
     * Finds the locally installed OpenSSH version.
     *
     * @param bool $reset
     *
     * @return string|false
     */
    private function findVersion($reset = false)
    {
        if (isset($this->openSshVersion) && !$reset) {
            return $this->openSshVersion;
        }
        $this->openSshVersion = false;
        $process = new Process('ssh -V');
        $process->run();
        $errorOutput = $process->getErrorOutput();
        if (!$process->isSuccessful()) {
            if ($this->stdErr->isVerbose()) {
                $this->stdErr->writeln('Unable to determine the installed OpenSSH version. The command output was:');
                $this->stdErr->writeln($errorOutput);
            }
            return false;
        }
        if (\preg_match('/OpenSSH_([0-9.]+[^ ,]*)/', $errorOutput, $matches)) {
            $this->openSshVersion = $matches[1];
        }
        return $this->openSshVersion;
    }

    /**
     * Checks if the installed OpenSSH version is below a given value.
     *
     * @param string $test
     *
     * @return bool
     *   True if the version is determined and it is lower than the $test value, false otherwise.
     */
    private function versionIsBelow($test)
    {
        $version = $this->findVersion();
        if (!$version) {
            return false;
        }
        return \version_compare($version, $test, '<');
    }

    /**
     * Checks if the installed OpenSSH version supports the 'Include' syntax.
     *
     * @return bool
     */
    public function supportsInclude() {
        return !$this->versionIsBelow('7.3');
    }

    /**
     * Checks if the installed OpenSSH version supports the 'CertificateFile' syntax.
     *
     * @return bool
     */
    public function supportsCertificateFile() {
        return !$this->versionIsBelow('7.2');
    }

    /**
     * Checks if the required version is supported, and prints a warning.
     *
     * @return bool
     *   False if an incorrect version is installed, true otherwise.
     */
    public function checkRequiredVersion()
    {
        $version = $this->findVersion();
        if (!$version) {
            return true;
        }
        if (\version_compare($version, '6.5', '<')) {
            $this->stdErr->writeln(\sprintf(
                'OpenSSH version <error>%s</error> is installed. Version 6.5 or above is required. Some features depend on version 7.3 or above.',
                $version
            ));
            return false;
        }
        if (\version_compare($version, '7.3', '<')) {
            $this->stdErr->writeln(\sprintf(
                'OpenSSH version <comment>%s</comment> is installed. Some features depend on version 7.3 or above.',
                $version
            ));
        }
        return true;
    }
}
