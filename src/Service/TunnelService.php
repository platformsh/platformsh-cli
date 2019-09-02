<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Console\Selection;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Process\Process;

class TunnelService
{
    const LOCAL_IP = '127.0.0.1';
    const STATE_KEY = 'tunnel-info';

    private $config;
    private $localProject;
    private $output;
    private $state;
    private $stdErr;

    public function __construct(
        Config $config,
        LocalProject $localProject,
        OutputInterface $output,
        State $state
    ) {
        $this->config = $config;
        $this->localProject = $localProject;
        $this->output = $output;
        $this->state = $state;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * @param string $message
     */
    private function debug($message)
    {
        $this->stdErr->writeln('<options=reverse>DEBUG</> ' . $message, OutputInterface::VERBOSITY_DEBUG);
    }

    /**
     * Check whether a tunnel is already open.
     *
     * @param array $tunnel
     *
     * @return bool|array
     */
    public function isTunnelOpen(array $tunnel)
    {
        foreach ($this->getTunnelInfo() as $info) {
            if ($this->tunnelsAreEqual($tunnel, $info)) {
                /** @noinspection PhpComposerExtensionStubsInspection */
                if (isset($info['pid']) && function_exists('posix_kill') && !posix_kill($info['pid'], 0)) {
                    $this->debug(sprintf(
                        'The tunnel at port %d is no longer open, removing from list',
                        $info['localPort']
                    ));
                    $this->closeTunnel($info);
                    continue;
                }

                return $info;
            }
        }

        return false;
    }

    /**
     * Get info on currently open tunnels.
     *
     * @param bool $open
     *
     * @return array
     */
    public function getTunnelInfo($open = true)
    {
        $tunnelInfo = $this->state->get(self::STATE_KEY) ?: [];

        if ($open) {
            $needsSave = false;
            foreach ($tunnelInfo as $key => $tunnel) {
                /** @noinspection PhpComposerExtensionStubsInspection */
                if (isset($tunnel['pid']) && function_exists('posix_kill') && !posix_kill($tunnel['pid'], 0)) {
                    $this->debug(sprintf(
                        'The tunnel at port %d is no longer open, removing from list',
                        $tunnel['localPort']
                    ));
                    unset($tunnelInfo[$key]);
                    $needsSave = true;
                }
            }
            if ($needsSave) {
                $this->state->set(self::STATE_KEY, $tunnelInfo);
            }
        }

        return $tunnelInfo;
    }

    public function addTunnelInfo(array $tunnelInfo)
    {
        $currentInfo = $this->getTunnelInfo(false);
        $currentInfo[] = $tunnelInfo;
        $this->state->set(self::STATE_KEY, $currentInfo);
    }

    /**
     * Close an open tunnel.
     *
     * @param array $tunnel
     *
     * @return bool
     *   True on success, false on failure.
     */
    public function closeTunnel(array $tunnel)
    {
        $success = true;
        if (isset($tunnel['pid']) && function_exists('posix_kill')) {
            /** @noinspection PhpComposerExtensionStubsInspection */
            $success = posix_kill($tunnel['pid'], SIGTERM);
            if (!$success) {
                /** @noinspection PhpComposerExtensionStubsInspection */
                $this->stdErr->writeln(sprintf(
                    'Failed to kill process <error>%d</error> (POSIX error %s)',
                    $tunnel['pid'],
                    posix_get_last_error()
                ));
            }
        }
        $pidFile = $this->getPidFile($tunnel);
        if (file_exists($pidFile)) {
            $success = unlink($pidFile) && $success;
        }
        $tunnelInfo = $this->getTunnelInfo(false);
        $needsSave = false;
        foreach ($tunnelInfo as $key => $info) {
            if ($this->tunnelsAreEqual($info, $tunnel)) {
                unset($tunnelInfo[$key]);
                $needsSave = true;
            }
        }
        if ($needsSave) {
            $this->state->set(self::STATE_KEY, $tunnelInfo);
        }

        return $success;
    }

    /**
     * Automatically determine the best port for a new tunnel.
     *
     * @param int $default
     *
     * @return int
     */
    public function getPort($default = 30000)
    {
        $ports = [];
        foreach ($this->getTunnelInfo() as $tunnel) {
            $ports[] = $tunnel['localPort'];
        }

        return PortUtil::getPort($ports ? max($ports) + 1 : $default);
    }

    /**
     * @param string $logFile
     *
     * @return OutputInterface|false
     */
    public function openLog($logFile)
    {
        $logResource = fopen($logFile, 'a');
        if ($logResource) {
            return new StreamOutput($logResource);
        }

        return false;
    }

    /**
     * @param array $tunnel
     *
     * @return string
     */
    protected function getTunnelKey(array $tunnel)
    {
        return implode('--', [
            $tunnel['projectId'],
            $tunnel['environmentId'],
            $tunnel['appName'],
            $tunnel['relationship'],
            $tunnel['serviceKey'],
        ]);
    }

    /**
     * @param array $tunnel1
     * @param array $tunnel2
     *
     * @return bool
     */
    protected function tunnelsAreEqual(array $tunnel1, array $tunnel2)
    {
        return $this->getTunnelKey($tunnel1) === $this->getTunnelKey($tunnel2);
    }

    /**
     * @param array $tunnel
     *
     * @return string
     */
    public function getPidFile(array $tunnel)
    {
        $key = $this->getTunnelKey($tunnel);
        $dir = $this->config->getWritableUserDir() . '/.tunnels';
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Failed to create directory: ' . $dir);
        }

        return $dir . '/' . preg_replace('/[^0-9a-z\.]+/', '-', $key) . '.pid';
    }

    /**
     * @param string $url
     * @param string $remoteHost
     * @param int $remotePort
     * @param int $localPort
     * @param array $extraArgs
     *
     * @return \Symfony\Component\Process\Process
     */
    public function createTunnelProcess($url, $remoteHost, $remotePort, $localPort, array $extraArgs = [])
    {
        $args = ['ssh', '-n', '-N', '-L', implode(':', [$localPort, $remoteHost, $remotePort]), $url];
        $args = array_merge($args, $extraArgs);
        $process = new Process($args);
        $process->setTimeout(null);

        return $process;
    }

    /**
     * Filter a list of tunnels by the currently selected project/environment.
     *
     * @param array     $tunnels
     * @param Selection $selection
     *
     * @return array
     */
    public function filterTunnels(array $tunnels, Selection $selection)
    {
        if (!$selection->hasProject() && !$this->localProject->getProjectRoot()) {
            return $tunnels;
        }

        $project = $selection->getProject();
        $environment = $selection->hasEnvironment() ? $selection->getEnvironment() : null;
        $appName = $selection->getAppName();
        foreach ($tunnels as $key => $tunnel) {
            if ($tunnel['projectId'] !== $project->id
                || ($environment !== null && $tunnel['environmentId'] !== $environment->id)
                || ($appName !== null && $tunnel['appName'] !== $appName)) {
                unset($tunnels[$key]);
            }
        }

        return $tunnels;
    }

    /**
     * Format a tunnel's relationship as a string.
     *
     * @param array $tunnel
     *
     * @return string
     */
    public function formatTunnelRelationship(array $tunnel)
    {
        return $tunnel['serviceKey'] > 0
            ? sprintf('%s.%d', $tunnel['relationship'], $tunnel['serviceKey'])
            : $tunnel['relationship'];
    }
}
