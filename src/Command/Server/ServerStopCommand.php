<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Server;

use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ServerStopCommand extends ServerCommandBase
{
    protected static $defaultName = 'server:stop';

    private $localProject;

    public function __construct(
        Config $config,
        LocalProject $localProject
    )
    {
        $this->localProject = $localProject;
        parent::__construct($config, $localProject);
    }

    protected function configure()
    {
        $this->setDescription('Stop local project web server(s)')
          ->addOption('all', 'a', InputOption::VALUE_NONE, 'Stop all servers');
    }

    public function isEnabled()
    {
        if (!extension_loaded('posix')) {
            return false;
        }

        return parent::isEnabled();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->localProject->getProjectRoot();
        $all = $input->getOption('all');
        if (!$all && !$projectRoot) {
            throw new RootNotFoundException('Specify --all to stop all servers, or go to a project directory');
        }

        $servers = $this->getServerInfo();
        if (!$servers) {
            $this->stdErr->writeln('No servers are running');
            return 1;
        } elseif (!$all) {
            $servers = array_filter($servers, function ($server) use ($projectRoot) {
                return $server['projectRoot'] === $projectRoot;
            });
            if (!$servers) {
                $this->stdErr->writeln('No servers are running for this project. Specify --all to stop all servers.');
                return 1;
            }
        }

        foreach ($servers as $address => $server) {
            $this->stdErr->writeln(sprintf('Stopping server at address %s, PID %s', $address, $server['pid']));
            $this->stopServer($address, $server['pid']);
        }

        return 0;
    }
}
