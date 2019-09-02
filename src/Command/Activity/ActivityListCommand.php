<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityListCommand extends CommandBase
{
    protected static $defaultName = 'activity:list';

    private $activityLoader;
    private $activityService;
    private $api;
    private $config;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(
        ActivityLoader $activityLoader,
        ActivityService $activityService,
        Api $api,
        Config $config,
        Selector $selector,
        Table $table,
        PropertyFormatter $formatter
    ) {
        $this->activityLoader = $activityLoader;
        $this->activityService = $activityService;
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->table = $table;
        $this->formatter = $formatter;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['activities', 'act'])
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter activities by type')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check activities on all environments')
            ->setDescription('Get a list of activities for an environment or project');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
        $this->formatter->configureInput($definition);

        $this->addExample('List recent activities for the current environment')
             ->addExample('List all recent activities for the current project', '--all')
             ->addExample('List recent pushes', '--type environment.push')
             ->addExample('List pushes made before 15 March', '--type environment.push --start 2015-03-15');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input, $input->getOption('all'));

        $project = $selection->getProject();

        $startsAt = null;
        if ($input->getOption('start') && !($startsAt = strtotime($input->getOption('start')))) {
            $this->stdErr->writeln('Invalid date: <error>' . $input->getOption('start') . '</error>');
            return 1;
        }

        $limit = (int) $input->getOption('limit');

        if ($selection->hasEnvironment() && !$input->getOption('all')) {
            $environmentSpecific = true;
            $apiResource = $selection->getEnvironment();
        } else {
            $environmentSpecific = false;
            $apiResource = $project;
        }

        $type = $input->getOption('type');

        $activities = $this->activityLoader->load($apiResource, $limit, $type, $startsAt);
        if (!$activities) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        $headers = ['ID', 'Created', 'Completed', 'Description', 'Progress', 'State', 'Result', 'Environment(s)'];
        $defaultColumns = ['ID', 'Created', 'Description', 'State', 'Result'];

        if (!$environmentSpecific) {
            $defaultColumns[] = 'Environment(s)';
        }

        $rows = [];
        foreach ($activities as $activity) {
            $rows[] = [
                new AdaptiveTableCell($activity->id, ['wrap' => false]),
                $this->formatter->format($activity['created_at'], 'created_at'),
                $this->formatter->format($activity['completed_at'], 'completed_at'),
                $this->activityService->getFormattedDescription($activity, !$this->table->formatIsMachineReadable()),
                $activity->getCompletionPercent() . '%',
                $this->activityService->formatState($activity->state),
                $this->activityService->formatResult($activity->result, !$this->table->formatIsMachineReadable()),
                implode(', ', $activity->environments)
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            if ($environmentSpecific) {
                $this->stdErr->writeln(sprintf(
                    'Activities on the project %s, environment %s:',
                    $this->api->getProjectLabel($project),
                    $this->api->getEnvironmentLabel($apiResource)
                ));
            } else {
                $this->stdErr->writeln(
                    sprintf(
                        'Activities on the project %s:',
                        $this->api->getProjectLabel($project)
                    )
                );
            }
        }

        $this->table->render($rows, $headers, $defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->get('application.executable');

            $max = $input->getOption('limit') ? (int) $input->getOption('limit') : 10;
            $maybeMoreAvailable = count($activities) === $max;
            if ($maybeMoreAvailable) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'More activities may be available.'
                    . ' To display older activities, increase <info>--limit</info> above %d, or set <info>--start</info> to a date in the past.'
                    . ' For more information, run: <info>%s activity:list -h</info>',
                    $max,
                    $executable
                ));
            }

            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for an activity, run: <info>%s activity:log [id]</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To view more information about an activity, run: <info>%s activity:get [id]</info>',
                $executable
            ));
        }

        return 0;
    }
}
