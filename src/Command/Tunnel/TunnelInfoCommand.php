<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\TunnelService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelInfoCommand extends CommandBase
{
    protected static $defaultName = 'tunnel:info';

    private $config;
    private $formatter;
    private $selector;
    private $tunnelService;

    public function __construct(
        Config $config,
        PropertyFormatter $formatter,
        Selector $selector,
        TunnelService $tunnelService
    ) {
        $this->config = $config;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->tunnelService = $tunnelService;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription("View relationship info for SSH tunnels")
          ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The relationship property to view')
          ->addOption('encode', 'c', InputOption::VALUE_NONE, 'Output as base64-encoded JSON');

        $this->selector->addAllOptions($this->getDefinition());
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
        $selection = $this->selector->getSelection($input);
        $tunnels = $this->tunnelService->getTunnelInfo();

        $relationships = [];
        foreach ($this->tunnelService->filterTunnels($tunnels, $selection) as $key => $tunnel) {
            $service = $tunnel['service'];

            // Overwrite the service's address with the local tunnel details.
            $service = array_merge($service, array_intersect_key([
                'host' => TunnelService::LOCAL_IP,
                'ip' => TunnelService::LOCAL_IP,
                'port' => $tunnel['localPort'],
            ], $service));

            $relationships[$tunnel['relationship']][$tunnel['serviceKey']] = $service;
        }
        if (!count($relationships)) {
            $this->stdErr->writeln('No tunnels found.');

            if (count($tunnels) > count($relationships)) {
                $this->stdErr->writeln(sprintf(
                    'List all tunnels with: <info>%s tunnels --all</info>',
                    $this->config->get('application.executable')
                ));
            }

            return 1;
        }

        if ($input->getOption('encode')) {
            if ($input->getOption('property')) {
                $this->stdErr->writeln('You cannot combine --encode with --property.');
                return 1;
            }

            $output->writeln(base64_encode(json_encode($relationships)));
            return 0;
        }

        $this->formatter->displayData($output, $relationships, $input->getOption('property'));

        return 0;
    }
}
