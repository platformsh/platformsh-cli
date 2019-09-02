<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\VariableService;
use Platformsh\Client\Model\Variable;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableDeleteCommand extends CommandBase
{
    protected static $defaultName = 'variable:delete';

    private $activityService;
    private $api;
    private $questionHelper;
    private $selector;
    private $variableService;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        QuestionHelper $questionHelper,
        Selector $selector,
        VariableService $variableService
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->variableService = $variableService;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->setDescription('Delete a variable');

        $definition = $this->getDefinition();
        $this->variableService->addLevelOption($definition);
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);

        $this->addExample('Delete the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $level = $this->variableService->getRequestedLevel($input);
        $selection = $this->selector->getSelection($input, $level === VariableService::LEVEL_PROJECT);

        $variableName = $input->getArgument('name');

        $variable = $this->variableService->getExistingVariable($selection, $variableName, $level);
        if (!$variable) {
            return 1;
        }

        if (!$variable->operationAvailable('delete')) {
            if ($variable instanceof Variable && $variable->inherited) {
                $this->stdErr->writeln(
                    "The variable <error>$variableName</error> is inherited,"
                    . " so it cannot be deleted from this environment."
                    . "\nYou could override its value with the <comment>variable:update</comment> command."
                );
            } else {
                $this->stdErr->writeln("The variable <error>$variableName</error> cannot be deleted");
            }

            return 1;
        }

        switch ($this->variableService->getVariableLevel($variable)) {
            case 'environment':
                $environmentId = $selection->getEnvironment()->id;
                $confirm = $this->questionHelper->confirm(
                    "Are you sure you want to delete the variable <info>$variableName</info> from the environment <info>$environmentId</info>?",
                    false
                );
                if (!$confirm) {
                    return 1;
                }
                break;

            case 'project':
                $confirm = $this->questionHelper->confirm(
                    "Are you sure you want to delete the variable <info>$variableName</info> from the project " . $this->api->getProjectLabel($selection->getProject()) . "?",
                    false
                );
                if (!$confirm) {
                    return 1;
                }
                break;
        }

        $result = $variable->delete();

        $this->stdErr->writeln("Deleted variable <info>$variableName</info>");

        $success = true;
        if (!$result->countActivities()) {
            $this->activityService->redeployWarning();
        } elseif ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitMultiple($result->getActivities(), $selection->getProject());
        }

        return $success ? 0 : 1;
    }
}
