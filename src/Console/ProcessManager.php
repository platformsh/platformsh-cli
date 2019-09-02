<?php /** @noinspection PhpComposerExtensionStubsInspection */
declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * A utility to manage multiple external processes launched from a CLI command.
 *
 * Usage:
 * <code>
 *   $manager = new \Platformsh\Cli\Util\ProcessManager();
 *
 *   // Fork: everything after this will run in a child. The user's shell will
 *   // be blocked until the parent is killed.
 *   $manager->fork().
 *
 *   $logFile = 'path/to/logFile';
 *   $log = new \Symfony\Component\Console\Output\StreamOutput(fopen($logFile, 'a'));
 *
 *   // Create multiple external processes.
 *   foreach ($commands as $key => $command) {
 *     $process = new \Symfony\Component\Process\Process($command);
 *     $pidFile = 'path/to/pidFile' . $key;
 *
 *     // Start the process with the manager.
 *     $manager->startProcess($process, $pidFile, $log);
 *
 *     // Report this to the shell.
 *     echo "Started process: " . $process->getCommandLine() . "\n";
 *   }
 *
 *   // Kill the parent process to release the shell prompt.
 *   $manager->killParent();
 *
 *   // Monitor the external process(es). This keeps them running until the
 *   // $pidFile is deleted or they are otherwise stopped.
 *   $manager->monitor();
 * </code>
 */
class ProcessManager
{
    /** @var Process[] */
    protected $processes;

    /**
     * @return bool
     */
    public static function supported()
    {
        return extension_loaded('pcntl') && extension_loaded('posix');
    }

    /**
     * Fork the current PHP process.
     *
     * Code run after this method is run in a child process. The parent process
     * merely waits for a SIGCHLD (successful) or SIGTERM (error) signal from
     * the child.
     *
     * This depends on the PCNTL extension.
     */
    public static function fork()
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork PHP process');
        } elseif ($pid > 0) {
            // This is the parent process. If the child process succeeds, this
            // receives SIGCHLD. If it fails, this receives SIGTERM.
            declare (ticks = 1);
            pcntl_signal(SIGCHLD, function () {
                exit;
            });
            pcntl_signal(SIGTERM, function () {
                exit(1);
            });

            // Wait a reasonable amount of time for the child processes to
            // finish.
            sleep(60);
            throw new \RuntimeException('Timeout in parent process');
        } elseif (posix_setsid() === -1) {
            throw new \RuntimeException('Child process failed to become session leader');
        }
    }

    /**
     * Kill the parent process.
     *
     * @param bool $error
     *   Whether the parent process should exit with an error status.
     */
    public static function killParent($error = false)
    {
        if (!posix_kill(posix_getppid(), $error ? SIGTERM : SIGCHLD)) {
            throw new \RuntimeException('Failed to kill parent process');
        }
    }

    /**
     * Start a managed external process.
     *
     * @param Process $process
     *   The Symfony Process object to manage.
     * @param string $pidFile
     *   The path to a lock file which governs the process: if the file is
     *   deleted then the process will be stopped in self::monitor().
     * @param OutputInterface $log
     *   An output stream to which log messages can be written.
     *
     * @throws \Exception on failure
     *
     * @return int
     *   The process PID.
     */
    public function startProcess(Process $process, $pidFile, OutputInterface $log)
    {
        $this->processes[$pidFile] = $process;

        try {
            $process->start(function ($type, $buffer) use ($log) {
                $output = $log instanceof ConsoleOutputInterface && $type === Process::ERR
                    ? $log->getErrorOutput()
                    : $log;
                $output->write($buffer);
            });
        } catch (\Exception $e) {
            unset($this->processes[$pidFile]);
            throw $e;
        }

        $pid = $process->getPid();
        if (file_put_contents($pidFile, $pid) === false) {
            throw new \RuntimeException('Failed to write PID file: ' . $pidFile);
        }

        $log->writeln(sprintf('Process started: %s', $process->getCommandLine()));

        return $pid;
    }

    /**
     * Monitor processes and stop them if their PID file no longer exists.
     *
     * @param OutputInterface $log
     *   A log file as a Symfony Console output object.
     */
    public function monitor(OutputInterface $log)
    {
        while (count($this->processes)) {
            sleep(1);
            foreach ($this->processes as $pidFile => $process) {
                // The user can delete the PID file in order to stop the
                // process deliberately.
                if (!file_exists($pidFile)) {
                    $log->writeln(sprintf('Process stopped: %s', $process->getCommandLine()));
                    $process->stop();
                    unset($this->processes[$pidFile]);
                } elseif (!$process->isRunning()) {
                    // If the process has been stopped via another method, remove it
                    // from the list, and log a message.
                    $exitCode = $process->getExitCode();
                    if ($signal = $this->getSignal($exitCode)) {
                        $log->writeln(sprintf('Process stopped with signal %s: %s', $signal, $process->getCommandLine()));
                    } elseif ($exitCode > 0) {
                        $log->writeln(sprintf(
                            'Process failed with exit code %s: %s',
                            $exitCode,
                            $process->getCommandLine()
                        ));
                    } else {
                        $log->writeln(sprintf(
                            'Process stopped with exit code %s: %s',
                            $exitCode,
                            $process->getCommandLine()
                        ));
                    }
                    unlink($pidFile);
                    unset($this->processes[$pidFile]);
                }
            }
        }
    }

    /**
     * @param int $exitCode
     *
     * @return string|false
     */
    private function getSignal($exitCode)
    {
        if ($exitCode < 128 || $exitCode > 162) {
            return false;
        }

        $signals = [
            1 => 'SIGHUP',
            2 => 'SIGINT',
            3 => 'SIGQUIT',
            4 => 'SIGILL',
            5 => 'SIGTRAP',
            6 => 'SIGABRT',
            7 => 'SIGEMT',
            8 => 'SIGFPE',
            9 => 'SIGKILL',
            10 => 'SIGBUS',
            11 => 'SIGSEGV',
            12 => 'SIGSYS',
            13 => 'SIGPIPE',
            14 => 'SIGALRM',
            15 => 'SIGTERM',
            16 => 'SIGUSR1',
            17 => 'SIGUSR2',
            18 => 'SIGCHLD',
            19 => 'SIGPWR',
            20 => 'SIGWINCH',
            21 => 'SIGURG',
            22 => 'SIGPOLL',
            23 => 'SIGSTOP',
            24 => 'SIGTSTP',
            25 => 'SIGCONT',
            26 => 'SIGTTIN',
            27 => 'SIGTTOU',
            28 => 'SIGVTALRM',
            29 => 'SIGPROF',
            30 => 'SIGXCPU',
            31 => 'SIGXFSZ',
            32 => 'SIGWAITING',
            33 => 'SIGLWP',
            34 => 'SIGAIO',
        ];

        return $signals[$exitCode - 128];
    }
}
