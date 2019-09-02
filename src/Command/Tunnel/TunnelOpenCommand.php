<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Console\ProcessManager;
use Platformsh\Cli\Service\TunnelService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelOpenCommand extends CommandBase
{
    protected static $defaultName = 'tunnel:open';

    private $config;
    private $questionHelper;
    private $relationshipsService;
    private $selector;
    private $ssh;
    private $tunnelService;

    public function __construct(
        Config $config,
        QuestionHelper $questionHelper,
        Relationships $relationshipsService,
        Selector $selector,
        Ssh $ssh,
        TunnelService $tunnelService
    ) {
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->relationshipsService = $relationshipsService;
        $this->selector = $selector;
        $this->ssh = $ssh;
        $this->tunnelService = $tunnelService;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription("Open SSH tunnels to an app's relationships");

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition);
        $this->ssh->configureInput($this->getDefinition());

        $this->setHelp(<<<EOF
This command opens SSH tunnels to all of the relationships of an application.

Connections can then be made to the application's services as if they were
local, for example a local MySQL client can be used, or the Solr web
administration endpoint can be accessed through a local browser.

This command requires the posix and pcntl PHP extensions (as multiple
background CLI processes are created to keep the SSH tunnels open). The
<info>tunnel:single</info> command can be used on systems without these
extensions.
EOF
        );
    }

    /**
     * {@inheritdoc}
     */
    public function canBeRunMultipleTimes(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->checkSupport();
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();
        $environment = $selection->getEnvironment();

        if ($environment->id === 'master') {
            $confirmText = 'Are you sure you want to open SSH tunnel(s) to the'
                . ' <comment>master</comment> (production) environment?';
            if (!$this->questionHelper->confirm($confirmText, false)) {
                return 1;
            }
            $this->stdErr->writeln('');
        }

        $appName = $selection->getAppName();
        $sshUrl = $environment->getSshUrl($appName);

        $relationships = $this->relationshipsService->getRelationships($selection->getHost());
        if (!$relationships) {
            $this->stdErr->writeln('No relationships found.');
            return 1;
        }

        $logFile = $this->config->getWritableUserDir() . '/tunnels.log';
        if (!$log = $this->tunnelService->openLog($logFile)) {
            $this->stdErr->writeln(sprintf('Failed to open log file for writing: %s', $logFile));
            return 1;
        }

        $sshArgs = $this->ssh->getSshArgs();

        $log->setVerbosity($output->getVerbosity());

        $processManager = new ProcessManager();
        $processManager->fork();

        $error = false;
        $processIds = [];
        foreach ($relationships as $relationship => $services) {
            foreach ($services as $serviceKey => $service) {
                $remoteHost = $service['host'];
                $remotePort = $service['port'];

                $localPort = $this->tunnelService->getPort();
                $tunnel = [
                    'projectId' => $project->id,
                    'environmentId' => $environment->id,
                    'appName' => $appName,
                    'relationship' => $relationship,
                    'serviceKey' => $serviceKey,
                    'remotePort' => $remotePort,
                    'remoteHost' => $remoteHost,
                    'localPort' => $localPort,
                    'service' => $service,
                    'pid' => null,
                ];

                $relationshipString = $this->tunnelService->formatTunnelRelationship($tunnel);

                if ($openTunnelInfo = $this->tunnelService->isTunnelOpen($tunnel)) {
                    $this->stdErr->writeln(sprintf(
                        'A tunnel is already open on port %s for the relationship: <info>%s</info>',
                        $openTunnelInfo['localPort'],
                        $relationshipString
                    ));
                    continue;
                }

                $process = $this->tunnelService->createTunnelProcess($sshUrl, $remoteHost, $remotePort, $localPort, $sshArgs);

                $pidFile = $this->tunnelService->getPidFile($tunnel);

                try {
                    $pid = $processManager->startProcess($process, $pidFile, $log);
                } catch (\Exception $e) {
                    $this->stdErr->writeln(sprintf(
                        'Failed to open tunnel for relationship <error>%s</error>: %s',
                        $relationshipString,
                        $e->getMessage()
                    ));
                    $error = true;
                    continue;
                }

                // Wait a very small time to capture any immediate errors.
                usleep(100000);
                if (!$process->isRunning() && !$process->isSuccessful()) {
                    $this->stdErr->writeln(trim($process->getErrorOutput()));
                    $this->stdErr->writeln(sprintf(
                        'Failed to open tunnel for relationship: <error>%s</error>',
                        $relationshipString
                    ));
                    unlink($pidFile);
                    $error = true;
                    continue;
                }

                // Save information about the tunnel for use in other commands.
                $tunnel['pid'] = $pid;
                $this->tunnelService->addTunnelInfo($tunnel);

                $this->stdErr->writeln(sprintf(
                    'SSH tunnel opened on port <info>%s</info> to relationship: <info>%s</info>',
                    $tunnel['localPort'],
                    $relationshipString
                ));
                $processIds[] = $pid;
            }
        }

        if (count($processIds)) {
            $this->stdErr->writeln("Logs are written to: $logFile");
        }

        if (!$error) {
            $executable = $this->config->get('application.executable');
            $variable = $this->config->get('service.env_prefix') . 'RELATIONSHIPS';
            $this->stdErr->writeln('');
            $this->stdErr->writeln("List tunnels with: <info>$executable tunnels</info>");
            $this->stdErr->writeln("View tunnel details with: <info>$executable tunnel:info</info>");
            $this->stdErr->writeln("Close tunnels with: <info>$executable tunnel:close</info>");
            $this->stdErr->writeln('');
            $this->stdErr->writeln(
                "Save encoded tunnel details to the $variable variable using:"
                . "\n  <info>export $variable=\"$($executable tunnel:info --encode)\"</info>"
            );
        }

        $processManager->killParent($error);

        $processManager->monitor($log);

        return 0;
    }

    private function checkSupport()
    {
        $messages = [];
        foreach (['pcntl', 'posix'] as $extension) {
            if (!extension_loaded($extension)) {
                $messages[] = sprintf('The "%s" extension is required.', $extension);
            }
        }
        if (count($messages)) {
            throw new \RuntimeException(implode("\n", $messages));
        }
    }
}
