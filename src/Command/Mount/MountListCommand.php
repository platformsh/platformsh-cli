<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Mount;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountListCommand extends CommandBase
{

    protected static $defaultName = 'mount:list';

    private $formatter;
    private $mountService;
    private $selector;
    private $table;

    public function __construct(
        PropertyFormatter $formatter,
        Mount $mountService,
        Selector $selector,
        Table $table
    ) {
        $this->formatter = $formatter;
        $this->mountService = $mountService;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['mounts'])
            ->setDescription('Get a list of mounts')
            ->addOption('paths', null, InputOption::VALUE_NONE, 'Output the mount paths only (one per line)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');

        $this->selector->addAllOptions($this->getDefinition(), true);
        $this->table->configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $container = $this->selector->getSelection($input)->getRemoteContainer();
        $mounts = $container->getMounts();

        if (empty($mounts)) {
            $this->stdErr->writeln(sprintf('The %s "%s" doesn\'t define any mounts.', $container->getType(), $container->getName()));

            return 1;
        }
        $mounts = $this->mountService->normalizeMounts($mounts);

        if ($input->getOption('paths')) {
            $output->writeln(array_keys($mounts));

            return 0;
        }

        $header = ['path' => 'Mount path', 'definition' => 'Definition'];
        $rows = [];
        foreach ($mounts as $path => $definition) {
            $rows[] = [
                'path' => $path,
                'definition' => $this->formatter->format($definition),
            ];
        }

        $this->stdErr->writeln(sprintf(
            'Mounts in the %s <info>%s</info> (environment <info>%s</info>):',
            $container->getType(),
            $container->getName(),
            $selection->getEnvironment()->id
        ));
        $this->table->render($rows, $header);

        return 0;
    }
}
