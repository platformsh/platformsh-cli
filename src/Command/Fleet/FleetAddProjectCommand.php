<?php


namespace Platformsh\Cli\Command\Fleet;


use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Fleets;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FleetAddProjectCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('fleet:project-add')
            ->setDescription('Add a project to a fleet')
            ->addArgument('fleet', InputArgument::REQUIRED, 'Fleet name')
            ->addArgument('project-id', InputArgument::REQUIRED, 'Project identifier')
            ->addExample('Add the project "abc123" to "my-fleet"', 'my-fleet abc123');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $this->debug('Loading fleet configuration');
        /* @var $fleetService \Platformsh\Cli\Service\Fleets */
        $fleetService = $this->getService('fleets');

        $fleet = $input->getArgument('fleet');
        $id = $input->getArgument('project-id');

        $result = $fleetService->addProject($fleet, $id);

        if ($result == Fleets::FLEET_DOES_NOT_EXIST) {
            $this->stdErr->writeln('The fleet ' . $fleet . ' does not exist. Please add it before adding projects.');
        }
        elseif ($result == Fleets::PROJECT_ADDED) {
            $this->stdErr->writeln('Added project ' . $id . ' to fleet ' . $fleet);
        }
        elseif ($result == Fleets::PROJECT_ALREADY_EXISTS) {
            $this->stdErr->writeln('Project ' . $id . ' already exists in the fleet ' . $fleet);
        }
        else {
            $this->stdErr->writeln('Could not add project');
        };

    }
}
