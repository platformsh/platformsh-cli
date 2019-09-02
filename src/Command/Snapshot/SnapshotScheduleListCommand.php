<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotScheduleListCommand extends CommandBase
{
    protected static $defaultName = 'snapshot:schedule:list';

    private $api;
    private $config;
    private $table;
    private $selector;

    public function __construct(
        Api $api,
        Config $config,
        Table $table,
        Selector $selector
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->table = $table;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('List scheduled snapshot policies')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'The interval between snapshots', '1h')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'The number of snapshots to keep', 1)
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $selectedEnvironment = $selection->getEnvironment();

        $header = ['Interval', 'Count'];
        $rows = [];

        $policies = $selectedEnvironment->getBackupConfig()->getPolicies();
        if (!$policies) {
            $this->stdErr->writeln('No scheduled snapshot policies found.');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(
                'Add a policy to the environment by running <info>' . $this->config->get('application.executable') . ' snapshot:schedule:add'
            );

            return 1;
        }
        foreach ($selectedEnvironment->getBackupConfig()->getPolicies() as $policy) {
            $rows[] = [$policy->getInterval(), $policy->getCount()];
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(
                sprintf(
                    'Scheduled snapshot policies for the project %s, environment %s:',
                    $this->api->getProjectLabel($selection->getProject()),
                    $this->api->getEnvironmentLabel($selectedEnvironment)
                )
            );
        }

        $this->table->render($rows, $header);

        return 0;
    }
}
