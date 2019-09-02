<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Service;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RedisCliCommand extends CommandBase
{
    protected static $defaultName = 'service:redis-cli';

    private $relationships;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        Relationships $relationships,
        Selector $selector,
        Shell $shell,
        Ssh $ssh
    ) {
        $this->relationships = $relationships;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['redis']);
        $this->setDescription('Access the Redis CLI');
        $this->addArgument('args', InputArgument::OPTIONAL, 'Arguments to add to the Redis command');

        $definition = $this->getDefinition();
        $this->relationships->configureInput($definition);
        $this->ssh->configureInput($definition);
        $this->selector->addAllOptions($definition);

        $this->addExample('Open the redis-cli shell');
        $this->addExample('Ping the Redis server', 'ping');
        $this->addExample('Show Redis status information', 'info');
        $this->addExample('Scan keys', "-- --scan");
        $this->addExample('Scan keys matching a pattern', '-- "--scan --pattern \'*-11*\'"');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->runningViaMulti && !$input->getArgument('args')) {
            throw new \RuntimeException('The redis-cli command cannot run as a shell via multi');
        }

        $selection = $this->selector->getSelection($input, false, $this->relationships->hasLocalEnvVar());
        $host = $selection->getHost();

        $service = $this->relationships->chooseService($host, $input, $output, ['redis']);
        if (!$service) {
            return 1;
        }

        $redisCommand = sprintf(
            'redis-cli -h %s -p %d',
            OsUtil::escapePosixShellArg($service['host']),
            $service['port']
        );
        if ($args = $input->getArgument('args')) {
            $redisCommand .= ' ' . $args;
        }

        $this->stdErr->writeln(
            sprintf('Connecting to Redis service via relationship <info>%s</info> on <info>%s</info>', $service['_relationship_name'], $host->getCacheKey())
        );

        if ($this->isTerminal(STDIN) && $host instanceof RemoteHost) {
            $host->setExtraSshArgs(['-t']);
        }

        return $host->runCommandDirect($redisCommand);
    }
}
