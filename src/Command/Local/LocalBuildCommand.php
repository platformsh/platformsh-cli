<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\QuestionHelper;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LocalBuildCommand extends CommandBase
{
    protected static $defaultName = 'local:build';

    private $config;
    private $questionHelper;
    private $filesystem;
    private $localBuild;
    private $localProject;

    public function __construct(
        Config $config,
        QuestionHelper $questionHelper,
        Filesystem $filesystem,
        LocalBuild $localBuild,
        LocalProject $localProject
    ) {
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->filesystem = $filesystem;
        $this->localBuild = $localBuild;
        $this->localProject = $localProject;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['build'])
            ->addArgument('app', InputArgument::IS_ARRAY, 'Specify application(s) to build')
            ->setDescription('Build the current project locally')
            ->addOption(
                'abslinks',
                'a',
                InputOption::VALUE_NONE,
                'Use absolute links'
            )
            ->addOption(
                'source',
                's',
                InputOption::VALUE_REQUIRED,
                'The source directory. Defaults to the current project root.'
            )
            ->addOption(
                'destination',
                'd',
                InputOption::VALUE_REQUIRED,
                'The destination, to which the web root of each app will be symlinked. Default: ' . $this->config->get('local.web_root')
            )
            ->addOption(
                'copy',
                'c',
                InputOption::VALUE_NONE,
                'Copy to a build directory, instead of symlinking from the source'
            )
            ->addOption(
                'clone',
                null,
                InputOption::VALUE_NONE,
                'Use Git to clone the current HEAD to the build directory'
            )
            ->addOption(
                'run-deploy-hooks',
                null,
                InputOption::VALUE_NONE,
                'Run deploy and/or post_deploy hooks'
            )
            ->addOption(
                'no-clean',
                null,
                InputOption::VALUE_NONE,
                'Do not remove old builds'
            )
            ->addOption(
                'no-archive',
                null,
                InputOption::VALUE_NONE,
                'Do not create or use a build archive'
            )
            ->addOption(
                'no-backup',
                null,
                InputOption::VALUE_NONE,
                'Do not back up the previous build'
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable caching'
            )
            ->addOption(
                'no-build-hooks',
                null,
                InputOption::VALUE_NONE,
                'Do not run post-build hooks'
            )
            ->addOption(
                'no-deps',
                null,
                InputOption::VALUE_NONE,
                'Do not install build dependencies locally'
            )
            ->addOption(
                'working-copy',
                null,
                InputOption::VALUE_NONE,
                'Drush: use git to clone a repository of each Drupal module rather than simply downloading a version'
            )
            ->addOption(
                'concurrency',
                null,
                InputOption::VALUE_REQUIRED,
                'Drush: set the number of concurrent projects that will be processed at the same time',
                4
            )
            ->addOption(
                'lock',
                null,
                InputOption::VALUE_NONE,
                'Drush: create or update a lock file (only available with Drush version 7+)'
            );
        $this->addExample('Build the current project');
        $this->addExample('Build the app "example" without symlinking the source files', 'example --copy');
        $this->addExample('Rebuild the current project without using an archive', '--no-archive');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->localProject->getProjectRoot();

        $sourceDirOption = $input->getOption('source');

        // If no project root is found, ask the user for a source directory.
        if (!$projectRoot && !$sourceDirOption && $input->isInteractive()) {
            $default = file_exists($this->config->get('service.app_config_file')) || is_dir('.git') ? '.' : null;
            $sourceDirOption = $this->questionHelper->askInput('Source directory', $default);
        }

        if ($sourceDirOption) {
            $sourceDir = realpath($sourceDirOption);
            if (!is_dir($sourceDir)) {
                throw new InvalidArgumentException('Source directory not found: ' . $sourceDirOption);
            }

            // Sensible handling if the user provides a project root as the
            // source directory.
            if (file_exists($sourceDir . $this->config->get('local.project_config'))) {
                $projectRoot = $sourceDir;
                $sourceDir = $projectRoot;
            }
        } elseif (!$projectRoot) {
            throw new RootNotFoundException('Project root not found. Specify --source or go to a project directory.');
        } else {
            $sourceDir = $projectRoot;
        }

        $destination = $input->getOption('destination');

        // If no project root is found, ask the user for a destination path.
        if (!$projectRoot && !$destination && $input->isInteractive()) {
            $default = is_dir($sourceDir . '/.git') && $sourceDir === getcwd()
                ? $this->config->get('local.web_root')
                : null;
            $destination = $this->questionHelper->askInput('Build destination', $default);
        }

        if ($destination) {
            $destination = $this->filesystem->makePathAbsolute($destination);
        } elseif (!$projectRoot) {
            throw new RootNotFoundException(
                'Project root not found. Specify --destination or go to a project directory.'
            );
        } else {
            $destination = $projectRoot . '/' . $this->config->get('local.web_root');
        }

        // Ensure no conflicts between source and destination.
        if (strpos($sourceDir, $destination) === 0) {
            throw new InvalidArgumentException("The destination '$destination' conflicts with the source '$sourceDir'");
        }

        // Ask the user about overwriting the destination, if a project root was
        // not found.
        if (!$projectRoot && file_exists($destination)) {
            if (!is_writable($destination)) {
                $this->stdErr->writeln("The destination exists and is not writable: <error>$destination</error>");
                return 1;
            }
            $default = is_link($destination);
            if (!$this->questionHelper->confirm(
                "The destination exists: <comment>$destination</comment>. Overwrite?",
                $default
            )) {
                return 1;
            }
        }

        // Map input options to build settings.
        $settings = [];
        foreach ($input->getOptions() as $name => $value) {
            $settings[$name] = $value;
        }

        $apps = $input->getArgument('app');

        $success = $this->localBuild->build($settings, $sourceDir, $destination, $apps);

        return $success ? 0 : 1;
    }
}
