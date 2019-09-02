<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Doctrine\Common\Cache\CacheProvider;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ClearCacheCommand extends CommandBase
{
    protected static $defaultName = 'clear-cache';

    private $cache;

    public function __construct(CacheProvider $cache)
    {
        $this->cache = $cache;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['clearcache', 'cc'])
            ->setDescription('Clear the CLI cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->cache->flushAll();
        $this->stdErr->writeln("All caches have been cleared");
    }
}
