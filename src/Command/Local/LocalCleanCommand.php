<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LocalCleanCommand extends CommandBase
{
    protected static $defaultName = 'local:clean';

    private $localBuild;
    private $localProject;

    public function __construct(
        LocalBuild $localBuild,
        LocalProject $localProject
    ) {
        $this->localBuild = $localBuild;
        $this->localProject = $localProject;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['clean'])
            ->setDescription('Remove old project builds')
            ->addOption(
                'keep',
                null,
                InputOption::VALUE_REQUIRED,
                'The maximum number of builds to keep',
                5
            )
            ->addOption(
                'max-age',
                null,
                InputOption::VALUE_REQUIRED,
                'The maximum age of builds, in seconds. Ignored if not set.'
            )
            ->addOption(
                'include-active',
                null,
                InputOption::VALUE_NONE,
                'Delete active build(s) too'
            );
        $this->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->localProject->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $result = $this->localBuild->cleanBuilds(
            $projectRoot,
            $input->getOption('max-age'),
            $input->getOption('keep'),
            $input->getOption('include-active'),
            false
        );

        if (!$result[0] && !$result[1]) {
            $this->stdErr->writeln("There are no builds to delete");
        } else {
            if ($result[0]) {
                $this->stdErr->writeln("Deleted <info>{$result[0]}</info> build(s)");
            }
            if ($result[1]) {
                $this->stdErr->writeln("Kept <info>{$result[1]}</info> build(s)");
            }
        }

        $archivesResult = $this->localBuild->cleanArchives($projectRoot);
        if ($archivesResult[0]) {
            $this->stdErr->writeln("Deleted <info>{$archivesResult[0]}</info> archive(s)");
        }
    }
}
