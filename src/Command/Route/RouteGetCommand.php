<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Route;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RouteGetCommand extends CommandBase
{
    protected static $defaultName = 'route:get';

    private $api;
    private $config;
    private $formatter;
    private $questionHelper;
    private $selector;

    public function __construct(
        Api $api,
        Config $config,
        PropertyFormatter $formatter,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->formatter = $formatter;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('View a resolved route')
            ->addArgument('route', InputArgument::OPTIONAL, "The route's original URL")
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'A route ID to select')
            ->addOption('primary', '1', InputOption::VALUE_NONE, 'Select the primary route')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to display')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Bypass the cache of routes');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->formatter->configureInput($definition);

        $this->addExample('View the URL to the https://{default}/ route', "'https://{default}/' -P url");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Allow override via PLATFORM_ROUTES.
        $prefix = $this->config->get('service.env_prefix');
        if (getenv($prefix . 'ROUTES') && !LocalHost::conflictsWithCommandLineOptions($input, $prefix)) {
            $this->debug('Reading routes from environment variable ' . $prefix . 'ROUTES');
            $decoded = json_decode(base64_decode(getenv($prefix . 'ROUTES'), true), true);
            if (empty($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'ROUTES');
            }
            $routes = Route::fromVariables($decoded);
        } else {
            $this->debug('Reading routes from the API');
            $environment = $this->selector->getSelection($input)->getEnvironment();
            $deployment = $this->api
                ->getCurrentDeployment($environment, $input->getOption('refresh'));
            $routes = Route::fromDeploymentApi($deployment->routes);
        }

        /** @var \Platformsh\Cli\Model\Route|false $selectedRoute */
        $selectedRoute = false;

        $id = $input->getOption('id');
        if (!$selectedRoute && $id !== null) {
            foreach ($routes as $route) {
                if ($route->id === $id) {
                    $selectedRoute = $route;
                    break;
                }
            }
            if (!$selectedRoute) {
                $this->stdErr->writeln(sprintf('No route found with ID: <error>%s</error>', $id));

                return 1;
            }
        }

        if (!$selectedRoute && $input->getOption('primary')) {
            foreach ($routes as $route) {
                if ($route->primary) {
                    $selectedRoute = $route;
                    break;
                }
            }
            if (!$selectedRoute) {
                throw new \RuntimeException('No primary route found.');
            }
        }

        $originalUrl = $input->getArgument('route');
        if (!$selectedRoute && ($originalUrl === null || $originalUrl === '')) {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('You must specify a route via the <comment>route</comment> argument or <comment>--id</comment> option.');

                return 1;
            }
            $items = [];
            foreach ($routes as $route) {
                $originalUrl = $route->original_url;
                $items[$originalUrl] = $originalUrl;
                if (!empty($route->id)) {
                    $items[$originalUrl] .= ' (<info>' . $route->id . '</info>)';
                }
                if ($route->primary) {
                    $items[$originalUrl] .= ' - <info>primary</info>';
                }
            }
            uksort($items, [$this->api, 'urlSort']);
            $originalUrl = $this->questionHelper->choose($items, 'Enter a number to choose a route:');
        }

        if (!$selectedRoute && $originalUrl !== null && $originalUrl !== '') {
            foreach ($routes as $route) {
                if ($route->original_url === $originalUrl) {
                    $selectedRoute = $route;
                    break;
                }
            }

            if (!$selectedRoute) {
                $this->stdErr->writeln(sprintf('No route found for original URL: <comment>%s</comment>', $originalUrl));

                return 1;
            }
        }

        if (!$selectedRoute) {
            $this->stdErr->writeln('No route found.');

            return 1;
        }

        $this->formatter->displayData($output, $selectedRoute->getProperties(), $input->getOption('property'));

        return 0;
    }
}
