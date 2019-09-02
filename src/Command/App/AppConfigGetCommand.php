<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Model\Host\LocalHost;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigGetCommand extends CommandBase
{
    protected static $defaultName = 'app:config-get';

    private $api;
    private $config;
    private $selector;
    private $formatter;

    public function __construct(Api $api, Config $config, Selector $selector, PropertyFormatter $formatter)
    {
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->formatter = $formatter;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('View the configuration of an app')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The configuration property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        $this->selector->addAllOptions($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Allow override via PLATFORM_APPLICATION.
        $prefix = $this->config->get('service.env_prefix');
        if (getenv($prefix . 'APPLICATION') && !LocalHost::conflictsWithCommandLineOptions($input, $prefix)) {
            $this->debug('Reading application config from environment variable ' . $prefix . 'APPLICATION');
            $decoded = json_decode(base64_decode(getenv($prefix . 'APPLICATION'), true), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'APPLICATION');
            }
            $appConfig = new AppConfig($decoded);
        } else {
            $appConfig = $this->selector->getSelection($input)
                ->getRemoteContainer()
                ->getConfig();
        }

        $this->formatter->displayData($output, $appConfig->getNormalized(), $input->getOption('property'));
    }
}
