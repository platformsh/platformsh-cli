<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SubCommandRunner
{
    private $application;
    private $input;
    private $output;
    private $stdErr;

    public function __construct(
        Application $application,
        InputInterface $input,
        OutputInterface $output
    ) {
        $this->application = $application;
        $this->input = $input;
        $this->output = $output;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput(): $output;
    }

    /**
     * Runs another command in this CLI application.
     *
     * @param string               $commandName
     * @param array                $arguments
     * @param OutputInterface|null $output
     *
     * @return int
     * @throws \Exception
     */
    public function run($commandName, array $arguments = [], OutputInterface $output = null)
    {
        $command = $this->application->find($commandName);

        // Pass on all intersecting options to the other command by default.
        foreach ($command->getDefinition()->getOptions() as $option) {
            $n = $option->getName();
            if ($this->input->hasOption($n)
                && !isset($arguments['--' . $n])
                && ($value = $this->input->getOption($n)) !== null) {
                $arguments['--' . $n] = $value;
            }
        }

        $cmdInput = new ArrayInput(['command' => $commandName] + $arguments);
        if (!empty($arguments['--yes']) || !empty($arguments['--no'])) {
            $cmdInput->setInteractive(false);
        } else {
            $cmdInput->setInteractive($this->input->isInteractive());
        }

        $this->stdErr->writeln(
            '<options=reverse>DEBUG</> Running sub-command: <info>' . $command->getName() . '</info>',
            OutputInterface::VERBOSITY_DEBUG
        );

        $currentCommand = $this->application->getCurrentCommand();
        $this->application->setCurrentCommand($command);

        try {
            $result = $command->run($cmdInput, $output ?: $this->output);
        } finally {
            $this->application->setCurrentCommand($currentCommand);
        }

        return $result;
    }
}
