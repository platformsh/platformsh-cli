<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectClearBuildCacheCommand extends CommandBase
{
    protected static $defaultName = 'project:clear-build-cache';

    private $api;
    private $selector;

    public function __construct(Api $api, Selector $selector) {
        $this->api = $api;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription("Clear a project's build cache");
        $this->selector->addProjectOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();
        $project->clearBuildCache();
        $this->stdErr->writeln('The build cache has been cleared on the project: ' . $this->api->getProjectLabel($project));

        return 0;
    }
}
