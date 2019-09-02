<?php
declare(strict_types=1);

namespace Platformsh\Cli\Exception;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * An exception thrown when a process fails.
 *
 * This is a copy of Symfony's ProcessFailedException, with an improved error
 * message.
 *
 * @see \Symfony\Component\Process\Exception\ProcessFailedException
 */
class ProcessFailedException extends RuntimeException
{
    private $process;

    /**
     * ProcessFailedException constructor.
     *
     * @param \Symfony\Component\Process\Process $process
     *     The failed process.
     * @param bool $includeErrorOutput
     *     Whether to include the error output in the exception message. Set to
     *     false if the error output would have already been displayed.
     */
    public function __construct(Process $process, $includeErrorOutput)
    {
        if ($process->isSuccessful()) {
            throw new \InvalidArgumentException('Expected a failed process, but the given process was successful.');
        }

        $message = 'The command failed with the exit code: ' . $process->getExitCode();
        $message .= "\n\nFull command: " . $process->getCommandLine();
        if ($includeErrorOutput) {
            $message .= "\n\nError output:\n" . $process->getErrorOutput();
        }

        parent::__construct($message);

        $this->process = $process;
    }

    /**
     * @return \Symfony\Component\Process\Process
     */
    public function getProcess()
    {
        return $this->process;
    }
}
