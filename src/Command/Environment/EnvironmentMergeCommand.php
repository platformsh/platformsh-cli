<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentMergeCommand extends CommandBase
{
    protected static $defaultName = 'environment:merge';

    private $api;
    private $activityService;
    private $questionHelper;
    private $selector;

    public function __construct(
        Api $api,
        ActivityService $activityService,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->api = $api;
        $this->activityService = $activityService;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['merge'])
            ->setDescription('Merge an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment to merge');

        $definition = $this->getDefinition();
        $this->selector->addEnvironmentOption($definition);
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);

        $this->addExample('Merge the environment "sprint-2" into its parent', 'sprint-2');
        $this->setHelp(
            'This command will initiate a Git merge of the specified environment into its parent environment.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selectedEnvironment = $this->selector->getSelection($input)->getEnvironment();
        $environmentId = $selectedEnvironment->id;

        if (!$selectedEnvironment->operationAvailable('merge', true)) {
            $this->stdErr->writeln(sprintf(
                "Operation not available: The environment <error>%s</error> can't be merged.",
                $environmentId
            ));

            if ($selectedEnvironment->parent === null) {
                $this->stdErr->writeln('The environment does not have a parent.');
            } elseif ($selectedEnvironment->is_dirty) {
                $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
            }

            return 1;
        }

        $parentId = $selectedEnvironment->parent;

        $confirmText = sprintf(
            'Are you sure you want to merge <info>%s</info> into its parent, <info>%s</info>?',
            $environmentId,
            $parentId
        );
        if (!$this->questionHelper->confirm($confirmText)) {
            return 1;
        }

        $this->stdErr->writeln(sprintf(
            'Merging <info>%s</info> into <info>%s</info>',
            $environmentId,
            $parentId
        ));

        $this->api->clearEnvironmentsCache($selectedEnvironment->project);

        $activity = $selectedEnvironment->merge();
        if ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitAndLog(
                $activity,
                'Merge complete',
                'Merge failed'
            );
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
