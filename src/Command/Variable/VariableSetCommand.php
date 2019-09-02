<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:create and variable:update instead (with --level environment)
 */
class VariableSetCommand extends CommandBase
{
    protected static $defaultName = 'variable:set';

    private $activityService;
    private $selector;

    public function __construct(ActivityService $activityService, Selector $selector)
    {
        $this->activityService = $activityService;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['vset'])
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->addArgument('value', InputArgument::REQUIRED, 'The variable value')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Mark the value as JSON')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Mark the variable as disabled')
            ->setDescription('Set a variable for an environment');
        $this->setHidden(true);

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);

        $this->addExample('Set the variable "example" to the string "123"', 'example 123');
        $this->addExample('Set the variable "example" to the Boolean TRUE', 'example --json true');
        $this->addExample('Set the variable "example" to a list of values', 'example --json \'["value1", "value2"]\'');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $variableName = $input->getArgument('name');
        $variableValue = $input->getArgument('value');
        $json = $input->getOption('json');
        $enabled = !$input->getOption('disabled');

        if ($json && !$this->validateJson($variableValue)) {
            throw new InvalidArgumentException("Invalid JSON: <error>$variableValue</error>");
        }

        // Check whether the variable already exists. If there is no change,
        // quit early.
        $existing = $selection->getEnvironment()
            ->getVariable($variableName);
        if ($existing
            && $existing->value === $variableValue
            && $existing->is_enabled === $enabled
            && $existing->is_json == $json) {
            $this->stdErr->writeln("Variable <info>$variableName</info> already set as: $variableValue");

            return 0;
        }

        // Set the variable to a new value.
        $result = $selection->getEnvironment()
            ->setVariable($variableName, $variableValue, $json, $enabled);

        $this->stdErr->writeln("Variable <info>$variableName</info> set to: $variableValue");

        $success = true;
        if (!$result->countActivities()) {
            $this->activityService->redeployWarning();
        } elseif ($this->activityService->shouldWait($input)) {
            $success = $this->activityService
                ->waitMultiple($result->getActivities(), $selection->getProject());
        }

        return $success ? 0 : 1;
    }

    /**
     * @param $string
     *
     * @return bool
     */
    protected function validateJson($string)
    {
        $null = json_decode($string) === null;

        return !$null || ($null && $string === 'null');
    }
}
