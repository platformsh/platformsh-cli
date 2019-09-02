<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Server;

use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ServerListCommand extends ServerCommandBase
{
    protected static $defaultName = 'server:list';

    private $localProject;
    private $table;

    public function __construct(
        Config $config,
        LocalProject $localProject,
        Table $table
    )
    {
        $this->table = $table;
        $this->localProject = $localProject;
        parent::__construct($config, $localProject);
    }

    protected function configure()
    {
        $this->setAliases(['servers'])
          ->setDescription('List running local project web server(s)')
          ->addOption('all', 'a', InputOption::VALUE_NONE, 'List all servers');
        $this->table->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $servers = $this->getServerInfo();
        if (!$servers) {
            $this->stdErr->writeln('No servers are running');
            return 1;
        }

        $projectRoot = $this->localProject->getProjectRoot();
        $all = $input->getOption('all');
        if (!$all && $projectRoot) {
            $servers = array_filter($servers, function ($server) use ($projectRoot) {
                return $server['projectRoot'] === $projectRoot;
            });
            if (!$servers) {
                $this->stdErr->writeln('No servers are running for this project. Specify --all to view all servers.');
                return 1;
            }
        }

        $headers = ['Address', 'PID', 'App', 'Project root', 'Log'];
        $rows = [];
        foreach ($servers as $address => $server) {
            $row = [$address, $server['pid'], $server['appId'], $server['projectRoot']];
            $logFile = ltrim(str_replace($server['projectRoot'], '', $server['logFile']), '/');
            $row[] = $logFile;
            $rows[] = $row;
        }
        $this->table->render($rows, $headers);

        return 0;
    }
}
