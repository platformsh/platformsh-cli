<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityLogCommand extends CommandBase
{
    protected static $defaultName = 'activity:log';

    private $activityService;
    private $api;
    private $selector;
    private $propertyFormatter;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        Selector $selector,
        PropertyFormatter $formatter
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->selector = $selector;
        $this->propertyFormatter = $formatter;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.')
            ->addOption(
                'refresh',
                null,
                InputOption::VALUE_REQUIRED,
                'Log refresh interval (seconds). Set to 0 to disable refreshing.',
                1
            )
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter recent activities by type')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments')
            ->setDescription('Display the log for an activity');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->propertyFormatter->configureInput($definition);

        $this->addExample('Display the log for the last push on the current environment', '--type environment.push')
            ->addExample('Display the log for the last activity on the current project', '--all');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input, $input->getOption('all') || $input->getArgument('id'));

        $id = $input->getArgument('id');
        if ($id) {
            $activity = $selection->getProject()
                             ->getActivity($id);
            if (!$activity) {
                $activities = $selection->getEnvironment()
                    ->getActivities(0, $input->getOption('type'));
                $activity = $this->api->matchPartialId($id, $activities, 'Activity');
                if (!$activity) {
                    $this->stdErr->writeln("Activity not found: <error>$id</error>");

                    return 1;
                }
            }
        } else {
            if ($selection->hasEnvironment() && !$input->getOption('all')) {
                $activities = $selection->getEnvironment()
                    ->getActivities(1, $input->getOption('type'));
            } else {
                $activities = $selection->getProject()
                    ->getActivities(1, $input->getOption('type'));
            }
            /** @var Activity $activity */
            $activity = reset($activities);
            if (!$activity) {
                $this->stdErr->writeln('No activities found');

                return 1;
            }
        }

        $this->stdErr->writeln([
            sprintf('<info>Activity ID: </info>%s', $activity->id),
            sprintf('<info>Description: </info>%s', $this->activityService->getFormattedDescription($activity)),
            sprintf('<info>Created: </info>%s', $this->propertyFormatter->format($activity->created_at, 'created_at')),
            sprintf('<info>State: </info>%s', $this->activityService->formatState($activity->state)),
            '<info>Log: </info>',
        ]);

        $refresh = $input->getOption('refresh');
        if ($refresh > 0 && !$this->runningViaMulti && !$activity->isComplete()) {
            $progressOutput = $this->stdErr->isDecorated() ? $this->stdErr : new NullOutput();
            $bar = new ProgressBar($progressOutput);
            $bar->setPlaceholderFormatterDefinition('state', function () use ($activity) {
                return $this->activityService->formatState($activity->state);
            });
            $bar->setFormat('[%bar%] %elapsed:6s% (%state%)');
            $bar->start();

            $activity->wait(
                function () use ($bar) {
                    $bar->advance();
                },
                function ($log) use ($output, $bar, $progressOutput) {
                    // Clear the progress bar and ensure the current line is flushed.
                    $bar->clear();
                    $progressOutput->write($progressOutput->isDecorated() ? "\n\033[1A" : "\n");

                    // Display the new log output.
                    $output->write($log);

                    // Display the progress bar again.
                    $bar->advance();
                },
                $refresh
            );
            $bar->clear();

            // Once the activity is complete, something has probably changed in
            // the project's environments, so this is a good opportunity to
            // clear the cache.
            $this->api->clearEnvironmentsCache($activity->project);
        } else {
            $output->writeln($activity->log);
        }

        return 0;
    }
}
