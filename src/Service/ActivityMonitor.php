<?php

namespace Platformsh\Cli\Service;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Client\Model\Activity;
use Platformsh\Client\Model\ActivityLog\LogItem;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityMonitor
{

    protected static $resultNames = [
        Activity::RESULT_FAILURE => 'failure',
        Activity::RESULT_SUCCESS => 'success',
    ];

    protected static $stateNames = [
        Activity::STATE_PENDING => 'pending',
        Activity::STATE_COMPLETE => 'complete',
        Activity::STATE_IN_PROGRESS => 'in progress',
        Activity::STATE_CANCELLED => 'cancelled',
    ];

    protected $output;
    protected $config;
    protected $api;

    /**
     * @param OutputInterface $output
     * @param Config $config
     * @param Api $api
     */
    public function __construct(OutputInterface $output, Config $config, Api $api)
    {
        $this->output = $output;
        $this->config = $config;
        $this->api = $api;
    }

    /**
     * @return \Symfony\Component\Console\Output\OutputInterface
     */
    protected function getStdErr()
    {
        return $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
    }

    /**
     * Indent a multi-line string.
     *
     * @param string $string
     * @param string $prefix
     *
     * @return string
     */
    protected function indent($string, $prefix = '    ')
    {
        return preg_replace('/^/m', $prefix, $string);
    }

    /**
     * Wait for a single activity to complete, and display the log continuously.
     *
     * @param Activity $activity The activity.
     * @param int $pollInterval The interval between refreshing the activity (seconds).
     * @param bool|string $timestamps Whether to display timestamps (or pass in a date format).
     * @param bool $context Whether to add a context message.
     * @param OutputInterface|null $logOutput The output object for log messages (defaults to stderr).
     *
     * @return bool True if the activity succeeded, false otherwise.
     */
    public function waitAndLog(Activity $activity, $pollInterval = 3, $timestamps = false, $context = true, OutputInterface $logOutput = null)
    {
        $stdErr = $this->getStdErr();
        $logOutput = $logOutput ?: $stdErr;

        if ($context) {
            $stdErr->writeln(sprintf(
                'Waiting for the activity <info>%s</info> (%s):',
                $activity->id,
                self::getFormattedDescription($activity)
            ));
            $stdErr->writeln('');
        }

        // The progress bar will show elapsed time and the activity's state.
        $bar = $this->newProgressBar($stdErr);
        $overrideState = '';
        $bar->setPlaceholderFormatterDefinition('state', function () use ($activity, &$overrideState) {
            return $this->formatState($overrideState ?: $activity->state);
        });
        $startTime = $this->getStart($activity) ?: time();
        $bar->setPlaceholderFormatterDefinition('elapsed', function () use ($startTime) {
            return Helper::formatTime(time() - $startTime);
        });
        $bar->setFormat('[%bar%] %elapsed:6s% (%state%)');

        // Set up cancellation for the activity on Ctrl+C.
        if (\function_exists('\\pcntl_signal') && $activity->operationAvailable('cancel')) {
            declare(ticks = 1);
            $sigintReceived = false;
            /** @noinspection PhpComposerExtensionStubsInspection */
            \pcntl_signal(SIGINT, function () use ($activity, $stdErr, $bar, &$sigintReceived) {
                if ($sigintReceived) {
                    exit(1);
                }
                $sigintReceived = true;
                $bar->clear();
                if ($this->cancel($activity, $stdErr)) {
                    exit(1);
                }
                $stdErr->writeln('');
                $bar->advance();
            });
            $stdErr->writeln('Enter Ctrl+C once to cancel the activity (or twice to quit this command).');
        }

        $bar->start();

        $logStream = $this->getLogStream($activity, $bar);
        $bar->advance();

        // Read the log while waiting for the activity to complete.
        $lastRefresh = microtime(true);
        $buffer = '';
        while (!feof($logStream) || !$activity->isComplete()) {
            // If $pollInterval has passed, or if there is nothing else left
            // to do, then refresh the activity.
            if (feof($logStream) || microtime(true) - $lastRefresh >= $pollInterval) {
                $activity->refresh();
                $overrideState = '';
                $lastRefresh = microtime(true);
            }

            // Update the progress bar.
            $bar->advance();

            // Wait to see if a read will not block the stream, for up to .2
            // seconds.
            if (!$this->canRead($logStream, 200000)) {
                continue;
            }

            // Parse the log.
            $items = $this->parseLog($logStream, $buffer);
            if (empty($items)) {
                continue;
            }

            // If there is log output, assume the activity must be in progress.
            if ($activity->state === Activity::STATE_PENDING) {
                $overrideState = Activity::STATE_IN_PROGRESS;
            }

            // Format log items.
            $formatted = $this->formatLog($items, $timestamps);

            // Clear the progress bar and ensure the current line is flushed.
            $bar->clear();
            $stdErr->write($stdErr->isDecorated() ? "\n\033[1A" : "\n");

            // Display the new log output.
            $logOutput->write($formatted);

            // Display the progress bar again.
            $bar->advance();
        }
        $bar->finish();
        $stdErr->writeln('');

        // Display the success or failure messages.
        switch ($activity['result']) {
            case Activity::RESULT_SUCCESS:
                $stdErr->writeln("Activity <info>{$activity->id}</info> succeeded");
                return true;

            case Activity::RESULT_FAILURE:
                $stdErr->writeln("Activity <error>{$activity->id}</error> failed");
                return false;
        }

        return false;
    }

    /**
     * Attempts to cancel the activity, catching and printing errors.
     *
     * @param Activity $activity
     * @param OutputInterface $stdErr
     *
     * @return bool
     */
    private function cancel(Activity $activity, OutputInterface $stdErr)
    {
        if (!$activity->operationAvailable('cancel')) {
            $stdErr->writeln('The activity cannot be cancelled.');
            return false;
        }
        $stdErr->writeln('Cancelling the activity...');
        try {
            $activity->cancel();
        } catch (\Exception $e) {
            if ($e instanceof BadResponseException
                && $e->getResponse() && $e->getResponse()->getStatusCode() === 400
                && \strpos($e->getMessage(), 'cannot be cancelled in its current state') !== false) {
                $activity->refresh();
                $stdErr->writeln(\sprintf('The activity cannot be cancelled in its current state (<error>%s</error>).', $activity->state));
                return false;
            }
            $stdErr->writeln(\sprintf('Failed to cancel the activity: <error>%s</error>', $e->getMessage()));
            return false;
        }
        $stdErr->writeln('The activity was successfully cancelled.');
        return true;
    }

    /**
     * Reads the log stream and returns LogItem objects.
     *
     * @param resource $stream
     *   The stream.
     * @param string   &$buffer
     *   A string where a buffer can be stored between stream updates.
     *
     * @return LogItem[]
     */
    private function parseLog($stream, &$buffer) {
        $buffer .= stream_get_contents($stream);
        $lastNewline = strrpos($buffer, "\n");
        if ($lastNewline === false) {
            return [];
        }
        $content = substr($buffer, 0, $lastNewline + 1);
        $buffer = substr($buffer, $lastNewline + 1);

        return LogItem::multipleFromJsonStream($content);
    }

    /**
     * Waits to see if a stream can be read (if the read will not block).
     *
     * @param resource $stream  The stream.
     * @param int      $microseconds A timeout in microseconds.
     *
     * @return bool
     */
    private function canRead($stream, $microseconds) {
        if (PHP_MAJOR_VERSION >= 8) {
            // Work around a bug: "Cannot cast a filtered stream on this system" which throws a ValueError in PHP 8+.
            // See https://github.com/platformsh/platformsh-cli/issues/1027#issuecomment-779170913
            \usleep($microseconds);
            return true;
        }
        $readSet = [$stream];
        $ignore = [];

        return (bool) stream_select($readSet, $ignore, $ignore, 0, $microseconds);
    }

    /**
     * Formats log items for display.
     *
     * @param LogItem[]   $items
     *   The log items.
     * @param bool|string $timestamps
     *   False for no timestamps, or a string date format or true to display timestamps
     *
     * @return string
     */
    public function formatLog(array $items, $timestamps = false) {
        $timestampFormat = false;
        if ($timestamps !== false) {
            $timestampFormat = $timestamps ?: $this->config->getWithDefault('application.date_format', 'Y-m-d H:i:s');
        }
        $formatItem = function (LogItem $item) use ($timestampFormat) {
            if ($timestampFormat !== false) {
                return '[' . $item->getTime()->format($timestampFormat) . '] '. $item->getMessage();
            }

            return $item->getMessage();
        };

        return implode('', array_map($formatItem, $items));
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
    public function waitMultiple(array $activities, Project $project)
    {
        $stdErr = $this->getStdErr();

        $count = count($activities);
        if ($count == 0) {
            return true;
        } elseif ($count === 1) {
            return $this->waitAndLog(reset($activities));
        }

        $stdErr->writeln(sprintf('Waiting for %d activities...', $count));

        // The progress bar will show elapsed time and all of the activities'
        // states.
        $bar = $this->newProgressBar($stdErr);
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
            foreach ($activities as &$activity) {
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
        $stdErr->writeln('');

        // Display success or failure messages for each activity.
        $success = true;
        foreach ($activities as $activity) {
            $description = self::getFormattedDescription($activity);
            switch ($activity['result']) {
                case Activity::RESULT_SUCCESS:
                    $stdErr->writeln(sprintf('Activity <info>%s</info> succeeded: %s', $activity->id, $description));
                    break;

                case Activity::RESULT_FAILURE:
                    $success = false;
                    $stdErr->writeln(sprintf('Activity <error>%s</error> failed', $activity->id));

                    // If the activity failed, show the complete log.
                    $stdErr->writeln('  Description: ' . $description);
                    $stdErr->writeln('  Log:');
                    $stdErr->writeln($this->indent($this->formatLog($activity->readLog())));
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
    public static function formatState($state)
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
    public static function formatResult($result, $decorate = true)
    {
        $name = isset(self::$resultNames[$result]) ? self::$resultNames[$result] : $result;

        return $decorate && $result === Activity::RESULT_FAILURE
            ? '<error>' . $name . '</error>'
            : $name;
    }

    /**
     * Initialize a new progress bar.
     *
     * @param OutputInterface $output
     *
     * @return ProgressBar
     */
    protected function newProgressBar(OutputInterface $output)
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
    public static function getFormattedDescription(Activity $activity, $withDecoration = true)
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
     * @param Activity $activity
     *
     * @return false|int
     */
    private function getStart(Activity $activity) {
        return !empty($activity->started_at) ? strtotime($activity->started_at) : strtotime($activity->created_at);
    }

    /**
     * Returns the activity log as a PHP stream resource.
     *
     * @param Activity $activity
     * @param ProgressBar $bar
     *   Progress bar, updated when we retry.
     *
     * @return resource
     */
    private function getLogStream(Activity $activity, ProgressBar $bar) {
        $url = $activity->getLink('log');

        // Try fetching the stream with an up to 10 second timeout per call,
        // and a .5 second interval between calls, for up to 2 minutes.
        $readTimeout = .5;
        $stream = \fopen($url, 'r', false, $this->api->getStreamContext($readTimeout));
        $interval = .5;
        $start = \microtime(true);
        while ($stream === false) {
            if (\microtime(true) - $start > 120) {
                throw new \RuntimeException('Failed to open activity log stream: ' . $url);
            }
            $bar->advance();
            \usleep($interval * 1000000);
            $bar->advance();
            $readTimeout = $readTimeout >= 10 ? $readTimeout : $readTimeout + .5;
            $stream = \fopen($url, 'r', false, $this->api->getStreamContext($readTimeout));
        }
        \stream_set_blocking($stream, 0);

        return $stream;
    }
}
