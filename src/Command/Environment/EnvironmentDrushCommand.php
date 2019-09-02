<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\RemoteEnvVars;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

class EnvironmentDrushCommand extends CommandBase
{
    protected static $defaultName = 'environment:drush';

    private $api;
    private $config;
    private $remoteEnvVars;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        Api $api,
        Config $config,
        RemoteEnvVars $remoteEnvVars,
        Selector $selector,
        Shell $shell,
        Ssh $ssh
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->remoteEnvVars = $remoteEnvVars;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['drush'])
            ->setDescription('Run a drush command on the remote environment')
            ->addArgument('cmd', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'A command to pass to Drush', ['status']);

        $definition = $this->getDefinition();
        $this->selector->addEnvironmentOption($definition);
        $this->selector->addProjectOption($definition);
        $this->ssh->configureInput($definition);

        $this->addExample('Run "drush status" on the remote environment', 'status');
        $this->addExample('Enable the Overlay module on the remote environment', 'en overlay');
        $this->addExample('Get a one-time login link for name@example.com (use quotes for complex commands)', "'user-login --mail=name@example.com'");
    }

    public function isHidden()
    {
        // Hide this command in the list if the project is not Drupal.
        $projectRoot = $this->selector->getProjectRoot();
        if ($projectRoot && !Drupal::isDrupal($projectRoot)) {
            return true;
        }

        return parent::isHidden();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $drushCommand = $input->getArgument('cmd');
        if (is_array($drushCommand)) {
            $drushCommand = implode(' ', $drushCommand);
        }

        // Pass through options that the CLI shares with Drush.
        foreach (['yes', 'no', 'quiet'] as $option) {
            if ($input->getOption($option) && !preg_match('/\b' . preg_quote($option) . '\b/', $drushCommand)) {
                $drushCommand .= " --$option";
            }
        }
        if (!preg_match('/\b((verbose|debug|quiet)\b|-v)/', $drushCommand)) {
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG ) {
                $drushCommand .= " --debug";
            } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $drushCommand .= " --verbose";
            } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $drushCommand .= " --verbose";
            } elseif ($output->getVerbosity() == OutputInterface::VERBOSITY_QUIET) {
                $drushCommand .= " --quiet";
            }
        }

        $deployment = $this->api->getCurrentDeployment($selection->getEnvironment());

        // Use the PLATFORM_DOCUMENT_ROOT environment variable, if set, to
        // determine the path to Drupal.
        $documentRoot = $this->remoteEnvVars->getEnvVar('DOCUMENT_ROOT', $selection->getHost());
        if ($documentRoot !== '') {
            $drupalRoot = $documentRoot;
        } else {
            // Fall back to a combination of the document root (from the
            // deployment configuration) and the PLATFORM_APP_DIR variable.
            $appDir = $this->remoteEnvVars->getEnvVar('APP_DIR', $selection->getHost()) ?: '/app';

            $remoteApp = $deployment->getWebApp($selection->getAppName());
            $relativeDocRoot = AppConfig::fromWebApp($remoteApp)->getDocumentRoot();

            $drupalRoot = $appDir . '/' . $relativeDocRoot;
        }

        $columns = (new Terminal())->getWidth();

        $command = "COLUMNS=$columns drush --root=" . OsUtil::escapePosixShellArg($drupalRoot);
        if ($siteUrl = $this->api->getSiteUrl($selection->getEnvironment(), $selection->getAppName(), $deployment)) {
            $command .= " --uri=" . OsUtil::escapePosixShellArg($siteUrl);
        }
        $command .= ' ' . $drushCommand;

        return $selection->getHost()->runCommandDirect($command);
    }
}
