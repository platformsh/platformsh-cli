<?php

namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Backups\Policy;
use Platformsh\Client\Model\Type\Duration;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotScheduleAddCommand extends CommandBase
{

    /** @var Form */
    private $form;

    protected function configure()
    {
        $this->setName('snapshot:schedule:add')
            ->setDescription('Add a scheduled snapshot policy');

        $this->form = Form::fromArray($this->getFields());
        $this->form->configureInputDefinition($this->getDefinition());

        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $values = $this->form->resolveOptions($input, $output, $questionHelper);

        $selectedEnvironment = $this->getSelectedEnvironment();

        $result = $selectedEnvironment->addBackupPolicy(
            new Policy($values['interval'], $values['count'])
        );

        $this->stdErr->writeln(sprintf(
            'Created scheduled snapshot policy: interval <info>%s</info>, count <info>%d</info>.',
            $values['interval'],
            $values['count']
        ));

        $this->api()->clearEnvironmentsCache($selectedEnvironment->project);

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

    /**
     * @return \Platformsh\ConsoleForm\Field\Field[]
     */
    private function getFields()
    {
        return [
            'interval' => new Field('Interval', [
                'description' => 'The time interval between scheduled snapshots',
                'default' => '1h',
                'validator' => function ($duration) {
                    $seconds = (new Duration($duration))->getSeconds();
                    if ($seconds < 3600) {
                        return 'The interval must be at least 1 hour.';
                    }
                    if (!is_int($seconds)) {
                        return 'The interval must be a whole number of seconds.';
                    }

                    return true;
                }
            ]),
            'count' => new Field('Count', [
                'description' => 'The maximum number of snapshots to keep for this policy',
                'validator' => 'is_numeric',
                'default' => 1,
            ])
        ];
    }
}
