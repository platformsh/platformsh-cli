<?php

namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotScheduleListCommand extends CommandBase
{

    protected function configure()
    {
        $this->setName('snapshot:schedule:list')
            ->setDescription('List scheduled snapshot policies')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'The interval between snapshots', '1h')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'The number of snapshots to keep', 1)
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment');

        $this->addProjectOption()
            ->addEnvironmentOption();

        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $selectedEnvironment = $this->getSelectedEnvironment();

        $header = ['Interval', 'Count'];
        $rows = [];

        $policies = $selectedEnvironment->getBackupConfig()->getPolicies();
        if (!$policies) {
            $this->stdErr->writeln('No scheduled snapshot policies found.');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(
                'Add a policy to the environment by running <info>' . $this->config()->get('application.executable') . ' snapshot:schedule:add'
            );

            return 1;
        }
        foreach ($selectedEnvironment->getBackupConfig()->getPolicies() as $policy) {
            $rows[] = [$policy->getInterval(), $policy->getCount()];
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(
                sprintf(
                    'Scheduled snapshot policies for the project %s, environment %s:',
                    $this->api()->getProjectLabel($this->getSelectedProject()),
                    $this->api()->getEnvironmentLabel($selectedEnvironment)
                )
            );
        }

        $table->render($rows, $header);

        return 0;
    }
}
