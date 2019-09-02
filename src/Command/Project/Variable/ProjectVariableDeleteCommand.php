<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:delete instead
 */
class ProjectVariableDeleteCommand extends CommandBase
{
    protected static $defaultName = 'project:variable:delete';

    private $activityService;
    private $selector;
    private $subCommandRunner;

    public function __construct(
        ActivityService $activityService,
        Selector $selector,
        SubCommandRunner $subCommandRunner
    ) {
        $this->activityService = $activityService;
        $this->selector = $selector;
        $this->subCommandRunner = $subCommandRunner;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->setDescription('Delete a variable from a project');
        $this->setHidden(true);
        $this->selector->addProjectOption($this->getDefinition());
        $this->activityService->configureInput($this->getDefinition());
        $this->addExample('Delete the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return $this->subCommandRunner->run('variable:delete', [
            'name' => $input->getArgument('name'),
            '--level' => 'project'
        ]);
    }
}
