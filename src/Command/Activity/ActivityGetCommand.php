<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\Selection;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityGetCommand extends CommandBase
{
    protected static $defaultName = 'activity:get';

    private $activityService;
    private $selector;
    private $table;
    private $formatter;
    private $api;
    private $config;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        Config $config,
        PropertyFormatter $formatter,
        Selector $selector,
        Table $table
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->config = $config;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter recent activities by type')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to view')
            ->setDescription('View detailed information on a single activity');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
        $this->formatter->configureInput($definition);

        $this->addExample('Find the time a project was created', '--all --type project.create -P completed_at');
        $this->addExample('Find the duration (in seconds) of the last activity', '-P duration');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input, $input->getOption('all') || $input->getArgument('id'));

        $id = $input->getArgument('id');
        if ($id) {
            $activity = $selection->getProject()->getActivity($id);
            if (!$activity) {
                $activity = $this->api->matchPartialId($id, $this->getActivities($selection, $input), 'Activity');
                if (!$activity) {
                    $this->stdErr->writeln("Activity not found: <error>$id</error>");

                    return 1;
                }
            }
        } else {
            $activities = $this->getActivities($selection, $input, 1);
            /** @var Activity $activity */
            $activity = reset($activities);
            if (!$activity) {
                $this->stdErr->writeln('No activities found');

                return 1;
            }
        }

        $properties = $activity->getProperties();

        if (!$input->getOption('property') && !$this->table->formatIsMachineReadable()) {
            $properties['description'] = $this->activityService->getFormattedDescription($activity, true);
        } else {
            $properties['description'] = $this->activityService->getFormattedDescription($activity, false);
            if ($input->getOption('property')) {
                $properties['description_html'] = $activity->description;
            }
        }

        // Add the fake "duration" property.
        if (!isset($properties['duration'])) {
            $properties['duration'] = $this->getDuration($activity);
        }

        if ($property = $input->getOption('property')) {
            $this->formatter->displayData($output, $properties, $property);
            return 0;
        }

        unset($properties['payload'], $properties['log']);

        $this->stdErr->writeln(
            'These properties have been omitted for brevity: <comment>payload</comment> and <comment>log</comment>.'
            . ' You can still view them with the -P (--property) option.',
            OutputInterface::VERBOSITY_VERBOSE
        );

        $header = [];
        $rows = [];
        foreach ($properties as $property => $value) {
            $header[] = $property;
            $rows[] = $this->formatter->format($value, $property);
        }

        $this->table->renderSimple($rows, $header);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->get('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for this activity, run: <info>%s activity:log %s</info>',
                $executable,
                $activity->id
            ));
            $this->stdErr->writeln(sprintf(
                'To list activities, run: <info>%s activities</info>',
                $executable
            ));
        }

        return 0;
    }

    /**
     * Get activities on the project or environment.
     *
     * @param Selection                                       $selection
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param int                                             $limit
     *
     * @return \Platformsh\Client\Model\Activity[]
     */
    private function getActivities(Selection $selection, InputInterface $input, int $limit = 0): array
    {
        if ($selection->hasEnvironment() && !$input->getOption('all')) {
            return $selection->getEnvironment()
                ->getActivities($limit, $input->getOption('type'));
        }

        return $selection->getProject()
            ->getActivities($limit, $input->getOption('type'));
    }

    /**
     * Calculates the duration of an activity, whether complete or not.
     *
     * @param \Platformsh\Client\Model\Activity $activity
     * @param int|null                          $now
     *
     * @return int|null
     */
    private function getDuration(Activity $activity, ?int $now = null): ?int
    {
        if ($activity->isComplete()) {
            $end = strtotime($activity->completed_at);
        } elseif (!empty($activity->started_at)) {
            $now = $now === null ? time() : $now;
            $end = $now;
        } else {
            $end = strtotime($activity->updated_at);
        }
        $start = !empty($activity->started_at) ? strtotime($activity->started_at) : strtotime($activity->created_at);

        return $end !== false && $start !== false && $end - $start > 0 ? $end - $start : null;
    }
}
