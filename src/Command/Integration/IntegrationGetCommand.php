<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\IntegrationService;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationGetCommand extends CommandBase
{
    protected static $defaultName = 'integration:get';

    private $api;
    private $formatter;
    private $integrationService;
    private $questionHelper;
    private $selector;
    private $table;

    public function __construct(
        Api $api,
        IntegrationService $integrationService,
        PropertyFormatter $formatter,
        QuestionHelper $questionHelper,
        Selector $selector,
        Table $table
    ) {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->integrationService = $integrationService;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addOption('property', 'P', InputOption::VALUE_OPTIONAL, 'The integration property to view')
            ->setDescription('View details of an integration');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->table->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $id = $input->getArgument('id');
        if (!$id && !$input->isInteractive()) {
            $this->stdErr->writeln('An integration ID is required.');

            return 1;
        } elseif (!$id) {
            $integrations = $project->getIntegrations();
            if (empty($integrations)) {
                $this->stdErr->writeln('No integrations found.');

                return 1;
            }
            $choices = [];
            foreach ($integrations as $integration) {
                $choices[$integration->id] = sprintf('%s (%s)', $integration->id, $integration->type);
            }
            $id = $this->questionHelper->choose($choices, 'Enter a number to choose an integration:');
        }

        $integration = $project->getIntegration($id);
        if (!$integration) {
            try {
                $integration = $this->api->matchPartialId($id, $project->getIntegrations(), 'Integration');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        if ($property = $input->getOption('property')) {
            if ($property === 'hook_url' && $integration->hasLink('#hook')) {
                $value = $integration->getLink('#hook');
            } else {
                $value = $this->api->getNestedProperty($integration, $property);
            }

            $output->writeln($this->formatter->format($value, $property));

            return 0;
        }

        $this->integrationService->displayIntegration($integration);

        return 0;
    }
}
