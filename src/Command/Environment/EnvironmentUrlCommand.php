<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentUrlCommand extends CommandBase
{
    protected static $defaultName = 'environment:url';

    private $api;
    private $config;
    private $questionHelper;
    private $selector;
    private $url;

    public function __construct(
        Api $api,
        Config $config,
        QuestionHelper $questionHelper,
        Selector $selector,
        Url $url
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->url = $url;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['url'])
            ->setDescription('Get the public URLs of an environment')
            ->addOption('primary', '1', InputOption::VALUE_NONE, 'Only return the URL for the primary route');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->url->configureInput($definition);

        $this->addExample('Give a choice of URLs to open (or print all URLs if there is no browser)');
        $this->addExample('Print all URLs', '--pipe');
        $this->addExample('Print and/or open the primary route URL', '--primary');
        $this->addExample('Print the primary route URL', '--primary --pipe');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Allow override via PLATFORM_ROUTES.
        $prefix = $this->config->get('service.env_prefix');
        if (getenv($prefix . 'ROUTES') && !LocalHost::conflictsWithCommandLineOptions($input, $prefix)) {
            $this->debug('Reading URLs from environment variable ' . $prefix . 'ROUTES');
            $decoded = json_decode(base64_decode(getenv($prefix . 'ROUTES'), true), true);
            if (empty($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'ROUTES');
            }
            $routes = Route::fromVariables($decoded);
        } else {
            $this->debug('Reading URLs from the API');
            $environment = $this->selector->getSelection($input)
                ->getEnvironment();

            $deployment = $this->api->getCurrentDeployment($environment);
            $routes = Route::fromDeploymentApi($deployment->routes);
        }
        if (empty($routes)) {
            $output->writeln('No URLs found.');

            return 1;
        }

        $primaryUrl = $this->findPrimaryRouteUrl($routes);

        // Handle the --primary option: just display the primary route's URL.
        if ($input->getOption('primary')) {
            if ($primaryUrl === null) {
                $this->stdErr->writeln('No primary route found.');

                return 1;
            }

            $this->displayOrOpenUrls([$primaryUrl], $input, $output);

            return 0;
        }

        // Build a list of all the route URLs.
        $urls = array_map(function (Route $route) {
            return $route->url;
        }, $routes);

        // Sort URLs by preference (HTTPS first, shorter URLs first).
        usort($urls, [$this->api, 'urlSort']);

        // Shift the primary URL to the top of the list.
        if ($primaryUrl !== null) {
            array_unshift($urls, $primaryUrl);
            $urls = array_unique($urls);
        }

        $this->displayOrOpenUrls($urls, $input, $output);

        return 0;
    }

    /**
     * Displays or opens URLs.
     *
     * @param string[]        $urls
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    private function displayOrOpenUrls(array $urls, InputInterface $input, OutputInterface $output)
    {
        // Just display the URLs if --browser is 0 or if --pipe is set.
        if ($input->getOption('pipe') || $input->getOption('browser') === '0') {
            $output->writeln($urls);
            return;
        }
        // Just display the URLs if there is no DISPLAY available or if there
        // is no browser.
        $toDisplay = $urls;
        if (!$input->isInteractive()) {
            // For backwards compatibility, ensure only one URL is output for
            // non-interactive input.
            $toDisplay = $urls[0];
        }
        if (!$this->url->hasDisplay()) {
            $this->debug('Not opening URLs (no display found)');
            $output->writeln($toDisplay);
            return;
        } elseif (!$this->url->canOpenUrls()) {
            $this->debug('Not opening URLs (no browser found)');
            $output->writeln($toDisplay);
            return;
        }

        // Allow the user to choose a URL to open.
        if (count($urls) === 1) {
            $url = $urls[0];
        } else {
            $url = $this->questionHelper->choose(array_combine($urls, $urls), 'Enter a number to open a URL', $urls[0]);
        }

        $this->url->openUrl($url);
    }

    /**
     * Finds the URL of the primary route.
     *
     * @param Route[] $routes
     *
     * @return string|null
     */
    private function findPrimaryRouteUrl(array $routes)
    {
        foreach ($routes as $route) {
            if ($route->primary) {
                return $route->url;
            }
        }

        return null;
    }
}
