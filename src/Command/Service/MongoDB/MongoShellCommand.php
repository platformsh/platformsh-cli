<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Service\MongoDB;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MongoShellCommand extends CommandBase
{
    protected static $defaultName = 'service:mongo:shell';

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
        $this->setAliases(['mongo']);
        $this->setDescription('Use the MongoDB shell');
        $this->addOption('eval', null, InputOption::VALUE_REQUIRED, 'Pass a JavaScript fragment to the shell');

        $definition = $this->getDefinition();
        $this->relationships->configureInput($definition);
        $this->ssh->configureInput($definition);
        $this->selector->addAllOptions($definition);

        $this->addExample('Display collection names', "--eval 'printjson(db.getCollectionNames())'");
    }

    public function canBeRunMultipleTimes(): bool
    {
        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->runningViaMulti) {
            throw new \RuntimeException('The mongo-shell command cannot run via multi');
        }

        $selection = $this->selector->getSelection($input, false, $this->relationships->hasLocalEnvVar());
        $host = $selection->getHost();

        $service = $this->relationships->chooseService($host, $input, $output, ['mongodb']);
        if (!$service) {
            return 1;
        }

        $command = 'mongo ' . $this->relationships->getDbCommandArgs('mongo', $service);

        if ($input->getOption('eval')) {
            $command .= ' --eval ' . OsUtil::escapePosixShellArg($input->getOption('eval'));
        };

        $sshOptions = [];

        if (!$output->isVerbose()) {
            $command .= ' --quiet';
            $sshOptions['LogLevel'] = 'QUIET';
        } elseif ($output->isDebug()) {
            $command .= ' --verbose';
        }

        if ($this->isTerminal(STDIN) && $host instanceof RemoteHost) {
            $host->setExtraSshArgs(['-t']);
        }

        $this->stdErr->writeln(
            sprintf('Connecting to MongoDB service via relationship <info>%s</info> on <info>%s</info>', $service['_relationship_name'], $host->getLabel()),
            OutputInterface::VERBOSITY_VERBOSE
        );

        return $host->runCommandDirect($command);
    }
}
