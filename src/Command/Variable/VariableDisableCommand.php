<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use "variable:update --enabled false" instead
 */
class VariableDisableCommand extends CommandBase
{
    protected static $defaultName = 'variable:disable';

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
        $this->addArgument('name', InputArgument::REQUIRED, 'The name of the variable')
            ->setDescription('Disable an enabled environment-level variable');
        $this->setHidden(true);

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return $this->subCommandRunner->run('variable:update', [
            'name' => $input->getArgument('name'),
            '--enabled' => 'false',
        ]);
    }
}
