<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotCreateCommand extends CommandBase
{
    protected static $defaultName = 'snapshot:create';

    private $activityService;
    private $api;
    private $selector;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        Selector $selector
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Make a snapshot of an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);

        $this->setHiddenAliases(['backup', 'environment:backup']);
        $this->addExample('Make a snapshot of the current environment');
        $this->addExample('Request a snapshot (and exit quickly)', '--no-wait');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $selectedEnvironment = $selection->getEnvironment();
        $environmentId = $selectedEnvironment->id;
        if (!$selectedEnvironment->operationAvailable('backup', true)) {
            $this->stdErr->writeln(
                "Operation not available: cannot create a snapshot of <error>$environmentId</error>"
            );

            if ($selectedEnvironment->is_dirty) {
                $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
            } elseif (!$selectedEnvironment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            }

            $access = $selectedEnvironment->getUser($this->api->getMyAccount()['id']);
            if ($access->role !== 'admin') {
                $this->stdErr->writeln('You must be an administrator to create a snapshot.');
            }

            return 1;
        }

        $activity = $selectedEnvironment->backup();

        $this->stdErr->writeln("Creating a snapshot of <info>$environmentId</info>");

        if ($this->activityService->shouldWait($input)) {
            $this->stdErr->writeln('Waiting for the snapshot to complete...');

            // Strongly recommend using --no-wait in a cron job.
            if (!$this->isTerminal(STDIN)) {
                $this->stdErr->writeln(
                    '<comment>Warning:</comment> use the --no-wait (-W) option if you are running this in a cron job.'
                );
            }

            $success = $this->activityService->waitAndLog(
                $activity,
                'A snapshot of environment <info>' . $environmentId . '</info> has been created',
                'The snapshot failed'
            );
            if (!$success) {
                return 1;
            }
        }

        if (!empty($activity['payload']['backup_name'])) {
            $name = $activity['payload']['backup_name'];
            $output->writeln("Snapshot name: <info>$name</info>");
        }

        return 0;
    }
}
