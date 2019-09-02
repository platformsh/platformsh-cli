<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotListCommand extends CommandBase
{

    protected static $defaultName = 'snapshot:list';

    private $api;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(
        Api $api,
        PropertyFormatter $formatter,
        Selector $selector,
        Table $table
    ) {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['snapshots'])
            ->setDescription('List available snapshots of an environment')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of snapshots to list', 10);

        $definition = $this->getDefinition();
        $this->table->configureInput($definition);
        $this->formatter->configureInput($definition);
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);

        $this->addExample('List the most recent snapshots');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $selectedEnvironment = $selection->getEnvironment();

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln("Finding snapshots for the environment <info>{$selectedEnvironment->id}</info>");
        }

        $backups = $selectedEnvironment->getBackups();
        if (!$backups) {
            $this->stdErr->writeln('No snapshots found');
            return 1;
        }

        $headers = ['Created', 'Snapshot name', 'Status', 'Commit'];
        $rows = [];
        foreach ($backups as $backup) {
            $rows[] = [
                $this->formatter->format($backup->created_at, 'created_at'),
                new AdaptiveTableCell($backup->id, ['wrap' => false]),
                $backup->status,
                $backup->commit_id,
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(
                sprintf(
                    'Snapshots for the project %s, environment %s:',
                    $this->api->getProjectLabel($selection->getProject()),
                    $this->api->getEnvironmentLabel($selectedEnvironment)
                )
            );
        }

        $this->table->render($rows, $headers);

        return 0;
    }
}
