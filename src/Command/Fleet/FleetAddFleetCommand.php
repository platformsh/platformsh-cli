<?php

namespace Platformsh\Cli\Command\Fleet;


use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Fleets;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class FleetAddFleetCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('fleet:add')
            ->setDescription('Add a fleet to this project')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the fleet');
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $this->debug('Loading fleet configuration');
        /* @var $fleetConfig \Platformsh\Cli\Service\Fleets */
        $fleetConfig = $this->getService('fleets');

        $result = $fleetConfig->addFleet($input->getArgument('name'));

        if($result == Fleets::FLEET_ADDED) {
            $this->stdErr->writeln('Added new fleet: ' . $input->getArgument('name'));
        }
        elseif ($result == Fleets::FLEET_ALREADY_EXISTS) {
            $this->stdErr->writeln('The fleet ' . $input->getArgument('name') . ' already exists.');
        }
        else {
            $this->stdErr->writeln('The fleet ' . $input->getArgument('name') . ' could not be added.');
        };

    }


}
