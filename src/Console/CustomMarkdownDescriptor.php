<?php
declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\MarkdownDescriptor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CustomMarkdownDescriptor extends MarkdownDescriptor
{
    protected $cliExecutableName;

    /**
     * @param string|null $cliExecutableName
     *   The name of the CLI command.
     */
    public function __construct($cliExecutableName = null)
    {
        $this->cliExecutableName = $cliExecutableName ?: basename($_SERVER['PHP_SELF']);
    }

    /**
     * @inheritdoc
     */
    protected function describeCommand(Command $command, array $options = [])
    {
        $command->getSynopsis();
        $command->mergeApplicationDefinition(false);

        $this->write($command->getName() . "\n"
            . str_repeat('-', strlen($command->getName()))."\n");

        if ($description = $command->getDescription()) {
            $this->write("$description\n\n");
        }

        $aliases = $command instanceof CommandBase ? $command->getVisibleAliases() : $command->getAliases();
        if ($aliases) {
            $this->write(
                'Aliases: ' . '`'.implode('`, `', $aliases).'`' . "\n\n"
            );
        }

        $this->write("## Usage:\n\n```\n" . $command->getSynopsis() . "\n```\n\n");

        if ($help = $command->getProcessedHelp()) {
            $this->write($help);
            $this->write("\n\n");
        }

        if ($command->getNativeDefinition()) {
            $this->describeInputDefinition($command->getNativeDefinition());
            $this->write("\n\n");
        }

        if ($command instanceof CommandBase && ($examples = $command->getExamples())) {
            $this->write('## Examples');
            $this->write("\n");
            $name = $command->getName();
            $shortName = count($aliases) === 1 ? reset($aliases) : $name;
            foreach ($examples as $arguments => $description) {
                $this->write("\n* $description:  \n  ```\n  {$this->cliExecutableName} $shortName $arguments\n  ```\n");
            }
            $this->write("\n");
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function describeInputArgument(InputArgument $argument, array $options = [])
    {
        $this->write('* **`' . $argument->getName() . "`**");
        $notes = [
            $argument->isRequired() ? "required" : "optional",
        ];
        if ($argument->isArray()) {
            $notes[] = "multiple values allowed";
        }
        $this->write(' (' . implode('; ', $notes) . ')');
        $this->write("  \n  " . $argument->getDescription());
        if (!$argument->isRequired() && $argument->getDefault()) {
            $default = var_export($argument->getDefault(), true);
            $this->write("  \n  Default: `$default``");
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function describeInputOption(InputOption $option, array $options = [])
    {
        $this->write('* **`--' . $option->getName() . "`**");
        if ($shortcut = $option->getShortcut()) {
            $this->write(" (`-" . implode('|-', explode('|', $shortcut)). "`)");
        }
        $notes = [];
        if ($option->isArray()) {
            $notes[] = 'multiple values allowed';
        } elseif ($option->acceptValue()) {
            $notes[] = 'expects a value';
        }
        if ($notes) {
            $this->write(' (' . implode('; ', $notes) . ')');
        }
        if ($description = $option->getDescription()) {
            $this->write("  \n  " . $description);
        }
        if ($option->acceptValue() && $option->getDefault()) {
            $default = var_export($option->getDefault(), true);
            $this->write("  \n  Default: `$default`");
        }
    }
}
