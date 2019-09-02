<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSynchronizeCommand extends CommandBase implements CompletionAwareInterface
{
    protected static $defaultName = 'environment:synchronize';

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
        $this->setAliases(['sync'])
            ->setDescription("Synchronize an environment's code and/or data from its parent")
            ->addArgument('synchronize', InputArgument::IS_ARRAY, 'What to synchronize: "code", "data" or both')
            ->addOption('rebase', null, InputOption::VALUE_NONE, 'Synchronize code by rebasing instead of merging');

        $definition = $this->getDefinition();
        $this->selector->addEnvironmentOption($definition);
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);

        $this->setHelp(<<<EOT
This command synchronizes to a child environment from its parent environment.

Synchronizing "code" means there will be a Git merge from the parent to the
child. Synchronizing "data" means that all files in all services (including
static files, databases, logs, search indices, etc.) will be copied from the
parent to the child.
EOT
        );
        $this->addExample('Synchronize data from the parent environment', 'data');
        $this->addExample('Synchronize code and data from the parent environment', 'code data');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $selectedEnvironment = $selection->getEnvironment();
        $environmentId = $selectedEnvironment->id;

        if (!$selectedEnvironment->operationAvailable('synchronize', true)) {
            $this->stdErr->writeln(
                "Operation not available: The environment <error>$environmentId</error> can't be synchronized."
            );

            if ($selectedEnvironment->parent === null) {
                $this->stdErr->writeln('The environment does not have a parent.');
            } elseif ($selectedEnvironment->is_dirty) {
                $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
            }

            return 1;
        }

        $parentId = $selectedEnvironment->parent;

        $rebase = (bool) $input->getOption('rebase');

        if ($synchronize = $input->getArgument('synchronize')) {
            // The input was invalid.
            if (array_diff($input->getArgument('synchronize'), ['code', 'data', 'both'])) {
                $this->stdErr->writeln("Specify 'code', 'data', or 'both'");
                return 1;
            }
            $syncCode = in_array('code', $synchronize) || in_array('both', $synchronize);
            $syncData = in_array('data', $synchronize) || in_array('both', $synchronize);

            if ($rebase && !$syncCode) {
                $this->stdErr->writeln('<comment>Note:</comment> you specified the <comment>--rebase</comment> option, but this only applies to synchronizing code, which you have not selected.');
                $this->stdErr->writeln('');
            }

            $toSync = $syncCode && $syncData
                ? '<options=underscore>code</> and <options=underscore>data</>'
                : '<options=underscore>' . ($syncCode ? 'code' : 'data') . '</>';

            $confirmText = sprintf(
                'Are you sure you want to synchronize %s from <info>%s</info> to <info>%s</info>?',
                $toSync,
                $parentId,
                $environmentId
            );
            if (!$this->questionHelper->confirm($confirmText)) {
                return 1;
            }
        } else {
            $syncCode = $this->questionHelper->confirm(
                "Do you want to synchronize <options=underscore>code</> from <info>$parentId</info> to <info>$environmentId</info>?",
                false
            );

            if ($syncCode && !$rebase) {
                $rebase = $this->questionHelper->confirm(
                    "Do you want to synchronize code by rebasing instead of merging?",
                    false
                );
            }

            if ($rebase && !$syncCode) {
                $this->stdErr->writeln('<comment>Note:</comment> you specified the <comment>--rebase</comment> option, but this only applies to synchronizing code.');
            }

            $this->stdErr->writeln('');

            $syncData = $this->questionHelper->confirm(
                "Do you want to synchronize <options=underscore>data</> from <info>$parentId</info> to <info>$environmentId</info>?",
                false
            );
        }
        if (!$syncCode && !$syncData) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You did not select anything to synchronize.');

            return 1;
        }

        $this->stdErr->writeln("Synchronizing environment <info>$environmentId</info>");

        $activity = $selectedEnvironment->synchronize($syncData, $syncCode, $rebase);
        if ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitAndLog(
                $activity,
                "Synchronization complete",
                "Synchronization failed"
            );
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        if ($argumentName === 'synchronize') {
            return ['code', 'data', 'both'];
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function completeOptionValues($argumentName, CompletionContext $context)
    {
        return [];
    }
}
