<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbSqlCommand extends CommandBase
{
    protected static $defaultName = 'db:sql';

    private $api;
    private $questionHelper;
    private $relationships;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        Api $api,
        QuestionHelper $questionHelper,
        Relationships $relationships,
        Selector $selector,
        Shell $shell,
        Ssh $ssh
    ) {
        $this->api = $api;
        $this->questionHelper = $questionHelper;
        $this->relationships = $relationships;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['sql'])
            ->setDescription('Run SQL on the remote database')
            ->addArgument('query', InputArgument::OPTIONAL, 'An SQL statement to execute')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Produce raw, non-tabular output')
            ->addOption('schema', null, InputOption::VALUE_REQUIRED, 'The schema to dump. Omit to use the default schema (usually "main"). Pass an empty string to not use any schema.');

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition);
        $this->relationships->configureInput($definition);
        $this->ssh->configureInput($definition);

        $this->addExample('Open an SQL console on the remote database');
        $this->addExample('View tables on the remote database', "'SHOW TABLES'");
        $this->addExample('Import a dump file into the remote database', '< dump.sql');
        $this->setHiddenAliases(['environment:sql']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$input->getArgument('query') && $this->runningViaMulti) {
            throw new InvalidArgumentException('The query argument is required when running via "multi"');
        }

        $selection = $this->selector->getSelection($input, false, $this->relationships->hasLocalEnvVar());
        $database = $this->relationships->chooseDatabase($selection->getHost(), $input, $output);
        if (empty($database)) {
            return 1;
        }

        $schema = $input->getOption('schema');
        if ($schema === null) {
            if ($selection->hasEnvironment()) {
                // Get information about the deployed service associated with the
                // selected relationship.
                $deployment = $this->api->getCurrentDeployment($selection->getEnvironment());
                $service = isset($database['service']) ? $deployment->getService($database['service']) : false;
            } else {
                $service = false;
            }

            // Get a list of schemas from the service configuration.
            $schemas = [];
            if ($service) {
                $schemas = !empty($service->configuration['schemas'])
                    ? $service->configuration['schemas']
                    : ['main'];
            }

            // Filter the list by the schemas accessible from the endpoint.
            if (isset($database['rel'])
                && $service
                && isset($service->configuration['endpoints'][$database['rel']]['privileges'])) {
                $schemas = array_intersect(
                    $schemas,
                    array_keys($service->configuration['endpoints'][$database['rel']]['privileges'])
                );
            }

            // If the database path is not in the list of schemas, we have to
            // use that.
            if (!empty($database['path']) && !in_array($database['path'], $schemas, true)) {
                $schema = $database['path'];
            } elseif (count($schemas) === 1) {
                $schema = reset($schemas);
            } else {
                // Provide the user with a choice of schemas.
                $choices = [];
                $schemas[] = '(none)';
                $default = ($database['path'] ?: '(none)');
                foreach ($schemas as $schema) {
                    $choices[$schema] = $schema;
                    if ($schema === $default) {
                        $choices[$schema] .= ' (default)';
                    }
                }
                $schema = $this->questionHelper->choose($choices, 'Enter a number to choose a schema:', $default . ' (default)', true);
                $schema = $schema === '(none)' ? '' : $schema;
            }
        }

        $query = $input->getArgument('query');

        switch ($database['scheme']) {
            case 'pgsql':
                $sqlCommand = 'psql ' . $this->relationships->getDbCommandArgs('psql', $database, $schema);
                if ($query) {
                    if ($input->getOption('raw')) {
                        $sqlCommand .= ' -t';
                    }
                    $sqlCommand .= ' -c ' . OsUtil::escapePosixShellArg($query);
                }
                break;

            default:
                $sqlCommand = 'mysql --no-auto-rehash ' . $this->relationships->getDbCommandArgs('mysql', $database, $schema);
                if ($query) {
                    if ($input->getOption('raw')) {
                        $sqlCommand .= ' --batch --raw';
                    }
                    $sqlCommand .= ' --execute ' . OsUtil::escapePosixShellArg($query);
                }
                break;
        }

        $host = $selection->getHost();

        if ($host instanceof RemoteHost && $this->isTerminal(STDIN)) {
            $host->setExtraSshArgs(['-t']);
        }

        return $host->runCommandDirect($sqlCommand);
    }
}
