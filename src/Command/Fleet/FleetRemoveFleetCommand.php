<?php

namespace Platformsh\Cli\Command\Fleet;

use Platformsh\Cli\Service\Fleets;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


use Platformsh\Cli\Command\CommandBase;

class FleetRemoveFleetCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('fleet:remove')
            ->setDescription('Remove a fleet')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the fleet');
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $this->debug('Loading fleet configuration');
        /* @var $fleetConfig \Platformsh\Cli\Service\Fleets */
        $fleetConfig = $this->getService('fleets');

        $result = $fleetConfig->removeFleet($input->getArgument('name'));

        if($result == Fleets::FLEET_REMOVED) {
            $this->stdErr->writeln('Removed fleet: ' . $input->getArgument('name'));
        }
        elseif($result == Fleets::FLEET_DOES_NOT_EXIST) {
            $this->stdErr->writeln('The fleet ' . $input->getArgument('name') . ' was not found.');
        }
        else {
            $this->stdErr->writeln('The fleet ' . $input->getArgument('name') . 'could not be removed');
        };

    }
}
