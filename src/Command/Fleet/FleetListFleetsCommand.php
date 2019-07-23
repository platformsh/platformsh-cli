<?php

namespace Platformsh\Cli\Command\Fleet;


use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Platformsh\Cli\Console\AdaptiveTableCell;

class FleetListFleetsCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('fleet:list')
            ->setDescription('List all fleets');
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $this->debug('Loading fleet configuration');
        /* @var $fleetConfig \Platformsh\Cli\Service\Fleets */
        $fleetConfig = $this->getService('fleets');
        $fleets = $fleetConfig->getFleetConfiguration();

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $machineReadable = $table->formatIsMachineReadable();

        // Display a message if no projects are found.
        if (empty($fleets)) {
            $this->stdErr->writeln(
                'You do not have any fleets yet.'
            );

            return 0;
        }

        $rows = [];
        foreach ($fleets as $fleetName => $fleetSettings) {

            $count = count($fleetSettings['projects']);
            $project_list = implode(", ", $fleetSettings['projects']);

            $rows[] = [
                new AdaptiveTableCell($fleetName, ['wrap' => false]),
                new AdaptiveTableCell($count),
                new AdaptiveTableCell($project_list)
            ];
        }

        $header = ['ID', 'No. of Projects', 'Project IDs'];

        // Display a simple table (and no messages) if the --format is
        // machine-readable (e.g. csv or tsv).
        if ($machineReadable) {
            $table->render($rows, $header);

            return 0;
        }

        // Display the projects.
        if (empty($filters)) {
            $this->stdErr->writeln('Fleets attached to this project: ');
        }

        $table->render($rows, $header);

    }
}
