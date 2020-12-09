<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\SshCert\Certifier;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class SshDiagnostics
{
    const _SSH_ERROR_EXIT_CODE = 255;

    private $ssh;
    private $sshKey;
    private $certifier;
    private $stdErr;
    private $api;
    private $config;

    private $connectionTestResult;

    public function __construct(Ssh $ssh, OutputInterface $output, Certifier $certifier, SshKey $sshKey, Api $api, Config $config)
    {
        $this->ssh = $ssh;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->sshKey = $sshKey;
        $this->certifier = $certifier;
        $this->api = $api;
        $this->config = $config;
    }

    /**
     * Checks whether the SSH connection fails due to MFA requirements.
     *
     * @param string $uri
     * @param Process|null $failedProcess
     *
     * @return bool
     */
    private function connectionFailedDueToMFA($uri, $failedProcess = null)
    {
        if (($cert = $this->certifier->getExistingCertificate()) && $cert->hasMfa()) {
            // MFA is verified.
            return false;
        }
        $failedProcess = $failedProcess ?: $this->testConnection($uri);

        return stripos($failedProcess->getErrorOutput(), 'reason: access requires MFA') !== false;
    }

    /**
     * Checks if SSH authentication succeeded, even if the connection failed.
     *
     * This occurs if the SSH key or certificate was correct, but the requested
     * service does not exist or the user does not have access to it.
     *
     * @param string $uri
     * @param Process|null $failedProcess
     *
     * @return bool
     */
    private function authenticationSucceeded($uri, $failedProcess = null)
    {
        $failedProcess = $failedProcess ?: $this->testConnection($uri);
        $stdErr = $failedProcess->getErrorOutput();

        return stripos($stdErr, "reason: service doesn't exist") !== false
            || stripos($stdErr, 'reason: service not found') !== false
            || stripos($stdErr, 'you successfully authenticated, but') !== false;
    }

    /**
     * Checks if SSH host key verification failed.
     *
     * @param string $uri
     * @param Process|null $failedProcess
     *
     * @return bool
     */
    private function hostKeyVerificationFailed($uri, $failedProcess = null)
    {
        $failedProcess = $failedProcess ?: $this->testConnection($uri);
        $stdErr = $failedProcess->getErrorOutput();

        return stripos($stdErr, "Host key verification failed.") !== false;
    }

    /**
     * Tests the SSH connection (and caches the result).
     *
     * @param string $uri
     * @param bool   $reset
     *
     * @return Process
     *   A process (already run) that tested the SSH connection.
     */
    private function testConnection($uri, $reset = false)
    {
        if (!$reset && isset($this->connectionTestResult)) {
            return $this->connectionTestResult;
        }
        $this->stdErr->writeln('Making test connection to diagnose SSH errors', OutputInterface::VERBOSITY_DEBUG);
        $process = new Process($this->ssh->getSshCommand([], $uri, 'exit'));
        $process->run();
        $this->stdErr->writeln('Test connection complete', OutputInterface::VERBOSITY_DEBUG);
        return $this->connectionTestResult = $process;
    }

    /**
     * Hackily finds a host from an SSH URI.
     *
     * @param string $uri
     *
     * @return string|false
     */
    private function getHost($uri)
    {
        // Parse the SSH URI to get the hostname.
        if (\strpos($uri, '@') !== false) {
            list(, $uri) = \explode('@', $uri, 2);
        }
        if (\strpos($uri, '://') !== false) {
            list(, $uri) = \explode('://', $uri, 2);
        }
        if (\strpos($uri, ':') !== false) {
            list($uri, ) = \explode(':', $uri, 2);
        }
        return \parse_url('ssh://' . $uri, PHP_URL_HOST);
    }

    /**
     * Hackily finds an environment from an SSH URI.
     *
     * @param string $uri
     *
     * @return Environment|false
     */
    private function getEnvironment($uri)
    {
        // Parse the SSH URI to get the hostname.
        if (\strpos($uri, '@') === false) {
            return false;
        }
        list($user,) = \explode('@', $uri, 2);
        if (\strpos($user, '-') === false) {
            return false;
        }
        list($projectId, $rest) = \explode($user, '-', 2);
        if (\strpos($rest, '--') === false) {
            return false;
        }
        list($environmentMachineName,) = \explode($rest, '--', 2);
        $project = $this->api->getProject($projectId);
        if (!$project) {
            return false;
        }
        foreach ($this->api->getEnvironments($project) as $environment) {
            if ($environment->machine_name === $environmentMachineName) {
                return $environment;
            }
        }
        return false;
    }

    /**
     * Checks if an SSH URI is for an internal (first-party) SSH server.
     *
     * @param string $uri
     *
     * @return bool
     *  True if the URI is for an internal server, false if it's external or it cannot be determined.
     */
    public function sshHostIsInternal($uri)
    {
        $host = $this->getHost($uri);
        if ($host === false) {
            return false;
        }
        // Check against the wildcard list.
        foreach ($this->config->getWithDefault('api.ssh_domain_wildcards', []) as $wildcard) {
            if (\strpos($host, \str_replace('*.', '', $wildcard)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Diagnoses and reports reasons for an SSH command failure.
     *
     * @param string $uri
     *   The SSH connection URI.
     * @param int $exitCode
     *   The exit code from the SSH command.
     * @param Process|null $failedProcess
     *   The failed SSH process. Another SSH command will run automatically if a process is not available.
     * @param bool $blankLine
     *   Whether to output a blank line first if anything will be printed.
     */
    public function diagnoseFailure($uri, $exitCode, Process $failedProcess = null, $blankLine = true)
    {
        if ($exitCode !== self::_SSH_ERROR_EXIT_CODE) {
            return;
        }
        // Only check when the SSH URI matches an internal SSH host.
        if (!$this->sshHostIsInternal($uri)) {
            return;
        }

        $executable = $this->config->get('application.executable');

        if ($this->connectionFailedDueToMFA($uri, $failedProcess)) {
            if ($blankLine) {
                $this->stdErr->writeln('');
            }

            if (!$this->certifier->getExistingCertificate() && !$this->certifier->isAutoLoadEnabled()) {
                $this->stdErr->writeln('The SSH connection failed. An SSH certificate is required.');
                $this->stdErr->writeln(sprintf('Generate one using: <comment>%s ssh-cert:load</comment>', $executable));
                return;
            }

            $this->stdErr->writeln('The SSH connection failed because access requires MFA (multi-factor authentication).');

            if (!$this->config->getWithDefault('api.auth', false)) {
                if ($this->config->has('api.mfa_setup_url')) {
                    $this->stdErr->writeln(\sprintf(
                        'Ensure that MFA is enabled on your account. Set it up at: <comment>%s</comment>',
                        $this->config->get('api.mfa_setup_url')
                    ));
                    $this->stdErr->writeln(\sprintf('Then log in again with: <comment>%s login -f</comment>', $executable));
                } else {
                    $this->stdErr->writeln(\sprintf('Log in again with: <comment>%s login -f</comment>', $executable));
                }
            } elseif ($this->api->getUser()->mfa_enabled) {
                $this->stdErr->writeln('MFA is currently enabled on your account, but reverification is required.');
                $this->stdErr->writeln(\sprintf('Log in again with: <comment>%s login -f</comment>', $executable));
            } else {
                $this->stdErr->writeln('MFA is not yet enabled on your account.');
                if ($this->config->has('api.mfa_setup_url')) {
                    $this->stdErr->writeln(\sprintf('Set up MFA at: <comment>%s</comment>', $this->config->get('api.mfa_setup_url')));
                }
                $this->stdErr->writeln(\sprintf('Then log in again with: <comment>%s login -f</comment>', $executable));
            }
            return;
        }

        if ($this->authenticationSucceeded($uri, $failedProcess)) {
            return;
        }

        if ($this->hostKeyVerificationFailed($uri, $failedProcess)) {
            if ($blankLine) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln('SSH was unable to verify the host key.');
            if ($host = $this->getHost($uri)) {
                $this->stdErr->writeln('In non-interactive environments, you can install the host key with:');
                $this->stdErr->writeln('ssh-keyscan ' . \escapeshellarg($host) . ' >> $HOME/.ssh/known_hosts');
                if (\strpos($host, 'ssh.') === 0) {
                    $this->stdErr->writeln('ssh-keyscan ' . \escapeshellarg('git.' . \substr($host, 4)) . ' >> $HOME/.ssh/known_hosts');
                } elseif (\strpos($host, 'git.') === 0) {
                    $this->stdErr->writeln('ssh-keyscan ' . \escapeshellarg('ssh.' . \substr($host, 4)) . ' >> $HOME/.ssh/known_hosts');
                }
            }
            return;
        }

        $environment = $this->getEnvironment($uri);
        if (!$environment) {
            $this->stdErr->writeln('Failed to load environment from SSH URI: ' . $uri, OutputInterface::VERBOSITY_DEBUG);
            return;
        } elseif ($environment->refresh() && $environment->status !== 'active') {
            $this->stdErr->writeln(\sprintf('The environment %s is not currently active. Please try again later.', $this->api->getEnvironmentLabel($environment, 'comment')));
            $this->stdErr->writeln(\sprintf('View recent activities with: <comment>%s activities</comment>', $executable));
            return;
        }

        if (!$this->sshKey->hasLocalKey()) {
            if ($blankLine) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln(sprintf(
                'You probably need to add an SSH key, with: <comment>%s ssh-key:add</comment>',
                $executable
            ));
            return;
        }
    }
}
