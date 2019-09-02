<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Service\VariableService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableListCommand extends CommandBase
{
    protected static $defaultName = 'variable:list';

    private $api;
    private $config;
    private $selector;
    private $table;
    private $variableService;

    public function __construct(
        Api $api,
        Config $config,
        Selector $selector,
        Table $table,
        VariableService $variableService
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->table = $table;
        $this->variableService = $variableService;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['variables', 'var'])
            ->setDescription('List variables');

        $definition = $this->getDefinition();
        $this->variableService->addLevelOption($definition);
        $this->table->configureInput($definition);
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $level = $this->variableService->getRequestedLevel($input);

        $selection = $this->selector->getSelection($input, $level === 'project');

        $project = $selection->getProject();

        $variables = [];
        if ($level === 'project' || $level === null) {
            $variables = array_merge($variables, $project->getVariables());
        }
        if ($level === 'environment' || $level === null) {
            $variables = array_merge($variables, $selection->getEnvironment()->getVariables());
        }

        if (empty($variables)) {
            $this->stdErr->writeln('No variables found.');

            return 1;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $projectLabel = $this->api->getProjectLabel($project);
            switch ($level) {
                case 'project':
                    $this->stdErr->writeln(sprintf('Project-level variables on the project %s:', $projectLabel));
                    break;

                case 'environment':
                    $environmentId = $selection->getEnvironment()->id;
                    $this->stdErr->writeln(sprintf('Environment-level variables on the environment <info>%s</info> of project %s:', $environmentId, $projectLabel));
                    break;

                default:
                    $environmentId = $selection->getEnvironment()->id;
                    $this->stdErr->writeln(sprintf('Variables on the project %s, environment <info>%s</info>:', $projectLabel, $environmentId));
                    break;
            }
        }

        $rows = [];

        /** @var \Platformsh\Client\Model\ProjectLevelVariable|\Platformsh\Client\Model\Variable $variable */
        foreach ($variables as $variable) {
            $row = [];
            $row[] = $variable->name;
            $row[] = new AdaptiveTableCell($this->variableService->getVariableLevel($variable), ['wrap' => false]);

            // Handle sensitive variables' value (it isn't exposed in the API).
            if (!$variable->hasProperty('value', false) && $variable->is_sensitive) {
                $row[] = $this->table->formatIsMachineReadable() ? '' : '<fg=yellow>[Hidden: sensitive value]</>';
            } else {
                $row[] = $variable->value;
            }

            $rows[] = $row;
        }

        $this->table->render($rows, ['Name', 'Level', 'Value']);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $executable = $this->config->get('application.executable');
            $this->stdErr->writeln(sprintf(
                'To view variable details, run: <info>%s variable:get [name]</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To create a new variable, run: <info>%s variable:create</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To update a variable, run: <info>%s variable:update [name]</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To delete a variable, run: <info>%s variable:delete [name]</info>',
                $executable
            ));
        }

        return 0;
    }
}
