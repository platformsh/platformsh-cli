<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\Activity;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityService implements InputConfiguringInterface
{

    private static $resultNames = [
        Activity::RESULT_FAILURE => 'failure',
        Activity::RESULT_SUCCESS => 'success',
    ];

    private static $stateNames = [
        Activity::STATE_PENDING => 'pending',
        Activity::STATE_COMPLETE => 'complete',
        Activity::STATE_IN_PROGRESS => 'in progress',
    ];

    private $config;
    private $stdErr;

    /**
     * @param \Platformsh\Cli\Service\Config                    $config
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function __construct(Config $config, OutputInterface $output)
    {
        $this->config = $config;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Indent a multi-line string.
     *
     * @param string $string
     * @param string $prefix
     *
     * @return string
     */
    private function indent(string $string, string $prefix = '    '): string
    {
        return preg_replace('/^/m', $prefix, $string);
    }

    /**
     * Wait for a single activity to complete, and display the log continuously.
     *
     * @param Activity    $activity The activity.
     * @param string|null $success  A message to show on success.
     * @param string|null $failure  A message to show on failure.
     *
     * @return bool True if the activity succeeded, false otherwise.
     */
    public function waitAndLog(Activity $activity, ?string $success = null, ?string $failure = null): bool
    {
        $this->stdErr->writeln(sprintf(
            'Waiting for the activity <info>%s</info> (%s):',
            $activity->id,
            self::getFormattedDescription($activity)
        ));

        // The progress bar will show elapsed time and the activity's state.
        $bar = $this->newProgressBar($this->stdErr);
        $bar->setPlaceholderFormatterDefinition('state', function () use ($activity) {
            return $this->formatState($activity->state);
        });
        $bar->setFormat('  [%bar%] %elapsed:6s% (%state%)');
        $bar->start();

        // Wait for the activity to complete.
        $activity->wait(
            // Advance the progress bar whenever the activity is polled.
            function () use ($bar) {
                $bar->advance();
            },
            // Display new log output when it is available.
            function ($log) use ($bar) {
                // Clear the progress bar and ensure the current line is flushed.
                $bar->clear();
                $this->stdErr->write($this->stdErr->isDecorated() ? "\n\033[1A" : "\n");

                // Display the new log output.
                $this->stdErr->write($this->indent($log));

                // Display the progress bar again.
                $bar->advance();
            }
        );
        $bar->finish();
        $this->stdErr->writeln('');

        // Display the success or failure messages.
        switch ($activity['result']) {
            case Activity::RESULT_SUCCESS:
                $this->stdErr->writeln($success ?: "Activity <info>{$activity->id}</info> succeeded");
                return true;

            case Activity::RESULT_FAILURE:
                $this->stdErr->writeln($failure ?: "Activity <error>{$activity->id}</error> failed");
                return false;
        }

        return false;
    }

    /**
     * Wait for multiple activities to complete.
     *
     * A progress bar tracks the state of each activity. The activity log is
     * only displayed at the end, if an activity failed.
     *
     * @param Activity[]      $activities
     * @param Project         $project
     *
     * @return bool
     *   True if all activities succeed, false otherwise.
     */
    public function waitMultiple(array $activities, Project $project): bool
    {
        $count = count($activities);
        if ($count == 0) {
            return true;
        } elseif ($count === 1) {
            return $this->waitAndLog(reset($activities));
        }

        $this->stdErr->writeln(sprintf('Waiting for %d activities...', $count));

        // The progress bar will show elapsed time and all of the activities'
        // states.
        $bar = $this->newProgressBar($this->stdErr);
        $states = [];
        foreach ($activities as $activity) {
            $state = $activity->state;
            $states[$state] = isset($states[$state]) ? $states[$state] + 1 : 1;
        }
        $bar->setPlaceholderFormatterDefinition('states', function () use (&$states) {
            $format = '';
            foreach ($states as $state => $count) {
                $format .= $count . ' ' . $this->formatState($state) . ', ';
            }

            return rtrim($format, ', ');
        });
        $bar->setFormat('  [%bar%] %elapsed:6s% (%states%)');
        $bar->start();

        // Get the most recent created date of each of the activities, as a Unix
        // timestamp, so that they can be more efficiently refreshed.
        $mostRecentTimestamp = 0;
        foreach ($activities as $activity) {
            $created = strtotime($activity->created_at);
            $mostRecentTimestamp = $created > $mostRecentTimestamp ? $created : $mostRecentTimestamp;
        }

        // Wait for the activities to complete, polling (refreshing) all of them
        // with a 1 second delay.
        $complete = 0;
        while ($complete < $count) {
            sleep(1);
            $states = [];
            $complete = 0;
            // Get a list of activities on the project. Any of our activities
            // which are not contained in this list must be refreshed
            // individually.
            $projectActivities = $project->getActivities(0, null, $mostRecentTimestamp ?: null);
            foreach ($activities as $activity) {
                $refreshed = false;
                foreach ($projectActivities as $projectActivity) {
                    if ($projectActivity->id === $activity->id) {
                        $activity = $projectActivity;
                        $refreshed = true;
                        break;
                    }
                }
                if (!$refreshed && !$activity->isComplete()) {
                    $activity->refresh();
                }
                if ($activity->isComplete()) {
                    $complete++;
                }
                $state = $activity->state;
                $states[$state] = isset($states[$state]) ? $states[$state] + 1 : 1;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->stdErr->writeln('');

        // Display success or failure messages for each activity.
        $success = true;
        foreach ($activities as $activity) {
            $description = self::getFormattedDescription($activity);
            switch ($activity['result']) {
                case Activity::RESULT_SUCCESS:
                    $this->stdErr->writeln(sprintf('Activity <info>%s</info> succeeded: %s', $activity->id, $description));
                    break;

                case Activity::RESULT_FAILURE:
                    $success = false;
                    $this->stdErr->writeln(sprintf('Activity <error>%s</error> failed', $activity->id));

                    // If the activity failed, show the complete log.
                    $this->stdErr->writeln('  Description: ' . $description);
                    $this->stdErr->writeln('  Log:');
                    $this->stdErr->writeln($this->indent($activity->log));
                    break;
            }
        }

        return $success;
    }

    /**
     * Format a state name.
     *
     * @param string $state
     *
     * @return string
     */
    public function formatState(string $state): string
    {
        return isset(self::$stateNames[$state]) ? self::$stateNames[$state] : $state;
    }

    /**
     * Format a result.
     *
     * @param string $result
     * @param bool   $decorate
     *
     * @return string
     */
    public function formatResult(string $result, bool $decorate = true): string
    {
        $name = isset(self::$resultNames[$result]) ? self::$resultNames[$result] : $result;

        if ($decorate && $result === Activity::RESULT_FAILURE) {
            return '<bg=red>' . $name . '</>';
        }

        return $name;
    }

    /**
     * Initialize a new progress bar.
     *
     * @param OutputInterface $output
     *
     * @return ProgressBar
     */
    private function newProgressBar(OutputInterface $output)
    {
        // If the console output is not decorated (i.e. it does not support
        // ANSI), use NullOutput to suppress the progress bar entirely.
        $progressOutput = $output->isDecorated() ? $output : new NullOutput();

        return new ProgressBar($progressOutput);
    }

    /**
     * Get the formatted description of an activity.
     *
     * @param \Platformsh\Client\Model\Activity $activity
     * @param bool                              $withDecoration
     *
     * @return string
     */
    public function getFormattedDescription(Activity $activity, bool $withDecoration = true): string
    {
        if (!$withDecoration) {
            return $activity->getDescription(false);
        }
        $value = $activity->getDescription(true);

        // Replace description HTML elements with Symfony Console decoration
        // tags.
        $value = preg_replace('@<[^/][^>]+>@', '<options=underscore>', $value);
        $value = preg_replace('@</[^>]+>@', '</>', $value);

        // Replace literal tags like "&lt;info&;gt;" with escaped tags like
        // "\<info>".
        $value = preg_replace('@&lt;(/?[a-z][a-z0-9,_=;-]*+)&gt;@i', '\\\<$1>', $value);

        // Decode other HTML entities.
        $value = html_entity_decode($value, ENT_QUOTES, 'utf-8');

        return $value;
    }

    /**
     * {@inheritdoc}
     *
     * Add both the --no-wait and --wait options.
     */
    public function configureInput(InputDefinition $inputDefinition): void
    {
        $description = 'Wait for the operation to complete';
        if (!$this->detectRunningInHook()) {
            $description = 'Wait for the operation to complete (default)';
        }

        $inputDefinition->addOption(new InputOption('no-wait', 'W', InputOption::VALUE_NONE, 'Do not wait for the operation to complete'));
        $inputDefinition->addOption(new InputOption('wait', null, InputOption::VALUE_NONE, $description));
    }

    /**
     * Returns whether we should wait for an operation to complete.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *
     * @return bool
     */
    public function shouldWait(InputInterface $input): bool
    {
        if ($input->hasOption('no-wait') && $input->getOption('no-wait')) {
            return false;
        }
        if ($input->hasOption('wait') && $input->getOption('wait')) {
            return true;
        }
        if ($this->detectRunningInHook()) {
            $serviceName = $this->config->get('service.name');
            $message = "\n<comment>Warning:</comment> $serviceName hook environment detected: assuming <comment>--no-wait</comment> by default."
                . "\nTo avoid ambiguity, please specify either --no-wait or --wait."
                . "\n";
            $this->stdErr->writeln($message);

            return false;
        }

        return true;
    }

    /**
     * Detects a Platform.sh non-terminal Dash environment; i.e. a hook.
     *
     * @return bool
     */
    private function detectRunningInHook(): bool
    {
        $envPrefix = $this->config->get('service.env_prefix');
        if (getenv($envPrefix . 'PROJECT')
            && basename(getenv('SHELL')) === 'dash'
            && function_exists('posix_isatty')
            && !posix_isatty(STDIN)) {
            return true;
        }

        return false;
    }

    /**
     * Warn the user that the remote environment needs redeploying.
     */
    public function redeployWarning(): void
    {
        $this->stdErr->writeln([
            '',
            '<comment>The remote environment(s) must be redeployed for the change to take effect.</comment>',
            'To redeploy an environment, run: <info>' . $this->config->get('application.executable') . ' redeploy</info>',
        ]);
    }
}
