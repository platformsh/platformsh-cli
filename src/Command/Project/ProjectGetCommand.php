<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Cocur\Slugify\Slugify;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\Selection;
use Platformsh\Cli\Exception\DependencyMissingException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\Identifier;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\SubCommandRunner;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectGetCommand extends CommandBase
{
    protected $projectRoot;

    protected static $defaultName = 'project:get';

    private $api;
    private $config;
    private $filesystem;
    private $git;
    private $identifier;
    private $localBuild;
    private $localProject;
    private $questionHelper;
    private $selector;
    private $ssh;
    private $subCommandRunner;

    public function __construct(
        Api $api,
        Config $config,
        Filesystem $filesystem,
        Git $git,
        Identifier $identifier,
        LocalBuild $localBuild,
        LocalProject $localProject,
        QuestionHelper $questionHelper,
        Selector $selector,
        Ssh $ssh,
        SubCommandRunner $subCommandRunner
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->git = $git;
        $this->identifier = $identifier;
        $this->localBuild = $localBuild;
        $this->localProject = $localProject;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->ssh = $ssh;
        $this->subCommandRunner = $subCommandRunner;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['get'])
            ->setDescription('Clone a project locally')
            ->addArgument('project', InputArgument::OPTIONAL, 'The project ID')
            ->addArgument('directory', InputArgument::OPTIONAL, 'The directory to clone to. Defaults to the project title');
        $this->selector->addProjectOption($this->getDefinition());
        $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, "The environment ID to clone. Defaults to 'master' or the first available environment")
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'Create a shallow clone: limit the number of commits in the history')
            ->addOption('build', null, InputOption::VALUE_NONE, 'Build the project after cloning');
        $this->ssh->configureInput($this->getDefinition());

        $this->addExample('Clone the project "abc123" into the directory "my-project"', 'abc123 my-project');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->validateInput($input);
        $project = $selection->getProject();
        $environment = $selection->getEnvironment();
        $projectRoot = $this->projectRoot;

        // Prepare to talk to the remote repository.
        $gitUrl = $project->getGitUrl();

        $projectRootRelative = $this->filesystem->makePathRelative($projectRoot, getcwd());

        $this->git->ensureInstalled();
        $this->git->setSshCommand($this->ssh->getSshCommand());

        // First check if the repo actually exists.
        try {
            $repoExists = $this->git->remoteRefExists($gitUrl, 'refs/heads/' . $environment->id)
                || $this->git->remoteRefExists($gitUrl);
        } catch (\RuntimeException $e) {
            // The ls-remote command failed.
            $this->stdErr->writeln(sprintf(
                '<error>Failed to connect to the %s Git server</error>',
                $this->config->get('service.name')
            ));

            $this->suggestSshRemedies();

            return 1;
        }

        // If the remote repository doesn't exist, then locally we need to
        // create the folder, run git init, and attach the remote.
        if (!$repoExists) {
            $this->stdErr->writeln('Creating project directory: <info>' . $projectRootRelative . '</info>');
            if (mkdir($projectRoot) === false) {
                $this->stdErr->writeln('Failed to create the project directory.');

                return 1;
            }

            $this->debug('Initializing the repository');
            $this->git->init($projectRoot, true);

            $this->debug('Initializing the project');
            $this->localProject->mapDirectory($projectRoot, $project);

            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Your project has been initialized and connected to <info>%s</info>!',
                $this->config->get('service.name')
            ));
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Commit and push to the <info>master</info> branch of the <info>%s</info> Git remote'
                . ', and %s will build your project automatically.',
                $this->config->get('detection.git_remote_name'),
                $this->config->get('service.name')
            ));

            return 0;
        }

        // We have a repo! Yay. Clone it.
        $projectLabel = $this->api->getProjectLabel($project);
        $this->stdErr->writeln('Downloading project ' . $projectLabel);
        $cloneArgs = [
            '--branch',
            $environment->id,
            '--origin',
            $this->config->get('detection.git_remote_name'),
        ];
        if ($output->isDecorated()) {
            $cloneArgs[] = '--progress';
        }
        if ($input->getOption('depth')) {
            $cloneArgs[] = '--depth';
            $cloneArgs[] = $input->getOption('depth');
            $cloneArgs[] = '--shallow-submodules';
        }
        $cloned = $this->git->cloneRepo($gitUrl, $projectRoot, $cloneArgs);
        if ($cloned === false) {
            // The clone wasn't successful. Clean up the folders we created
            // and then bow out with a message.
            $this->stdErr->writeln('<error>Failed to clone Git repository</error>');
            $this->stdErr->writeln(sprintf(
                'Please check your SSH credentials or contact %s support',
                $this->config->get('service.name')
            ));

            return 1;
        }

        $this->debug('Initializing the project');
        $this->localProject->mapDirectory($projectRoot, $project);

        $this->debug('Downloading submodules (if any)');
        $this->git->updateSubmodules(true, $projectRoot);

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf(
            'The project <info>%s</info> was successfully downloaded to: <info>%s</info>',
            $projectLabel,
            $projectRootRelative
        ));

        // Change to the project directory.
        chdir($projectRoot);

        // Return early if there is no code in the repository.
        if (!glob($projectRoot . '/*', GLOB_NOSORT)) {
            return 0;
        }

        // Ensure that Drush aliases are created.
        if ($this->getApplication()->has('local:drush-aliases') && Drupal::isDrupal($projectRoot)) {
            $this->stdErr->writeln('');
            try {
                $this->subCommandRunner->run('local:drush-aliases');
            } catch (DependencyMissingException $e) {
                $this->stdErr->writeln(sprintf('<comment>%s</comment>', $e->getMessage()));
            }
        }

        // Launch the first build.
        $success = true;
        if ($input->getOption('build')) {
            // Launch the first build.
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Building the project locally for the first time. Run <info>%s build</info> to repeat this.',
                $this->config->get('application.executable')
            ));
            $options = ['no-clean' => true];
            try {
                $success = $this->localBuild->build($options, $projectRoot);
            } catch (\Exception $e) {
                $success = false;
            }
        } else {
            $this->stdErr->writeln(sprintf(
                "\nYou can build the project with: "
                . "\n    cd %s"
                . "\n    %s build",
                $projectRootRelative,
                $this->config->get('application.executable')
            ));
        }

        return $success ? 0 : 1;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *
     * @return Selection
     */
    private function validateInput(InputInterface $input)
    {
        if ($input->getOption('depth') !== null && !preg_match('/^[0-9]+$/', $input->getOption('depth'))) {
            throw new InvalidArgumentException('The --depth value must be an integer.');
        }

        // Combine the project argument and option.
        if ($input->getOption('project') && $input->getArgument('project')) {
            throw new InvalidArgumentException('You cannot use both the --project option and the <project> argument.');
        }
        if (!$input->getOption('project') && $input->getArgument('project')) {
            $input->setOption('project', $input->getArgument('project'));
        }

        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();

        $directory = $input->getArgument('directory');
        if (empty($directory)) {
            $slugify = new Slugify();
            $directory = $project->title ? $slugify->slugify($project->title) : $project->id;
            $directory = $this->questionHelper->askInput('Directory', $directory, [$directory, $project->id]);
        }

        if ($projectRoot = $this->selector->getProjectRoot()) {
            if (strpos(realpath(dirname($directory)), $projectRoot) === 0) {
                throw new InvalidArgumentException('A project cannot be cloned inside another project.');
            }
        }

        if (file_exists($directory)) {
            throw new InvalidArgumentException('The directory already exists: ' . $directory);
        }
        if (!$parent = realpath(dirname($directory))) {
            throw new InvalidArgumentException("Not a directory: " . dirname($directory));
        }
        $this->projectRoot = $parent . '/' . basename($directory);

        return $selection;
    }

    /**
     * Suggest SSH key commands for the user, if the Git connection fails.
     */
    protected function suggestSshRemedies()
    {
        $sshKeys = [];
        try {
            $sshKeys = $this->api->getClient(false)->getSshKeys();
        } catch (\Exception $e) {
            // Ignore exceptions.
        }

        if (!empty($sshKeys)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Please check your SSH credentials');
            $this->stdErr->writeln(sprintf(
                'You can list your keys with: <comment>%s ssh-keys</comment>',
                $this->config->get('application.executable')
            ));
        } else {
            $this->stdErr->writeln(sprintf(
                'You probably need to add an SSH key, with: <comment>%s ssh-key:add</comment>',
                $this->config->get('application.executable')
            ));
        }
    }
}
