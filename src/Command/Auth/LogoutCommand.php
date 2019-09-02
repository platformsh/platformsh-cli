<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Auth;

use Doctrine\Common\Cache\CacheProvider;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LogoutCommand extends CommandBase
{
    protected static $defaultName = 'auth:logout';

    private $api;
    private $cache;
    private $config;
    private $filesystem;
    private $questionHelper;

    public function __construct(
        Api $api,
        CacheProvider $cache,
        Config $config,
        Filesystem $filesystem,
        QuestionHelper $questionHelper
    )
    {
        $this->api = $api;
        $this->cache = $cache;
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->questionHelper = $questionHelper;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['logout'])
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Log out of all sessions')
            ->setDescription('Log out of ' . $this->config->get('service.name'));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Ignore API tokens for this command.
        if ($this->api->hasApiToken()) {
            $this->stdErr->writeln('<comment>Warning: an API token is set</comment>');
        }

        // Log out.
        $this->api->getClient(false)
             ->getConnector()
             ->logOut();

        // Clear the cache.
        $this->cache->flushAll();

        $this->stdErr->writeln('You are now logged out.');

        // Check for other sessions.
        $sessionsDir = $this->config->getSessionDir();
        if ($input->getOption('all')) {
            $this->api->deleteFromKeychain();
            if (is_dir($sessionsDir)) {
                $this->filesystem->remove($sessionsDir);
                $this->stdErr->writeln('All session files have been deleted.');
            }
        } elseif (is_dir($sessionsDir) && glob($sessionsDir . '/sess-cli-*/*', GLOB_NOSORT)) {
            $this->stdErr->writeln(sprintf(
                'Other session files exist. Delete them with: <comment>%s logout --all</comment>',
                $this->config->get('application.executable')
            ));
        }

        return 0;
    }
}
