<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Model\Backups\Policy;
use Platformsh\Client\Model\Type\Duration;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotScheduleAddCommand extends CommandBase
{
    protected static $defaultName = 'snapshot:schedule:add';

    private $activityService;
    private $api;
    private $questionHelper;
    private $selector;

    /** @var Form */
    private $form;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Add a scheduled snapshot policy');

        $definition = $this->getDefinition();

        $this->form = Form::fromArray($this->getFields());
        $this->form->configureInputDefinition($definition);

        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $values = $this->form->resolveOptions($input, $output, $this->questionHelper);

        $selection = $this->selector->getSelection($input);
        $selectedEnvironment = $selection->getEnvironment();

        $result = $selection->getEnvironment()->addBackupPolicy(
            new Policy($values['interval'], $values['count'])
        );

        $this->stdErr->writeln(sprintf(
            'Created scheduled snapshot policy: interval <info>%s</info>, count <info>%d</info>.',
            $values['interval'],
            $values['count']
        ));

        $this->api->clearEnvironmentsCache($selectedEnvironment->project);

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
