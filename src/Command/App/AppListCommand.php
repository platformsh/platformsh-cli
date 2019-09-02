<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppListCommand extends CommandBase
{
    protected static $defaultName = 'app:list';

    private $api;
    private $config;
    private $selector;
    private $table;

    public function __construct(Api $api, Config $config, Selector $selector, Table $table)
    {
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
        $this->setAliases(['apps'])
            ->setDescription('List apps in the project')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        // Find a list of deployed web apps.
        $deployment = $this->api
            ->getCurrentDeployment($selection->getEnvironment(), $input->getOption('refresh'));
        $apps = $deployment->webapps;

        if (!count($apps)) {
            $this->stdErr->writeln('No applications found.');

            if ($deployment->services) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'To list services, run: <info>%s services</info>',
                    $this->config->get('application.executable')
                ));
            }

            return 0;
        }

        // Determine whether to show the "Local path" of the app. This will be
        // found via matching the remote, deployed app with one in the local
        // project.
        // @todo The "Local path" column is mainly here for legacy reasons, and can be removed in a future version.
        $showLocalPath = false;
        $localApps = [];
        if (($projectRoot = $this->selector->getProjectRoot()) && $this->selector->getCurrentProject()->id === $selection->getProject()->id) {
            $localApps = LocalApplication::getApplications($projectRoot, $this->config);
            $showLocalPath = true;
        }
        // Get the local path for a given application.
        $getLocalPath = function ($appName) use ($localApps) {
            foreach ($localApps as $localApp) {
                if ($localApp->getName() === $appName) {
                    return $localApp->getRoot();
                    break;
                }
            }

            return '';
        };

        $headers = ['Name', 'Type', 'disk' => 'Disk (MiB)', 'Size'];
        $defaultColumns = ['name', 'type'];
        if ($showLocalPath) {
            $headers['path'] = 'Path';
            $defaultColumns[] = 'Path';
        }

        $rows = [];
        foreach ($apps as $app) {
            $row = [$app->name, $app->type, 'disk' => $app->disk, $app->size];
            if ($showLocalPath) {
                $row['path'] = $getLocalPath($app->name);
            }
            $rows[] = $row;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Applications on the project <info>%s</info>, environment <info>%s</info>:',
                $this->api->getProjectLabel($selection->getProject()),
                $this->api->getEnvironmentLabel($selection->getEnvironment())
            ));
        }

        $this->table->render($rows, $headers, $defaultColumns);

        if (!$this->table->formatIsMachineReadable() && $deployment->services) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To list services, run: <info>%s services</info>',
                $this->config->get('application.executable')
            ));
        }

        return 0;
    }
}
