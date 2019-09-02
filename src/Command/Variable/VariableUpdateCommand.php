<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\VariableService;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableUpdateCommand extends CommandBase
{
    /** @var Form */
    private $form;

    protected static $defaultName = 'variable:update';

    private $activityService;
    private $api;
    private $config;
    private $questionHelper;
    private $selector;
    private $variableService;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        Config $config,
        QuestionHelper $questionHelper,
        Selector $selector,
        VariableService $variableService
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->config = $config;
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
        $this->setDescription('Update a variable')
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name');

        $fields = $this->variableService->getFields();
        unset($fields['name'], $fields['prefix'], $fields['environment'], $fields['level']);
        $this->form = Form::fromArray($fields);

        $definition = $this->getDefinition();
        $this->variableService->addLevelOption($definition);
        $this->form->configureInputDefinition($definition);
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $level = $this->variableService->getRequestedLevel($input);
        $selection = $this->selector->getSelection($input, $level === VariableService::LEVEL_PROJECT);

        $name = $input->getArgument('name');
        $variable = $this->variableService->getExistingVariable($selection, $name, $level);
        if (!$variable) {
            return 1;
        }

        $values = [];
        $fields = $this->form->getFields();
        foreach ($variable->getProperties() as $property => $value) {
            if (isset($fields[$property])) {
                $newValue = $fields[$property]->getValueFromInput($input);
                if ($newValue !== null && $newValue !== $value) {
                    $values[$property] = $newValue;
                }
            }
        }

        // Handle sensitive variables' value (it isn't exposed in the API).
        if (!$variable->hasProperty('value') && $variable->is_sensitive) {
            $newValue = $fields['value']->getValueFromInput($input);
            if ($newValue !== null) {
                $values['value'] = $newValue;
            }
        }

        // Validate the is_json setting against the value.
        if ((isset($variable->value) || isset($values['value']))
            && (!empty($values['is_json']) || $variable->is_json)) {
            $value = isset($values['value']) ? $values['value'] : $variable->value;
            if (json_decode($value) === null && json_last_error()) {
                $this->stdErr->writeln('The value is not valid JSON: <error>' . $value . '</error>');

                return 1;
            }
        }

        if (!$values) {
            $this->stdErr->writeln('No changes were provided.');

            return 1;
        }

        $result = $variable->update($values);
        $this->stdErr->writeln("Variable <info>{$variable->name}</info> updated");

        $this->variableService->displayVariable($variable);

        $success = true;
        if (!$result->countActivities()) {
            $this->activityService->redeployWarning();
        } elseif ($this->activityService->shouldWait($input)) {
            $success = $this->activityService
                ->waitMultiple($result->getActivities(), $selection->getProject());
        }

        return $success ? 0 : 1;
    }
}
