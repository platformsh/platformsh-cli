<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local\DependencyManager;

use Platformsh\Cli\Service\Shell;

abstract class DependencyManagerBase implements DependencyManagerInterface
{
    protected $shell;
    protected $command = 'undefined';

    public function __construct(Shell $shell)
    {
        $this->shell = $shell;
    }

    /**
     * {@inheritdoc}
     */
    public function getCommandName($global = false)
    {
        return $this->command;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable()
    {
        return $this->shell->commandExists($this->command);
    }

    /**
     * {@inheritdoc}
     */
    public function getEnvVars($path)
    {
        return [];
    }

    /**
     * @param string      $command
     * @param string|null $path
     */
    protected function runCommand($command, $path = null)
    {
        $code = $this->shell->executeSimple($command, $path);
        if ($code > 0) {
            throw new \RuntimeException(sprintf(
                'The command failed with the exit code %d: %s',
                $code,
                $command
            ));
        }
    }
}
