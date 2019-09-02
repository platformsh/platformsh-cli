<?php
namespace Platformsh\Cli\Command\Tunnel;

use GuzzleHttp\Psr7\Uri;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Console\ProcessManager;
use Platformsh\Cli\Service\TunnelService;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelSingleCommand extends CommandBase
{
    protected static $defaultName = 'tunnel:single';

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
        $this->setDescription('Open a single SSH tunnel to an app relationship')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'The local port');

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition);
        $this->relationshipsService->configureInput($definition);
        $this->ssh->configureInput($definition);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();
        $environment = $selection->getEnvironment();
        $appName = $selection->getAppName();

        $sshUrl = $environment->getSshUrl($appName);

        $relationships = $this->relationshipsService->getRelationships($selection->getHost());
        if (!$relationships) {
            $this->stdErr->writeln('No relationships found.');
            return 1;
        }

        $service = $this->relationshipsService->chooseService($selection->getHost(), $input, $output);
        if (!$service) {
            return 1;
        }

        if ($environment->id === 'master') {
            $confirmText = sprintf(
                'Are you sure you want to open an SSH tunnel to'
                . ' the relationship <comment>%s</comment> on the'
                . ' <comment>%s</comment> (production) environment?',
                $service['_relationship_name'],
                $environment->id
            );
            if (!$this->questionHelper->confirm($confirmText, false)) {
                return 1;
            }
            $this->stdErr->writeln('');
        }

        $sshArgs = $this->ssh->getSshArgs();

        $remoteHost = $service['host'];
        $remotePort = $service['port'];

        if ($localPort = $input->getOption('port')) {
            if (!PortUtil::validatePort($localPort)) {
                $this->stdErr->writeln(sprintf('Invalid port: <error>%s</error>', $localPort));

                return 1;
            }
            if (PortUtil::isPortInUse($localPort)) {
                $this->stdErr->writeln(sprintf('Port already in use: <error>%s</error>', $localPort));

                return 1;
            }
        } else {
            $localPort = $this->tunnelService->getPort();
        }

        $tunnel = [
            'projectId' => $project->id,
            'environmentId' => $environment->id,
            'appName' => $appName,
            'relationship' => $service['_relationship_name'],
            'serviceKey' => $service['_relationship_key'],
            'remotePort' => $remotePort,
            'remoteHost' => $remoteHost,
            'localPort' => $localPort,
            'service' => $service,
            'pid' => null,
        ];

        $relationshipString = $this->tunnelService->formatTunnelRelationship($tunnel);

        if ($openTunnelInfo = $this->tunnelService->isTunnelOpen($tunnel)) {
            $this->stdErr->writeln(sprintf(
                'A tunnel is already open for the relationship <info>%s</info> (on port %s)',
                $relationshipString,
                $openTunnelInfo['localPort']
            ));

            return 1;
        }

        $pidFile = $this->tunnelService->getPidFile($tunnel);

        $processManager = new ProcessManager();
        $process = $this->tunnelService->createTunnelProcess($sshUrl, $remoteHost, $remotePort, $localPort, $sshArgs);
        $pid = $processManager->startProcess($process, $pidFile, $this->stdErr);

        // Wait a very small time to capture any immediate errors.
        usleep(100000);
        if (!$process->isRunning() && !$process->isSuccessful()) {
            $this->stdErr->writeln(trim($process->getErrorOutput()));
            $this->stdErr->writeln(sprintf(
                'Failed to open tunnel for relationship: <error>%s</error>',
                $relationshipString
            ));
            unlink($pidFile);

            return 1;
        }

        $tunnel['pid'] = $pid;
        $this->tunnelService->addTunnelInfo($tunnel);

        $this->stdErr->writeln('');

        $this->stdErr->writeln(sprintf(
            'SSH tunnel opened on port %s to relationship: <info>%s</info>',
            $tunnel['localPort'],
            $relationshipString
        ));

        $localService = array_merge($service, array_intersect_key([
            'host' => TunnelService::LOCAL_IP,
            'port' => $tunnel['localPort'],
        ], $service));
        $info = [
            'username' => 'Username',
            'password' => 'Password',
            'scheme' => 'Scheme',
            'host' => 'Host',
            'port' => 'Port',
            'path' => 'Path',
        ];
        foreach ($info as $key => $category) {
            if (isset($localService[$key])) {
                $this->stdErr->writeln(sprintf('  <info>%s</info>: %s', $category, $localService[$key]));
            }
        }

        $this->stdErr->writeln('');

        if (isset($localService['scheme']) && in_array($localService['scheme'], ['http', 'https'], true)) {
            $this->stdErr->writeln(sprintf('URL: <info>%s</info>', $this->getServiceUrl($localService)));
            $this->stdErr->writeln('');
        }

        $this->stdErr->writeln('Quitting this command (with Ctrl+C or equivalent) will close the tunnel.');

        $this->stdErr->writeln('');

        $processManager->monitor($this->stdErr);

        return $process->isSuccessful() ? 0 : 1;
    }

    /**
     * Build a URL to a service.
     *
     * @param array $service
     *
     * @return string
     */
    private function getServiceUrl(array $service)
    {
        $map = ['username' => 'user', 'password' => 'pass'];
        $urlParts = [];
        foreach ($service as $key => $value) {
            $newKey = isset($map[$key]) ? $map[$key] : $key;
            $urlParts[$newKey] = $value;
        }

        return Uri::fromParts($urlParts);
    }
}
