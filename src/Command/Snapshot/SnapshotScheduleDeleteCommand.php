<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\Selection;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Client\Model\Type\Duration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotScheduleDeleteCommand extends CommandBase
{
    protected static $defaultName = 'snapshot:schedule:delete';

    private $activityService;
    private $api;
    private $config;
    private $questionHelper;
    private $selector;
    private $subCommandRunner;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        Config $config,
        QuestionHelper $questionHelper,
        Selector $selector,
        SubCommandRunner $subCommandRunner
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->subCommandRunner = $subCommandRunner;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Delete a scheduled snapshot policy');
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'The interval, of the policy to delete');
        $this->addOption('count', null, InputOption::VALUE_REQUIRED, 'The count, of the policy to delete');

        $definition = $this->getDefinition();

        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $interval = $input->getOption('interval');
        $count = $input->getOption('count');

        $selection = $this->selector->getSelection($input);
        $selectedEnvironment = $selection->getEnvironment();

        $backupConfig = $selectedEnvironment->backups;
        $policies = isset($backupConfig['schedule']) ? $backupConfig['schedule'] : [];

        if (!count($policies)) {
            $this->stdErr->writeln('No scheduled snapshot policies found.');

            return 1;
        }

        $matchingPolicies = [];
        foreach ($policies as $key => $policy) {
            if ($interval !== null
                && (new Duration($interval))->getSeconds() !== (new Duration($policy['interval']))->getSeconds()) {
                continue;
            }
            if ($count !== null && (int) $count !== $policy['count']) {
                continue;
            }
            $matchingPolicies[$key] = $policy;
        }

        if (!count($matchingPolicies)) {
            $this->stdErr->writeln('No matching policies found.');
            $this->listPolicies($selection);

            return 1;
        }

        if (count($matchingPolicies) > 1 && ($interval === null || $count === null)) {
            $this->stdErr->writeln('More than one matching policy found.');
            if ($interval === null && $count === null) {
                $this->stdErr->writeln('Specify <comment>--interval</comment> and <comment>--count</comment> to find the policy to delete.');
            }

            $this->listPolicies($selection);

            return 1;
        }

        $policy = reset($matchingPolicies);
        $policyKey = key($matchingPolicies);
        if (!isset($backupConfig['schedule'][$policyKey])) {
            throw new \RuntimeException('Failed to find key in backup schedule: ' . $policyKey);
        }

        $this->stdErr->writeln(sprintf('Selected policy: interval <comment>%s</comment>, count <comment>%d</comment>', $policy['interval'], $policy['count']));
        if (!$this->questionHelper->confirm('Are you sure you want to delete this snapshot policy?')) {
            return 1;
        }

        // Remove the policy.
        unset($backupConfig['schedule'][$policyKey]);

        // Reset keys so that the schedule is serialized as a JSON array.
        $backupConfig['schedule'] = array_values($backupConfig['schedule']);

        $result = $selectedEnvironment->update([
            'backups' => $backupConfig,
        ]);

        $this->api->clearEnvironmentsCache($selectedEnvironment->project);

        $this->stdErr->writeln('The policy was successfully deleted.');

        $success = true;
        if ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitMultiple(
                $result->getActivities(),
                $selection->getProject()
            );
        }

        return $success ? 0 : 1;
    }

    /**
     * List snapshot policies.
     *
     * @param \Platformsh\Cli\Console\Selection $selection
     */
    private function listPolicies(Selection $selection)
    {
        $this->stdErr->writeln('');
        $this->subCommandRunner->run('snapshot:schedule:list', [
            '--project' => $selection->getProject()->id,
            '--environment' => $selection->getEnvironment()->id,
        ]);
    }
}
