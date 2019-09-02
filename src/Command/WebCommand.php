<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WebCommand extends CommandBase
{
    protected static $defaultName = 'web';

    private $api;
    private $config;
    private $selector;
    private $urlService;

    public function __construct(
        Api $api,
        Config $config,
        Selector $selector,
        Url $urlService
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->urlService = $urlService;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Open the Web UI');

        $definition = $this->getDefinition();
        $this->urlService->configureInput($definition);
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // If an environment ID has been specified but not found, then use
        // the specified ID anyway. This allows building a URL when an
        // environment doesn't yet exist.
        $environmentId = $input->getOption('environment');
        $input->setOption('environment', null);

        $selection = $this->selector->getSelection($input, true);
        $project = $selection->getProject();

        if ($environmentId !== null
            && ($environment = $this->api->getEnvironment($environmentId, $project, null, true))) {
            $environmentId = $environment->id;
        }

        $url = $project->getLink('#ui');
        if ($environmentId !== null) {
            // New (alpha) UI links lack the /environments path component.
            if ($this->config->has('detection.console_domain') && parse_url($url, PHP_URL_HOST) === $this->config->get('detection.console_domain')) {
                $url .= '/' . rawurlencode($environmentId);
            } else {
                $url .= '/environments/' . rawurlencode($environmentId);
            }
        }

        $this->urlService->openUrl($url);
    }
}
