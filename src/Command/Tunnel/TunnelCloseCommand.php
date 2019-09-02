<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\TunnelService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelCloseCommand extends CommandBase
{
    protected static $defaultName = 'tunnel:close';

    private $questionHelper;
    private $selector;
    private $tunnelService;

    public function __construct(
        QuestionHelper $questionHelper,
        Selector $selector,
        TunnelService $tunnelService
    ) {
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->tunnelService = $tunnelService;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Close SSH tunnels')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Close all tunnels');
        $this->selector->addAllOptions($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    public function canBeRunMultipleTimes(): bool
    {
        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tunnels = $this->tunnelService->getTunnelInfo();
        $allTunnelsCount = count($tunnels);
        if (!$allTunnelsCount) {
            $this->stdErr->writeln('No tunnels found.');
            return 1;
        }

        // Filter tunnels according to the current project and environment, if
        // available.
        if (!$input->getOption('all')) {
            $tunnels = $this->tunnelService->filterTunnels($tunnels, $this->selector->getSelection($input));
            if (!count($tunnels)) {
                $this->stdErr->writeln('No tunnels found. Use --all to close all tunnels.');
                return 1;
            }
        }

        $error = false;
        foreach ($tunnels as $tunnel) {
            $relationshipString = $this->tunnelService->formatTunnelRelationship($tunnel);
            $appString = $tunnel['projectId'] . '-' . $tunnel['environmentId'];
            if ($tunnel['appName']) {
                $appString .= '--' . $tunnel['appName'];
            }
            $questionText = sprintf(
                'Close tunnel to relationship <comment>%s</comment> on %s?',
                $relationshipString,
                $appString
            );
            if ($this->questionHelper->confirm($questionText)) {
                if ($this->tunnelService->closeTunnel($tunnel)) {
                    $this->stdErr->writeln(sprintf(
                        'Closed tunnel to <info>%s</info> on %s',
                        $relationshipString,
                        $appString
                    ));
                } else {
                    $error = true;
                    $this->stdErr->writeln(sprintf(
                        'Failed to close tunnel to <error>%s</error> on %s',
                        $relationshipString,
                        $appString
                    ));
                }
            }
        }

        if (!$input->getOption('all') && count($tunnels) < $allTunnelsCount) {
            $this->stdErr->writeln('Use --all to close all tunnels.');
        }

        return $error ? 1 : 0;
    }
}
