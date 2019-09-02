<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\SelfUpdater;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfUpdateCommand extends CommandBase
{
    protected static $defaultName = 'self:update';

    private $config;
    private $updater;

    public function __construct(Config $config, SelfUpdater $updater)
    {
        $this->config = $config;
        $this->updater = $updater;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['self-update'])
            ->setDescription('Update the CLI to the latest version')
            ->addOption('no-major', null, InputOption::VALUE_NONE, 'Only update between minor or patch versions')
            ->addOption('unstable', null, InputOption::VALUE_NONE, 'Update to a new unstable version, if available')
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'Override the manifest file location')
            ->addOption('current-version', null, InputOption::VALUE_REQUIRED, 'Override the current version')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'A timeout for the version check', 30);
        $this->setHiddenAliases(['up', 'update']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manifestUrl = $input->getOption('manifest') ?: $this->config->get('application.manifest_url');
        $currentVersion = $input->getOption('current-version') ?: $this->config->getVersion();

        $this->updater->setAllowMajor(!$input->getOption('no-major'));
        $this->updater->setAllowUnstable((bool) $input->getOption('unstable'));
        $this->updater->setTimeout($input->getOption('timeout'));

        $result = $this->updater->update($manifestUrl, $currentVersion);
        if ($result === false) {
            return 1;
        }

        // Phar cannot load more classes after the update has occurred. So to
        // avoid errors from classes loaded after this (e.g.
        // ConsoleTerminateEvent), we exit directly now.
        exit(0);
    }

    /**
     * {@inheritdoc}
     */
    protected function checkUpdates()
    {
        // Don't check for updates automatically when running self-update.
    }
}
