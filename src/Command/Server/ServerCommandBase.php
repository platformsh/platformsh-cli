<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Server;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

abstract class ServerCommandBase extends CommandBase
{
    protected $serverInfo;

    private $config;
    private $localProject;

    public function __construct(
        Config $config,
        LocalProject $localProject
    )
    {
        $this->config = $config;
        $this->localProject = $localProject;
        parent::__construct();
    }

    public function isEnabled()
    {
        return $this->config->isExperimentEnabled('enable_local_server')
            && parent::isEnabled();
    }

    /**
     * Check whether another server is running for an app.
     *
     * @param string $appId
     * @param string $projectRoot
     *
     * @return bool|array
     */
    protected function isServerRunningForApp($appId, $projectRoot)
    {
        foreach ($this->getServerInfo() as $address => $server) {
            if ($server['appId'] === $appId && $server['projectRoot'] === $projectRoot) {
                if ($this->isProcessDead($server['pid'])) {
                    $this->stopServer($address);
                    continue;
                }
                return $server;
            }
        }

        return false;
    }

    /**
     * Check whether a process is no longer running.
     *
     * @param int $pid
     *
     * @return bool
     */
    protected function isProcessDead($pid)
    {
        /** @noinspection PhpComposerExtensionStubsInspection */
        return function_exists('posix_kill') && !posix_kill($pid, 0);
    }

    /**
     * Check whether another server is running at an address.
     *
     * @param string $address
     *
     * @return bool|int
     */
    protected function isServerRunningForAddress($address)
    {
        $pidFile = $this->getPidFile($address);
        $serverInfo = $this->getServerInfo();
        if (file_exists($pidFile)) {
            $pid = file_get_contents($pidFile);
        } elseif (isset($serverInfo[$address])) {
            $pid = $serverInfo[$address]['pid'];
        }

        if (!empty($pid) && !$this->isProcessDead($pid)) {
            return $pid;
        } elseif (!empty($pid)) {
            // The PID is no longer valid. Delete the lock file and
            // continue.
            $this->stopServer($address);
        }

        list($hostname, $port) = explode(':', $address);

        return PortUtil::isPortInUse($port, $hostname);
    }

    /**
     * Get info on currently running servers.
     *
     * @param bool $running
     *
     * @return array
     */
    protected function getServerInfo($running = true)
    {
        if (!isset($this->serverInfo)) {
            $this->serverInfo = [];
            // @todo move this to State service (in a new major version)
            $filename = $this->config->getWritableUserDir() . '/local-servers.json';
            if (file_exists($filename)) {
                $this->serverInfo = (array) json_decode(file_get_contents($filename), true);
            }
        }

        if ($running) {
            return array_filter($this->serverInfo, function ($server) {
                if ($this->isProcessDead($server['pid'])) {
                    $this->stopServer($server['address']);
                    return false;
                }

                return true;
            });
        }

        return $this->serverInfo;
    }

    protected function saveServerInfo()
    {
        $filename = $this->config->getWritableUserDir() . '/local-servers.json';
        if (!empty($this->serverInfo)) {
            if (!file_put_contents($filename, json_encode($this->serverInfo))) {
                throw new \RuntimeException('Failed to write server info to: ' . $filename);
            }
        } else {
            unlink($filename);
        }
    }

    /**
     * Stop a running server.
     *
     * @param string $address
     * @param int|null $pid
     *
     * @return bool
     *   True on success, false on failure.
     */
    protected function stopServer($address, $pid = null)
    {
        $success = true;
        if ($pid && function_exists('posix_kill')) {
            /** @noinspection PhpComposerExtensionStubsInspection */
            $success = posix_kill($pid, SIGTERM);
            if (!$success) {
                /** @noinspection PhpComposerExtensionStubsInspection */
                $this->stdErr->writeln(sprintf(
                    'Failed to kill process <error>%d</error> (POSIX error %s)',
                    $pid,
                    posix_get_last_error()
                ));
            }
        }
        $pidFile = $this->getPidFile($address);
        if (file_exists($pidFile)) {
            $success = unlink($pidFile) && $success;
        }
        unset($this->serverInfo[$address]);
        $this->saveServerInfo();

        return $success;
    }

    /**
     * @param string $address
     * @param int $pid
     * @param array $info
     */
    protected function writeServerInfo($address, $pid, array $info = [])
    {
        file_put_contents($this->getPidFile($address), $pid);
        list($ip, $port) = explode(':', $address);
        $this->serverInfo[$address] = $info + [
            'address' => $address,
            'pid' => $pid,
            'port' => $port,
            'ip' => $ip,
        ];
        $this->saveServerInfo();
    }

    /**
     * Automatically determine the best port for a new server.
     *
     * @param int $default
     *
     * @return int
     */
    protected function getPort($default = 3000)
    {
        $ports = [];
        foreach ($this->getServerInfo() as $address => $server) {
            $ports[] = $server['port'];
        }

        return PortUtil::getPort($ports ? max($ports) + 1 : $default);
    }

    /**
     * Determine the name of the lock file for a particular web server process.
     *
     * @param string $address An address/port tuple
     *
     * @return string The filename
     */
    protected function getPidFile($address)
    {
        return $this->config->getWritableUserDir() . '/server-' . preg_replace('/\W+/', '-', $address) . '.pid';
    }

    /**
     * Creates a process to start a web server.
     *
     * @param string $address
     * @param string $docRoot
     * @param string $projectRoot
     * @param array $appConfig
     * @param array $env
     *
     * @throws \Exception
     *
     * @return Process
     */
    protected function createServerProcess($address, $docRoot, $projectRoot, array $appConfig, array $env = [])
    {
        if (isset($appConfig['type'])) {
            $type = explode(':', $appConfig['type'], 2);
            $version = isset($type[1]) ? $type[1] : false;
            if ($type[0] === 'php' && $version && version_compare(PHP_VERSION, $version, '<')) {
                $this->stdErr->writeln(sprintf(
                    '<comment>Warning:</comment> your local PHP version is %s, but the app expects %s',
                    PHP_VERSION,
                    $version
                ));
            }
        }

        $arguments = [];

        if (isset($appConfig['web']['commands']['start'])) {
            // Bail out. We can't support custom 'start' commands for now.
            throw new \Exception(
                "Not supported: the CLI doesn't support starting a server with a custom 'start' command"
            );
        }

        $router = $this->createRouter($projectRoot);

        $this->showSecurityWarning();

        $arguments[] = (new PhpExecutableFinder())->find() ?: PHP_BINARY;

        foreach ($this->getServerPhpConfig() as $item => $value) {
            $arguments[] = sprintf('-d %s="%s"', $item, $value);
        }

        $arguments = array_merge($arguments, [
          '-t',
          $docRoot,
          '-S',
          $address,
          $router,
        ]);

        $process = new Process($arguments);
        $process->setTimeout(null);
        $env += $this->createEnv($projectRoot, $docRoot, $address, $appConfig);
        $process->setEnv($env);
        $envPrefix = $this->config->get('service.env_prefix');
        if (isset($env[$envPrefix . 'APP_DIR'])) {
            $process->setWorkingDirectory($env[$envPrefix . 'APP_DIR']);
        }

        return $process;
    }

    /**
     * Get custom PHP configuration for the built-in web server.
     *
     * @return array
     */
    protected function getServerPhpConfig()
    {
        $phpConfig = [];

        // Ensure $_ENV is populated.
        $variables_order = ini_get('variables_order');
        if (strpos($variables_order, 'E') === false) {
            $phpConfig['variables_order'] = 'E' . $variables_order;
        }

        return $phpConfig;
    }

    /**
     * Create a router file.
     *
     * @param string $projectRoot
     *
     * @return string
     *   The absolute path to the router file.
     */
    protected function createRouter($projectRoot)
    {
        static $created = [];

        $router_src = CLI_ROOT . '/resources/router/router.php';
        if (!file_exists($router_src)) {
            throw new \RuntimeException(sprintf('Router not found: <error>%s</error>', $router_src));
        }

        $router = $projectRoot . '/' . $this->config->get('local.local_dir') . '/' . basename($router_src);
        if (!isset($created[$router])) {
            if (!file_put_contents($router, file_get_contents($router_src))) {
                throw new \RuntimeException(sprintf('Could not create router file: <error>%s</error>', $router));
            }
            $created[$router] = true;
        }

        return $router;
    }

    /**
     * @param string $logFile
     *
     * @return OutputInterface|false
     */
    protected function openLog($logFile)
    {
        $logResource = fopen($logFile, 'a');
        if ($logResource) {
            return new StreamOutput($logResource);
        }

        return false;
    }

    /**
     * @param string $projectRoot
     * @param string $address
     *
     * @return array
     */
    protected function getRoutesList($projectRoot, $address)
    {
        $routesConfig = (array) $this->localProject->readProjectConfigFile($projectRoot, 'routes.yaml');

        $routes = [];
        foreach ($routesConfig as $route => $config) {
            // If the route starts with http://{default}, replace it with the
            // $address. This can't accommodate subdomains or HTTPS routes, so
            // those are ignored.
            $url = strpos($route, 'http://{default}') === 0
                ? 'http://' . $address . substr($route, 16)
                : $route;
            if (strpos($url, '{default}') !== false) {
                continue;
            }
            $routes[$url] = $config + ['original_url' => $route];
        }

        return $routes;
    }

    /**
     * Create the virtual environment variables for a local server.
     *
     * @param string $projectRoot
     * @param string $docRoot
     * @param string $address
     * @param array $appConfig
     *
     * @return array
     */
    protected function createEnv($projectRoot, $docRoot, $address, array $appConfig)
    {
        $realDocRoot = realpath($docRoot);
        $envPrefix = $this->config->get('service.env_prefix');
        $env = [
            '_PLATFORM_VARIABLES_PREFIX' => $envPrefix,
            $envPrefix . 'ENVIRONMENT' => '_local',
            $envPrefix . 'APPLICATION' => base64_encode(json_encode($appConfig)),
            $envPrefix . 'APPLICATION_NAME' => isset($appConfig['name']) ? $appConfig['name'] : '',
            $envPrefix . 'DOCUMENT_ROOT' => $realDocRoot,
            $envPrefix . 'ROUTES' => base64_encode(json_encode($this->getRoutesList($projectRoot, $address))),
        ];

        list($env['IP'], $env['PORT']) = explode(':', $address);

        if (dirname($realDocRoot, 2) === $projectRoot . '/' . $this->config->get('local.build_dir')) {
            $env[$envPrefix . 'APP_DIR'] = dirname($realDocRoot);
        }

        if ($projectRoot === $this->localProject->getProjectRoot()) {
            $projectConfig = $this->localProject->getProjectConfig($projectRoot);
            if ($projectConfig && isset($projectConfig['id'])) {
                $env[$envPrefix . 'PROJECT'] = $projectConfig['id'];
            }
        }

        return $env;
    }

    protected function showSecurityWarning()
    {
        static $shown;
        if ($shown) {
            return;
        }
        $this->stdErr->writeln(
            '<comment>Warning:</comment> this uses the PHP built-in web server, which is neither secure nor reliable for production use'
        );
        $shown = true;
    }
}
