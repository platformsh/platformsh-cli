<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSshCommand extends CommandBase
{
    protected static $defaultName = 'environment:ssh';

    private $api;
    private $config;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        Api $api,
        Config $config,
        Selector $selector,
        Shell $shell,
        Ssh $ssh
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['ssh'])
            ->addArgument('cmd', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'A command to run on the environment.')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the SSH URL only.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Output all SSH URLs (for every app).')
            ->setDescription('SSH to the current environment');

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition, true);
        $this->ssh->configureInput($definition);

        $this->addExample('Open a shell over SSH');
        $this->addExample('List files', 'ls');
        $this->addExample("Monitor the app log (use '--' before options)", 'tail /var/log/app.log -- -n50 -f');
        $envPrefix = $this->config->get('service.env_prefix');
        $this->addExample('Display relationships (use quotes for complex syntax)', "'echo \${$envPrefix}RELATIONSHIPS | base64 --decode'");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $environment = $selection->getEnvironment();

        if ($input->getOption('all')) {
            $output->writeln(array_values($environment->getSshUrls()));

            return 0;
        }

        $container = $this->selector->getSelection($input)->getRemoteContainer();
        $sshUrl = $container->getSshUrl();

        if ($input->getOption('pipe')) {
            $output->write($sshUrl);

            return 0;
        }

        $remoteCommand = $input->getArgument('cmd');
        if (is_array($remoteCommand)) {
            $remoteCommand = implode(' ', $remoteCommand);
        }
        if (!$remoteCommand && $this->runningViaMulti) {
            throw new InvalidArgumentException('The cmd argument is required when running via "multi"');
        }

        $sshOptions = [];
        $command = $this->ssh->getSshCommand($sshOptions);
        if ($this->isTerminal(STDIN)) {
            $command .= ' -t';
        }
        $command .= ' ' . escapeshellarg($sshUrl);
        if ($remoteCommand) {
            $command .= ' ' . escapeshellarg($remoteCommand);
        }

        return $this->shell->executeSimple($command);
    }
}
