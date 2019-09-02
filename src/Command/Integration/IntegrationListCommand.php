<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Integration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationListCommand extends CommandBase
{
    protected static $defaultName = 'integration:list';

    private $config;
    private $selector;
    private $table;

    public function __construct(
        Config $config,
        Selector $selector,
        Table $table
    ) {
        $this->config = $config;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['integrations'])
            ->setDescription('View a list of project integration(s)');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->table->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $integrations = $project->getIntegrations();
        if (!$integrations) {
            $this->stdErr->writeln('No integrations found');

            return 1;
        }

        $header = ['ID', 'Type', 'Summary'];
        $rows = [];

        foreach ($integrations as $integration) {
            $rows[] = [
                new AdaptiveTableCell($integration->id, ['wrap' => false]),
                $integration->type,
                $this->getIntegrationSummary($integration),
            ];
        }

        $this->table->render($rows, $header);

        $executable = $this->config->get('application.executable');
        $this->stdErr->writeln('');
        $this->stdErr->writeln('View integration details with: <info>' . $executable . ' integration:get [id]</info>');
        $this->stdErr->writeln('');
        $this->stdErr->writeln('Add a new integration with: <info>' . $executable . ' integration:add</info>');
        $this->stdErr->writeln('Delete an integration with: <info>' . $executable . ' integration:delete [id]</info>');

        return 0;
    }

    /**
     * @param Integration $integration
     *
     * @return string
     */
    protected function getIntegrationSummary(Integration $integration)
    {
        $details = $integration->getProperties();
        unset($details['id'], $details['type']);

        switch ($integration->type) {
            case 'github':
            case 'bitbucket':
                $summary = sprintf('Repository: %s', $details['repository']);
                if ($integration->hasLink('#hook')) {
                    $summary .= "\n" . sprintf('Hook URL: %s', $integration->getLink('#hook'));
                }
                break;

            case 'bitbucket_server':
                $summary = sprintf('Project: %s', $details['project']);
                $summary .= "\n" . sprintf('Base URL: %s', $details['url']);
                if ($integration->hasLink('#hook')) {
                    $summary .= "\n" . sprintf('Hook URL: %s', $integration->getLink('#hook'));
                }
                break;

            case 'gitlab':
                $summary = sprintf('Project: %s', $details['project']);
                $summary .= "\n" . sprintf('Base URL: %s', $details['base_url']);
                if ($integration->hasLink('#hook')) {
                    $summary .= "\n" . sprintf('Hook URL: %s', $integration->getLink('#hook'));
                }
                break;

            case 'hipchat':
                $summary = sprintf('Room ID: %s', $details['room']);
                break;

            case 'webhook':
                $summary = sprintf('URL: %s', $details['url']);
                break;

            case 'health.email':
                $summary = sprintf("From: %s\nTo: %s", $details['from_address'], implode(', ', $details['recipients']));
                break;

            case 'health.slack':
                $summary = sprintf('Channel: %s', $details['channel']);
                break;

            case 'health.pagerduty':
                $summary = sprintf('Routing key: %s', $details['routing_key']);
                break;

            default:
                $summary = json_encode($details);
        }

        if (strlen($summary) > 240) {
            $summary = substr($summary, 0, 237) . '...';
        }

        return $summary;
    }
}
