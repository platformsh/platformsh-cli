<?php

namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Type\Duration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotScheduleDeleteCommand extends CommandBase
{

    protected function configure()
    {
        $this->setName('snapshot:schedule:delete');
        $this->setDescription('Delete a scheduled snapshot policy');
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'The interval, of the policy to delete');
        $this->addOption('count', null, InputOption::VALUE_REQUIRED, 'The count, of the policy to delete');

        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $interval = $input->getOption('interval');
        $count = $input->getOption('count');

        $selectedEnvironment = $this->getSelectedEnvironment();

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
            $this->listPolicies();

            return 1;
        }

        if (count($matchingPolicies) > 1 && ($interval === null || $count === null)) {
            $this->stdErr->writeln('More than one matching policy found.');
            if ($interval === null && $count === null) {
                $this->stdErr->writeln('Specify <comment>--interval</comment> and <comment>--count</comment> to select the policy to delete.');
            }

            $this->listPolicies();

            return 1;
        }

        $policy = reset($matchingPolicies);
        $policyKey = key($matchingPolicies);
        if (!isset($backupConfig['schedule'][$policyKey])) {
            throw new \RuntimeException('Failed to find key in backup schedule: ' . $policyKey);
        }

        $this->stdErr->writeln(sprintf('Selected policy: interval <comment>%s</comment>, count <comment>%d</comment>', $policy['interval'], $policy['count']));
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm('Are you sure you want to delete this snapshot policy?')) {
            return 1;
        }

        // Remove the policy.
        unset($backupConfig['schedule'][$policyKey]);

        // Reset keys so that the schedule is serialized as a JSON array.
        $backupConfig['schedule'] = array_values($backupConfig['schedule']);

        $result = $selectedEnvironment->update([
            'backups' => $backupConfig,
        ]);

        $this->api()->clearEnvironmentsCache($selectedEnvironment->project);

        $this->stdErr->writeln('The policy was successfully deleted.');

        $success = true;
        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple(
                $result->getActivities(),
                $this->getSelectedProject()
            );
        }

        return $success ? 0 : 1;
    }

    private function listPolicies()
    {
        $this->stdErr->writeln('');
        $this->runOtherCommand('snapshot:schedule:list', [
            '--project' => $this->getSelectedProject()->id,
            '--environment' => $this->getSelectedEnvironment()->id,
            '--yes' => true,
        ], $this->stdErr);
    }
}
