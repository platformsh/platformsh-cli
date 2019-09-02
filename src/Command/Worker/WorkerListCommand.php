<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Worker;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WorkerListCommand extends CommandBase
{
    protected static $defaultName = 'worker:list';

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

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['workers'])
            ->setDescription('Get a list of all deployed workers')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $workers = $this->api
            ->getCurrentDeployment($selection->getEnvironment(), $input->getOption('refresh'))
            ->workers;
        if (empty($workers)) {
            $this->stdErr->writeln('No workers found.');

            return 0;
        }

        $rows = [];
        foreach ($workers as $worker) {
            $commands = isset($worker->worker['commands']) ? $worker->worker['commands'] : [];
            $rows[] = [$worker->name, $worker->type, $this->formatter->format($commands)];
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Workers on the project %s, environment %s:',
                $this->api->getProjectLabel($selection->getProject()),
                $this->api->getEnvironmentLabel($selection->getEnvironment())
            ));
        }

        $this->table->render($rows, ['Name', 'Type', 'Commands']);

        return 0;
    }
}
