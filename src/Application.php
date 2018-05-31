<?php
namespace Platformsh\Cli;

use Platformsh\Cli\Console\EventSubscriber;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Util\TimezoneUtil;
use Symfony\Component\Console\Application as ParentApplication;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Exception\InvalidOptionException as ConsoleInvalidOptionException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Application extends ParentApplication
{
    /**
     * @var ConsoleCommand|null
     */
    protected $currentCommand;

    /** @var Config */
    protected $cliConfig;

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        $this->cliConfig = new Config();
        parent::__construct($this->cliConfig->get('application.name'), $this->cliConfig->get('application.version'));

        // Use the configured timezone, or fall back to the system timezone.
        date_default_timezone_set(
            $this->cliConfig->getWithDefault('application.timezone', null)
                ?: TimezoneUtil::getTimezone()
        );

        $this->addCommands($this->getCommands());

        $this->setDefaultCommand('welcome');

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new EventSubscriber($this->cliConfig));
        $this->setDispatcher($dispatcher);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultInputDefinition()
    {
        // We remove the confusing `--ansi` and `--no-ansi` options.
        return new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this application version'),
            new InputOption('--yes', '-y', InputOption::VALUE_NONE, 'Answer "yes" to all prompts; disable interaction'),
            new InputOption('--no', '-n', InputOption::VALUE_NONE, 'Answer "no" to all prompts'),
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function getDefaultCommands()
    {
        // Override the default commands to add a custom HelpCommand and
        // ListCommand.
        return [new Command\HelpCommand(), new Command\ListCommand()];
    }

    /**
     * @return \Symfony\Component\Console\Command\Command[]
     */
    protected function getCommands()
    {
        static $commands = [];
        if (count($commands)) {
            return $commands;
        }

        $commands[] = new Command\BotCommand();
        $commands[] = new Command\ClearCacheCommand();
        $commands[] = new Command\CompletionCommand();
        $commands[] = new Command\DocsCommand();
        $commands[] = new Command\LegacyMigrateCommand();
        $commands[] = new Command\MultiCommand();
        $commands[] = new Command\Activity\ActivityGetCommand();
        $commands[] = new Command\Activity\ActivityListCommand();
        $commands[] = new Command\Activity\ActivityLogCommand();
        $commands[] = new Command\App\AppConfigGetCommand();
        $commands[] = new Command\App\AppListCommand();
        $commands[] = new Command\Auth\AuthInfoCommand();
        $commands[] = new Command\Auth\AuthTokenCommand();
        $commands[] = new Command\Auth\LogoutCommand();
        $commands[] = new Command\Auth\PasswordLoginCommand();
        $commands[] = new Command\Auth\BrowserLoginCommand();
        $commands[] = new Command\Certificate\CertificateAddCommand();
        $commands[] = new Command\Certificate\CertificateDeleteCommand();
        $commands[] = new Command\Certificate\CertificateGetCommand();
        $commands[] = new Command\Certificate\CertificateListCommand();
        $commands[] = new Command\Config\Drupal8Command();
        $commands[] = new Command\Config\Drupal7Command();
        $commands[] = new Command\Config\NodejsCommand();
        $commands[] = new Command\Config\PhpCommand();
        $commands[] = new Command\Db\DbSqlCommand();
        $commands[] = new Command\Db\DbDumpCommand();
        $commands[] = new Command\Db\DbSizeCommand();
        $commands[] = new Command\Domain\DomainAddCommand();
        $commands[] = new Command\Domain\DomainDeleteCommand();
        $commands[] = new Command\Domain\DomainGetCommand();
        $commands[] = new Command\Domain\DomainListCommand();
        $commands[] = new Command\Domain\DomainUpdateCommand();
        $commands[] = new Command\Environment\EnvironmentActivateCommand();
        $commands[] = new Command\Environment\EnvironmentBranchCommand();
        $commands[] = new Command\Environment\EnvironmentCheckoutCommand();
        $commands[] = new Command\Environment\EnvironmentDeleteCommand();
        $commands[] = new Command\Environment\EnvironmentDrushCommand();
        $commands[] = new Command\Environment\EnvironmentHttpAccessCommand();
        $commands[] = new Command\Environment\EnvironmentListCommand();
        $commands[] = new Command\Environment\EnvironmentLogCommand();
        $commands[] = new Command\Environment\EnvironmentInfoCommand();
        $commands[] = new Command\Environment\EnvironmentInitCommand();
        $commands[] = new Command\Environment\EnvironmentMergeCommand();
        $commands[] = new Command\Environment\EnvironmentPushCommand();
        $commands[] = new Command\Environment\EnvironmentRedeployCommand();
        $commands[] = new Command\Environment\EnvironmentRelationshipsCommand();
        $commands[] = new Command\Environment\EnvironmentSshCommand();
        $commands[] = new Command\Environment\EnvironmentSynchronizeCommand();
        $commands[] = new Command\Environment\EnvironmentUrlCommand();
        $commands[] = new Command\Environment\EnvironmentSetRemoteCommand();
        $commands[] = new Command\Integration\IntegrationAddCommand();
        $commands[] = new Command\Integration\IntegrationDeleteCommand();
        $commands[] = new Command\Integration\IntegrationGetCommand();
        $commands[] = new Command\Integration\IntegrationListCommand();
        $commands[] = new Command\Integration\IntegrationUpdateCommand();
        $commands[] = new Command\Local\LocalBuildCommand();
        $commands[] = new Command\Local\LocalCleanCommand();
        $commands[] = new Command\Local\LocalDrushAliasesCommand();
        $commands[] = new Command\Local\LocalDirCommand();
        $commands[] = new Command\Mount\MountListCommand();
        $commands[] = new Command\Mount\MountDownloadCommand();
        $commands[] = new Command\Mount\MountSizeCommand();
        $commands[] = new Command\Mount\MountUploadCommand();
        $commands[] = new Command\Project\ProjectClearBuildCacheCommand();
        $commands[] = new Command\Project\ProjectCurlCommand();
        $commands[] = new Command\Project\ProjectCreateCommand();
        $commands[] = new Command\Project\ProjectDeleteCommand();
        $commands[] = new Command\Project\ProjectGetCommand();
        $commands[] = new Command\Project\ProjectListCommand();
        $commands[] = new Command\Project\ProjectInfoCommand();
        $commands[] = new Command\Project\ProjectSetRemoteCommand();
        $commands[] = new Command\Project\Variable\ProjectVariableDeleteCommand();
        $commands[] = new Command\Project\Variable\ProjectVariableGetCommand();
        $commands[] = new Command\Project\Variable\ProjectVariableSetCommand();
        $commands[] = new Command\Repo\CatCommand();
        $commands[] = new Command\Repo\LsCommand();
        $commands[] = new Command\Route\RouteListCommand();
        $commands[] = new Command\Route\RouteGetCommand();
        $commands[] = new Command\Self\SelfBuildCommand();
        $commands[] = new Command\Self\SelfInstallCommand();
        $commands[] = new Command\Self\SelfUpdateCommand();
        $commands[] = new Command\Self\SelfReleaseCommand();
        $commands[] = new Command\Self\SelfStatsCommand();
        $commands[] = new Command\Server\ServerRunCommand();
        $commands[] = new Command\Server\ServerStartCommand();
        $commands[] = new Command\Server\ServerListCommand();
        $commands[] = new Command\Server\ServerStopCommand();
        $commands[] = new Command\Service\MongoDB\MongoDumpCommand();
        $commands[] = new Command\Service\MongoDB\MongoExportCommand();
        $commands[] = new Command\Service\MongoDB\MongoRestoreCommand();
        $commands[] = new Command\Service\MongoDB\MongoShellCommand();
        $commands[] = new Command\Service\RedisCliCommand();
        $commands[] = new Command\Snapshot\SnapshotCreateCommand();
        $commands[] = new Command\Snapshot\SnapshotListCommand();
        $commands[] = new Command\Snapshot\SnapshotRestoreCommand();
        $commands[] = new Command\SshKey\SshKeyAddCommand();
        $commands[] = new Command\SshKey\SshKeyDeleteCommand();
        $commands[] = new Command\SshKey\SshKeyListCommand();
        $commands[] = new Command\SubscriptionInfoCommand();
        $commands[] = new Command\Tunnel\TunnelCloseCommand();
        $commands[] = new Command\Tunnel\TunnelInfoCommand();
        $commands[] = new Command\Tunnel\TunnelListCommand();
        $commands[] = new Command\Tunnel\TunnelOpenCommand();
        $commands[] = new Command\User\UserAddCommand();
        $commands[] = new Command\User\UserDeleteCommand();
        $commands[] = new Command\User\UserListCommand();
        $commands[] = new Command\User\UserRoleCommand();
        $commands[] = new Command\Variable\VariableCreateCommand();
        $commands[] = new Command\Variable\VariableDeleteCommand();
        $commands[] = new Command\Variable\VariableDisableCommand();
        $commands[] = new Command\Variable\VariableEnableCommand();
        $commands[] = new Command\Variable\VariableGetCommand();
        $commands[] = new Command\Variable\VariableListCommand();
        $commands[] = new Command\Variable\VariableSetCommand();
        $commands[] = new Command\Variable\VariableUpdateCommand();
        $commands[] = new Command\WelcomeCommand();
        $commands[] = new Command\WebCommand();
        $commands[] = new Command\Worker\WorkerListCommand();

        return $commands;
    }

    /**
     * @inheritdoc
     */
    public function getHelp()
    {
        $messages = [
            $this->getLongVersion(),
            '',
            '<comment>Global options:</comment>',
        ];

        foreach ($this->getDefinition()
                      ->getOptions() as $option) {
            $messages[] = sprintf(
                '  %-29s %s %s',
                '<info>--' . $option->getName() . '</info>',
                $option->getShortcut() ? '<info>-' . $option->getShortcut() . '</info>' : '  ',
                $option->getDescription()
            );
        }

        return implode(PHP_EOL, $messages);
    }

    /**
     * {@inheritdoc}
     */
    protected function configureIO(InputInterface $input, OutputInterface $output)
    {
        // Set the input to non-interactive if the yes or no options are used.
        if ($input->hasParameterOption(['--yes', '-y', '--no', '-n'])) {
            $input->setInteractive(false);
        }

        // Allow the CLICOLOR_FORCE environment variable to override whether
        // colors are used in the output.
        /* @see StreamOutput::hasColorSupport() */
        if (getenv('CLICOLOR_FORCE') === '0') {
            $output->setDecorated(false);
        } elseif (getenv('CLICOLOR_FORCE') === '1') {
            $output->setDecorated(true);
        }

        parent::configureIO($input, $output);
    }

    /**
     * {@inheritdoc}
     */
    protected function doRunCommand(ConsoleCommand $command, InputInterface $input, OutputInterface $output)
    {
        $this->setCurrentCommand($command);

        // Build the command synopsis early, so it doesn't include default
        // options and arguments (such as --help and <command>).
        // @todo find a better solution for this?
        $this->currentCommand->getSynopsis();

        return parent::doRunCommand($command, $input, $output);
    }

    /**
     * Set the current command. This is used for error handling.
     *
     * @param ConsoleCommand|null $command
     */
    public function setCurrentCommand(ConsoleCommand $command = null)
    {
        // The parent class has a similar (private) property named
        // $runningCommand.
        $this->currentCommand = $command;
    }

    /**
     * {@inheritdoc}
     */
    public function renderException(\Exception $e, OutputInterface $output)
    {
        $output->writeln('', OutputInterface::VERBOSITY_QUIET);
        $main = $e;

        do {
            $exceptionName = get_class($e);
            if (($pos = strrpos($exceptionName, '\\')) !== false) {
                $exceptionName = substr($exceptionName, $pos + 1);
            }
            $title = sprintf('  [%s]  ', $exceptionName);

            $len = strlen($title);

            $width = (new Terminal())->getWidth() - 1;
            $formatter = $output->getFormatter();
            $lines = array();
            foreach (preg_split('/\r?\n/', $e->getMessage()) as $line) {
                foreach (str_split($line, $width - 4) as $chunk) {
                    // pre-format lines to get the right string length
                    $lineLength = strlen(preg_replace('/\[[^m]*m/', '', $formatter->format($chunk))) + 4;
                    $lines[] = array($chunk, $lineLength);

                    $len = max($lineLength, $len);
                }
            }

            $messages = array();
            $messages[] = $emptyLine = $formatter->format(sprintf('<error>%s</error>', str_repeat(' ', $len)));
            $messages[] = $formatter->format(sprintf('<error>%s%s</error>', $title, str_repeat(' ', max(0, $len - strlen($title)))));
            foreach ($lines as $line) {
                $messages[] = $formatter->format(sprintf('<error>  %s  %s</error>', $line[0], str_repeat(' ', $len - $line[1])));
            }
            $messages[] = $emptyLine;
            $messages[] = '';

            $output->writeln($messages, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);

            if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
                $output->writeln('<comment>Exception trace:</comment>', OutputInterface::VERBOSITY_QUIET);

                // exception related properties
                $trace = $e->getTrace();
                array_unshift($trace, array(
                    'function' => '',
                    'file' => $e->getFile() !== null ? $e->getFile() : 'n/a',
                    'line' => $e->getLine() !== null ? $e->getLine() : 'n/a',
                    'args' => array(),
                ));

                for ($i = 0, $count = count($trace); $i < $count; ++$i) {
                    $class = isset($trace[$i]['class']) ? $trace[$i]['class'] : '';
                    $type = isset($trace[$i]['type']) ? $trace[$i]['type'] : '';
                    $function = $trace[$i]['function'];
                    $file = isset($trace[$i]['file']) ? $trace[$i]['file'] : 'n/a';
                    $line = isset($trace[$i]['line']) ? $trace[$i]['line'] : 'n/a';

                    $output->writeln(sprintf(' %s%s%s() at <info>%s:%s</info>', $class, $type, $function, $file, $line), OutputInterface::VERBOSITY_QUIET);
                }

                $output->writeln('', OutputInterface::VERBOSITY_QUIET);
            }
        } while ($e = $e->getPrevious());

        if (isset($this->currentCommand)
            && $this->currentCommand->getName() !== 'welcome'
            && ($main instanceof ConsoleInvalidArgumentException
                || $main instanceof ConsoleInvalidOptionException
                || $main instanceof ConsoleRuntimeException
            )) {
            $output->writeln(
                sprintf('Usage: <info>%s</info>', $this->currentCommand->getSynopsis()),
                OutputInterface::VERBOSITY_QUIET
            );
            $output->writeln('', OutputInterface::VERBOSITY_QUIET);
            $output->writeln(sprintf(
                'For more information, type: <info>%s help %s</info>',
                $this->cliConfig->get('application.executable'),
                $this->currentCommand->getName()
            ), OutputInterface::VERBOSITY_QUIET);
            $output->writeln('', OutputInterface::VERBOSITY_QUIET);
        }
    }
}
