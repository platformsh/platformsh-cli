<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Service\TunnelService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelListCommand extends CommandBase
{
    protected static $defaultName = 'tunnel:list';

    private $config;
    private $formatter;
    private $selector;
    private $table;
    private $tunnelService;

    public function __construct(
        Config $config,
        PropertyFormatter $formatter,
        Selector $selector,
        Table $table,
        TunnelService $tunnelService
    ) {
        $this->config = $config;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->table = $table;
        $this->tunnelService = $tunnelService;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['tunnels'])
          ->setDescription('List SSH tunnels')
          ->addOption('all', 'a', InputOption::VALUE_NONE, 'View all tunnels');

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition);
        $this->table->configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    public function canBeRunMultipleTimes(): bool
    {
        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tunnels = $this->tunnelService->getTunnelInfo();
        $allTunnelsCount = count($tunnels);
        if (!$allTunnelsCount) {
            $this->stdErr->writeln('No tunnels found.');
            return 1;
        }

        $executable = $this->config->get('application.executable');

        // Filter tunnels according to the current project and environment, if
        // available.
        if (!$input->getOption('all')) {
            $tunnels = $this->tunnelService->filterTunnels($tunnels, $this->selector->getSelection($input));
            if (!count($tunnels)) {
                $this->stdErr->writeln('No tunnels found.');
                $this->stdErr->writeln(sprintf(
                    'List all tunnels with: <info>%s tunnels --all</info>',
                    $executable
                ));

                return 1;
            }
        }

        $headers = ['Port', 'Project', 'Environment', 'App', 'Relationship'];
        $rows = [];
        foreach ($tunnels as $tunnel) {
            $rows[] = [
                $tunnel['localPort'],
                $tunnel['projectId'],
                $tunnel['environmentId'],
                $tunnel['appName'] ?: '[default]',
                $this->tunnelService->formatTunnelRelationship($tunnel),
            ];
        }
        $this->table->render($rows, $headers);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->get('application.executable');
            $this->stdErr->writeln('');

            if (!$input->getOption('all') && count($tunnels) < $allTunnelsCount) {
                $this->stdErr->writeln(sprintf(
                    'List all tunnels with: <info>%s tunnels --all</info>',
                    $executable
                ));
            }

            $this->stdErr->writeln([
                "View tunnel details with: <info>$executable tunnel:info</info>",
                "Close tunnels with: <info>$executable tunnel:close</info>",
            ]);
        }

        return 0;
    }
}
