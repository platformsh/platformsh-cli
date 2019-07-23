<?php
/**
 * Created by PhpStorm.
 * User: xtfer
 * Date: 2019-04-02
 * Time: 22:09
 */

namespace Platformsh\Cli\Command\Fleet;

use Platformsh\Cli\Service\Fleets;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FleetRemoveProjectCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('fleet:project-remove')
            ->setDescription('Remove a project from a fleet')
            ->addArgument('fleet', InputArgument::REQUIRED, 'Fleet name')
            ->addArgument('project-id', InputArgument::REQUIRED, 'Project identifier')
            ->addExample('Remove the project "abc123" from "my-fleet"', 'my-fleet abc123');
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $this->debug('Loading fleet configuration');
        /* @var $fleetService \Platformsh\Cli\Service\Fleets */
        $fleetService = $this->getService('fleets');

        $fleet = $input->getArgument('fleet');
        $id = $input->getArgument('project-id');

        $result = $fleetService->removeProject($fleet, $id);

        if ($result == Fleets::FLEET_DOES_NOT_EXIST) {
            $this->stdErr->writeln('The fleet ' . $fleet . ' does not exist. Please add it before adding projects.');
        }
        elseif ($result == Fleets::PROJECT_REMOVED) {
            $this->stdErr->writeln('Removed project ' . $id . ' from the fleet ' . $fleet);
        }
        elseif ($result == Fleets::PROJECT_DOES_NOT_EXIST) {
            $this->stdErr->writeln('Project ' . $id . ' does not exist in the fleet ' . $fleet);
        }
        else {
            $this->stdErr->writeln('Could not remove project');
        };

        $this->debug(print_r($fleet, TRUE));
    }
}
