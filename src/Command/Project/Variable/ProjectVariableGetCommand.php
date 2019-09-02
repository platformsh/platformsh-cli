<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:get and variable:list instead
 */
class ProjectVariableGetCommand extends CommandBase
{
    protected static $defaultName = 'project:variable:get';

    private $table;
    private $selector;
    private $subCommandRunner;

    public function __construct(
        Table $table,
        Selector $selector,
        SubCommandRunner $subCommandRunner
    ) {
        $this->table = $table;
        $this->selector = $selector;
        $this->subCommandRunner = $subCommandRunner;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['project-variables', 'pvget'])
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the variable')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the full variable value only (a "name" must be specified)')
            ->setDescription('View variable(s) for a project');
        $this->setHidden(true);
        $this->selector->addProjectOption($this->getDefinition());
        $this->table->configureInput($this->getDefinition());
        $this->addExample('View the variable "example"', 'example');
        $this->setHiddenAliases(['project:variable:list']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return $this->subCommandRunner->run('variable:get', [
            'name' => $input->getArgument('name'),
            '--level' => 'project',
        ]);
    }
}
