<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Route;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RouteListCommand extends CommandBase
{
    protected static $defaultName = 'route:list';

    private $api;
    private $config;
    private $selector;
    private $table;

    public function __construct(
        Api $api,
        Config $config,
        Selector $selector,
        Table $table
    ) {
        $this->api = $api;
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
        $this->setAliases(['routes'])
            ->setDescription('List all routes for an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment ID');
        $this->setHiddenAliases(['environment:routes']);

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Allow override via PLATFORM_ROUTES.
        $prefix = $this->config->get('service.env_prefix');
        if (getenv($prefix . 'ROUTES') && !LocalHost::conflictsWithCommandLineOptions($input, $prefix)) {
            $this->debug('Reading routes from environment variable ' . $prefix . 'ROUTES');
            $decoded = json_decode(base64_decode(getenv($prefix . 'ROUTES'), true), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'ROUTES');
            }
            $routes = Route::fromVariables($decoded);
            $fromEnv = true;
        } else {
            $this->debug('Reading routes from the API');
            $selection = $this->selector->getSelection($input);
            $routes = Route::fromEnvironmentApi($selection->getEnvironment()->getRoutes());
            $fromEnv = false;
        }
        if (empty($routes)) {
            $this->stdErr->writeln("No routes found");

            return 0;
        }

        $header = ['Route', 'Type', 'To'];
        $rows = [];
        foreach ($routes as $route) {
            $rows[] = [
                new AdaptiveTableCell($route->original_url, ['wrap' => false]),
                $route->type,
                $route->type == 'upstream' ? $route->upstream : $route->to,
            ];
        }


        if (!$this->table->formatIsMachineReadable()) {
            if ($fromEnv) {
                $this->stdErr->writeln('Routes in the <info>' . $prefix . 'ROUTES</info> environment variable:');
            }
            if (isset($selection) && !$fromEnv) {
                $this->stdErr->writeln(sprintf(
                    'Routes on the project %s, environment %s:',
                    $this->api->getProjectLabel($selection->getProject()),
                    $this->api->getEnvironmentLabel($selection->getEnvironment())
                ));
            }
        }

        $this->table->render($rows, $header);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view a single route, run: <info>%s route:get <route></info>',
                $this->config->get('application.executable')
            ));
        }

        return 0;
    }
}
