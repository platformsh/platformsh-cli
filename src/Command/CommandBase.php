<?php

namespace Platformsh\Cli\Command;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Model\Host\HostInterface;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Model\RemoteContainer;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

abstract class CommandBase extends Command implements MultiAwareInterface
{
    use HasExamplesTrait;

    const STABILITY_STABLE = 'STABLE';

    /** @var bool */
    private static $checkedUpdates;

    /** @var bool */
    private static $printedApiTokenWarning;

    /**
     * @see self::getProjectRoot()
     * @see self::setProjectRoot()
     *
     * @var string|false|null
     */
    private static $projectRoot = null;

    /** @var OutputInterface|null */
    protected $stdErr;

    protected $envArgName = 'environment';
    protected $hiddenInList = false;
    protected $stability = self::STABILITY_STABLE;
    protected $local = false;
    protected $canBeRunMultipleTimes = true;
    protected $runningViaMulti = false;

    private static $container;

    /** @var \Platformsh\Cli\Service\Api|null */
    private $api;

    /** @var InputInterface|null */
    private $input;

    /** @var OutputInterface|null */
    private $output;

    /**
     * @see self::setHiddenAliases()
     *
     * @var array
     */
    private $hiddenAliases = [];

    /**
     * The project, selected either by an option or the CWD.
     *
     * @var Project|false
     */
    private $project;

    /**
     * The current project, based on the CWD.
     *
     * @var Project|false|null
     */
    private $currentProject;

    /**
     * The environment, selected by an option, an argument, or the CWD.
     *
     * @var Environment|false
     */
    private $environment;

    /**
     * The remote container, selected via the --app and --worker options.
     *
     * @var RemoteContainer\RemoteContainerInterface
     */
    private $remoteContainer;

    /**
     * The command synopsis.
     *
     * @var array
     */
    private $synopsis = [];

    /**
     * {@inheritdoc}
     */
    public function isHidden()
    {
        return $this->hiddenInList || $this->stability !== self::STABILITY_STABLE;
    }

    /**
     * @inheritdoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // Set up dependencies that are only needed once per command run.
        $this->output = $output;
        $this->container()->set('output', $output);
        $this->input = $input;
        $this->container()->set('input', $input);
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // Clear cache properties, in case this object is being reused with
        // separate input.
        $this->project = null;
        $this->environment = null;
        $this->remoteContainer = null;

        if ($this->config()->get('api.debug')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        }

        // Tune error reporting based on the output verbosity.
        ini_set('log_errors', 0);
        ini_set('display_errors', 0);
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } elseif ($output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) {
            error_reporting(false);
        } else {
            error_reporting(E_PARSE | E_ERROR);
        }

        $this->promptLegacyMigrate();

        if (!self::$printedApiTokenWarning && $this->onContainer() && (getenv($this->config()->get('application.env_prefix') . 'TOKEN') || $this->api()->hasApiToken(false))) {
            $this->stdErr->writeln('<comment><options=bold>Warning:</>');
            $this->stdErr->writeln('An API token is set. Anyone with SSH access to this environment can read or copy the token.');
            $this->stdErr->writeln('Please remove the token, or ensure it only has strictly necessary access.');
            self::$printedApiTokenWarning = true;
        }
    }

    /**
     * Set up the API object.
     *
     * @return \Platformsh\Cli\Service\Api
     */
    protected function api()
    {
        if (!isset($this->api)) {
            $this->api = $this->getService('api');
            $this->api
                ->dispatcher
                ->addListener('login_required', [$this, 'login']);
            $this->api
                ->dispatcher
                ->addListener('environments_changed', [$this, 'updateDrushAliases']);
        }

        return $this->api;
    }

    /**
     * @return bool
     */
    private function onContainer() {
        $envPrefix = $this->config()->get('service.env_prefix');
        return getenv($envPrefix . 'PROJECT')
            && getenv($envPrefix . 'BRANCH')
            && getenv($envPrefix . 'APP_DIR')
            && getenv($envPrefix . 'TREE_ID');
    }

    /**
     * Prompt the user to migrate from the legacy project file structure.
     *
     * If the input is interactive, the user will be asked to migrate up to once
     * per hour. The time they were last asked will be stored in the project
     * configuration. If the input is not interactive, the user will be warned
     * (on every command run) that they should run the 'legacy-migrate' command.
     */
    private function promptLegacyMigrate()
    {
        static $asked = false;
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        if ($localProject->getLegacyProjectRoot() && $this->getName() !== 'legacy-migrate' && !$asked) {
            $asked = true;

            $projectRoot = $this->getProjectRoot();
            $timestamp = time();
            $promptMigrate = true;
            if ($projectRoot) {
                $projectConfig = $localProject->getProjectConfig($projectRoot);
                if (isset($projectConfig['migrate']['3.x']['last_asked'])
                    && $projectConfig['migrate']['3.x']['last_asked'] > $timestamp - 3600) {
                    $promptMigrate = false;
                }
            }

            $this->stdErr->writeln(sprintf(
                'You are in a project using an old file structure, from previous versions of the %s.',
                $this->config()->get('application.name')
            ));
            if ($this->input->isInteractive() && $promptMigrate) {
                if ($projectRoot && isset($projectConfig)) {
                    $projectConfig['migrate']['3.x']['last_asked'] = $timestamp;
                    $localProject->writeCurrentProjectConfig($projectConfig, $projectRoot);
                }
                /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                $questionHelper = $this->getService('question_helper');
                if ($questionHelper->confirm('Migrate to the new structure?')) {
                    $code = $this->runOtherCommand('legacy-migrate');
                    exit($code);
                }
            } else {
                $this->stdErr->writeln(sprintf(
                    'Fix this with: <comment>%s legacy-migrate</comment>',
                    $this->config()->get('application.executable')
                ));
            }
            $this->stdErr->writeln('');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        // Work around a bug in Console which means the default command's input
        // is always considered to be interactive.
        if ($this->getName() === 'welcome'
            && isset($GLOBALS['argv'])
            && array_intersect($GLOBALS['argv'], ['-n', '--no', '-y', '---yes'])) {
            $input->setInteractive(false);
            return;
        }

        $this->checkUpdates();
    }

    /**
     * Check for updates.
     */
    protected function checkUpdates()
    {
        // Avoid checking more than once in this process.
        if (self::$checkedUpdates) {
            return;
        }
        self::$checkedUpdates = true;

        // Check that the Phar extension is available.
        if (!extension_loaded('Phar')) {
            return;
        }

        // Get the filename of the Phar, or stop if this instance of the CLI is
        // not a Phar.
        $pharFilename = \Phar::running(false);
        if (!$pharFilename) {
            return;
        }

        // Check if the file is writable.
        if (!is_writable($pharFilename)) {
            return;
        }

        // Check if updates are configured.
        $config = $this->config();
        if (!$config->get('updates.check')) {
            return;
        }

        // Determine an embargo time, after which updates can be checked.
        $timestamp = time();
        $embargoTime = $timestamp - $config->get('updates.check_interval');

        // Stop if updates were last checked after the embargo time.
        /** @var \Platformsh\Cli\Service\State $state */
        $state = $this->getService('state');
        if ($state->get('updates.last_checked') > $embargoTime) {
            return;
        }

        // Stop if the Phar was updated after the embargo time.
        if (filemtime($pharFilename) > $embargoTime) {
            return;
        }

        // Ensure classes are auto-loaded if they may be needed after the
        // update.
        $this->getService('question_helper');
        $this->getService('shell');
        $currentVersion = $this->config()->get('application.version');

        /** @var \Platformsh\Cli\Service\SelfUpdater $cliUpdater */
        $cliUpdater = $this->getService('self_updater');
        $cliUpdater->setAllowMajor(true);
        $cliUpdater->setTimeout(5);

        try {
            $newVersion = $cliUpdater->update(null, $currentVersion);
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'Failed to download') !== false) {
                $this->stdErr->writeln('<error>' . $e->getMessage() . '</error>');
                $newVersion = false;
            } else {
                throw $e;
            }
        }

        $state->set('updates.last_checked', $timestamp);

        if ($newVersion !== false) {
            $this->continueAfterUpdating($currentVersion, $newVersion, $pharFilename);
            exit(0);
        }

        $this->stdErr->writeln('');
    }

    /**
     * Prompts the user to continue with the original command after updating.
     *
     * This only applies if it's not a major version change.
     *
     * @param string $currentVersion
     * @param string $newVersion
     * @param string $pharFilename
     *
     * @return void
     */
    private function continueAfterUpdating($currentVersion, $newVersion, $pharFilename) {
        if (!isset($this->input) || !$this->input instanceof ArgvInput || !is_executable($pharFilename)) {
            return;
        }
        list($currentMajorVersion,) = explode('.', ltrim($currentVersion, 'v'), 2);
        list($newMajorVersion,) = explode('.', ltrim($newVersion, 'v'), 2);
        if ($newMajorVersion !== $currentMajorVersion) {
            return;
        }

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $originalCommand = $this->input->__toString();
        if (empty($originalCommand)) {
            $exitCode = $shell->executeSimple(escapeshellarg($pharFilename));
            exit($exitCode);
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $questionText = "\n"
            . 'Original command: <info>' . $originalCommand . '</info>'
            . "\n\n" . 'Continue?';
        if ($questionHelper->confirm($questionText)) {
            $this->stdErr->writeln('');
            $exitCode = $shell->executeSimple(escapeshellarg($pharFilename) . ' ' . $originalCommand);
            exit($exitCode);
        }
    }

    /**
     * Log in the user.
     *
     * This is called via the 'login_required' event.
     *
     * @see Api::getClient()
     */
    public function login()
    {
        $success = false;
        if ($this->output && $this->input && $this->input->isInteractive()) {
            $sessionAdvice = [];
            if ($this->config()->getSessionId() !== 'default' || count($this->api()->listSessionIds()) > 1) {
                $sessionAdvice[] = sprintf('The current session ID is: <info>%s</info>', $this->config()->getSessionId());
                if (!$this->config()->isSessionIdFromEnv()) {
                    $sessionAdvice[] = sprintf('To switch sessions, run: <info>%s session:switch</info>', $this->config()->get('application.executable'));
                }
            }

            $method = $this->config()->getWithDefault('application.login_method', 'browser');
            if ($method === 'browser') {
                /** @var \Platformsh\Cli\Service\Url $url */
                $urlService = $this->getService('url');
                if ($urlService->canOpenUrls()) {
                    $this->stdErr->writeln('Authentication is required.');
                    $this->stdErr->writeln('');
                    if ($sessionAdvice) {
                        $this->stdErr->writeln($sessionAdvice);
                        $this->stdErr->writeln('');
                    }
                    /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                    $questionHelper = $this->getService('question_helper');
                    if ($questionHelper->confirm('Log in via a browser?')) {
                        $this->stdErr->writeln('');
                        $exitCode = $this->runOtherCommand('auth:browser-login');
                        $this->stdErr->writeln('');
                        $success = $exitCode === 0;
                    }
                }
            } elseif ($method === 'password') {
                if ($sessionAdvice) {
                    $this->stdErr->writeln($sessionAdvice);
                    $this->stdErr->writeln('');
                }
                $exitCode = $this->runOtherCommand('auth:password-login');
                $this->stdErr->writeln('');
                $success = $exitCode === 0;
            }
        }
        if (!$success) {
            throw new LoginRequiredException();
        }
    }

    /**
     * Is this a local command? (if it does not make API requests)
     *
     * @return bool
     */
    public function isLocal()
    {
        return $this->local;
    }

    /**
     * Get the current project if the user is in a project directory.
     *
     * @param bool $suppressErrors Suppress 403 or not found errors.
     *
     * @throws \RuntimeException
     *
     * @return Project|false The current project
     */
    public function getCurrentProject($suppressErrors = false)
    {
        if (isset($this->currentProject)) {
            return $this->currentProject;
        }
        if (!$projectRoot = $this->getProjectRoot()) {
            return false;
        }

        $project = false;
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $config = $localProject->getProjectConfig($projectRoot);
        if ($config) {
            try {
                $project = $this->api()->getProject($config['id'], isset($config['host']) ? $config['host'] : null);
            } catch (BadResponseException $e) {
                if ($suppressErrors && $e->getResponse() && in_array($e->getResponse()->getStatusCode(), [403, 404])) {
                    return $this->currentProject = false;
                }
                throw $e;
            }
            if (!$project) {
                if ($suppressErrors) {
                    return $this->currentProject = false;
                }
                throw new ProjectNotFoundException(
                    "Project not found: " . $config['id']
                    . "\nEither you do not have access to the project or it no longer exists."
                );
            }
            $this->debug('Project ' . $config['id'] . ' is mapped to the current directory');
        }
        $this->currentProject = $project;

        return $project;
    }

    /**
     * Get the current environment if the user is in a project directory.
     *
     * @param Project $expectedProject The expected project.
     * @param bool|null $refresh Whether to refresh the environments or projects
     *                           cache.
     *
     * @return Environment|false The current environment.
     */
    public function getCurrentEnvironment(Project $expectedProject = null, $refresh = null)
    {
        if (!($projectRoot = $this->getProjectRoot())
            || !($project = $this->getCurrentProject(true))
            || ($expectedProject !== null && $expectedProject->id !== $project->id)) {
            return false;
        }

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $git->setDefaultRepositoryDir($this->getProjectRoot());
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $config = $localProject->getProjectConfig($projectRoot);

        // Check if there is a manual mapping set for the current branch.
        if (!empty($config['mapping'])
            && ($currentBranch = $git->getCurrentBranch())
            && !empty($config['mapping'][$currentBranch])) {
            $environment = $this->api()->getEnvironment($config['mapping'][$currentBranch], $project, $refresh);
            if ($environment) {
                $this->debug('Found mapped environment for branch ' . $currentBranch . ': ' . $this->api()->getEnvironmentLabel($environment));
                return $environment;
            } else {
                unset($config['mapping'][$currentBranch]);
                $localProject->writeCurrentProjectConfig($config, $projectRoot);
            }
        }

        // Check whether the user has a Git upstream set to a remote environment
        // ID.
        $upstream = $git->getUpstream();
        if ($upstream && strpos($upstream, '/') !== false) {
            list(, $potentialEnvironment) = explode('/', $upstream, 2);
            $environment = $this->api()->getEnvironment($potentialEnvironment, $project, $refresh);
            if ($environment) {
                $this->debug('Selected environment ' . $this->api()->getEnvironmentLabel($environment) . ', based on Git upstream: ' . $upstream);
                return $environment;
            }
        }

        // There is no Git remote set. Fall back to trying the current branch
        // name.
        if (!empty($currentBranch) || ($currentBranch = $git->getCurrentBranch())) {
            $environment = $this->api()->getEnvironment($currentBranch, $project, $refresh);
            if (!$environment) {
                // Try a sanitized version of the branch name too.
                $currentBranchSanitized = Environment::sanitizeId($currentBranch);
                if ($currentBranchSanitized !== $currentBranch) {
                    $environment = $this->api()->getEnvironment($currentBranchSanitized, $project, $refresh);
                }
            }
            if ($environment) {
                $this->debug('Selected environment ' . $this->api()->getEnvironmentLabel($environment) . ' based on branch name: ' . $currentBranch);
                return $environment;
            }
            $this->debug('No environment was found to match the current Git branch: ' . $currentBranch);
        }

        return false;
    }

    /**
     * Update the user's local Drush aliases.
     *
     * This is called via the 'environments_changed' event.
     *
     * @see \Platformsh\Cli\Service\Api::getEnvironments()
     *
     * @param EnvironmentsChangedEvent $event
     */
    public function updateDrushAliases(EnvironmentsChangedEvent $event)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            return;
        }
        // Make sure the local:drush-aliases command is enabled.
        if (!$this->getApplication()->has('local:drush-aliases')) {
            return;
        }
        // Double-check that the passed project is the current one, and that it
        // still exists.
        $currentProject = $this->getCurrentProject(true);
        if (!$currentProject || $currentProject->id != $event->getProject()->id) {
            return;
        }
        // Ignore the project if it doesn't contain a Drupal application.
        if (!Drupal::isDrupal($projectRoot)) {
            return;
        }
        /** @var \Platformsh\Cli\Service\Drush $drush */
        $drush = $this->getService('drush');
        if ($drush->getVersion() === false) {
            $this->debug('Not updating Drush aliases: the Drush version cannot be determined.');
            return;
        }
        $this->debug('Updating Drush aliases');
        try {
            $drush->createAliases($event->getProject(), $projectRoot, $event->getEnvironments());
        } catch (\Exception $e) {
            $this->stdErr->writeln(sprintf(
                "<comment>Failed to update Drush aliases:</comment>\n%s\n",
                preg_replace('/^/m', '  ', trim($e->getMessage()))
            ));
        }
    }

    /**
     * @param string $root
     */
    protected function setProjectRoot($root)
    {
        if (!is_dir($root)) {
            throw new \InvalidArgumentException("Invalid project root: $root");
        }
        self::$projectRoot = $root;
    }

    /**
     * @return string|false
     */
    public function getProjectRoot()
    {
        if (!isset(self::$projectRoot)) {
            $this->debug('Finding the project root');
            /** @var \Platformsh\Cli\Local\LocalProject $localProject */
            $localProject = $this->getService('local.project');
            self::$projectRoot = $localProject->getProjectRoot();
            $this->debug(
                self::$projectRoot
                    ? 'Project root found: ' . self::$projectRoot
                    : 'Project root not found'
            );
        }

        return self::$projectRoot;
    }

    /**
     * @return bool
     */
    protected function selectedProjectIsCurrent()
    {
        $current = $this->getCurrentProject(true);
        if (!$current || !$this->hasSelectedProject()) {
            return false;
        }

        return $current->id === $this->getSelectedProject()->id;
    }

    /**
     * Warn the user that the remote environment needs redeploying.
     */
    protected function redeployWarning()
    {
        $this->stdErr->writeln([
            '',
            '<comment>The remote environment(s) must be redeployed for the change to take effect.</comment>',
            'To redeploy an environment, run: <info>' . $this->config()->get('application.executable') . ' redeploy</info>',
        ]);
    }

    /**
     * Add the --project and --host options.
     *
     * @return CommandBase
     */
    protected function addProjectOption()
    {
        $this->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'The project ID or URL');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, "The project's API hostname");

        return $this;
    }

    /**
     * Add the --environment option.
     *
     * @return CommandBase
     */
    protected function addEnvironmentOption()
    {
        return $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, 'The environment ID');
    }

    /**
     * Add the --app option.
     *
     * @return CommandBase
     */
    protected function addAppOption()
    {
        return $this->addOption('app', 'A', InputOption::VALUE_REQUIRED, 'The remote application name');
    }

    /**
     * Add both the --no-wait and --wait options.
     */
    protected function addWaitOptions()
    {
        $this->addOption('no-wait', 'W', InputOption::VALUE_NONE, 'Do not wait for the operation to complete');
        if ($this->detectRunningInHook()) {
            $this->addOption('wait', null, InputOption::VALUE_NONE, 'Wait for the operation to complete');
        } else {
            $this->addOption('wait', null, InputOption::VALUE_NONE, 'Wait for the operation to complete (default)');
        }
    }

    /**
     * Returns whether we should wait for an operation to complete.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *
     * @return bool
     */
    protected function shouldWait(InputInterface $input)
    {
        if ($input->hasOption('no-wait') && $input->getOption('no-wait')) {
            return false;
        }
        if ($input->hasOption('wait') && $input->getOption('wait')) {
            return true;
        }
        if ($this->detectRunningInHook()) {
            $serviceName = $this->config()->get('service.name');
            $message = "\n<comment>Warning:</comment> $serviceName hook environment detected: assuming <comment>--no-wait</comment> by default."
                . "\nTo avoid ambiguity, please specify either --no-wait or --wait."
                . "\n";
            $this->stdErr->writeln($message);

            return false;
        }

        return true;
    }

    /**
     * Detects a Platform.sh non-terminal Dash environment; i.e. a hook.
     *
     * @return bool
     */
    protected function detectRunningInHook()
    {
        $envPrefix = $this->config()->get('service.env_prefix');
        if (getenv($envPrefix . 'PROJECT')
            && basename(getenv('SHELL')) === 'dash'
            && !$this->isTerminal(STDIN)) {
            return true;
        }

        return false;
    }

    /**
     * Select the project for the user, based on input or the environment.
     *
     * @param string $projectId
     * @param string $host
     * @param bool   $detectCurrent
     *
     * @return Project
     */
    protected function selectProject($projectId = null, $host = null, $detectCurrent = true)
    {
        if (!empty($projectId)) {
            $this->project = $this->api()->getProject($projectId, $host);
            if (!$this->project) {
                throw new ConsoleInvalidArgumentException($this->getProjectNotFoundMessage($projectId));
            }

            $this->debug('Selected project: ' . $this->project->id);

            return $this->project;
        }

        $this->project = $detectCurrent ? $this->getCurrentProject() : false;
        if (!$this->project && isset($this->input) && $this->input->isInteractive()) {
            $projects = $this->api()->getProjects();
            if (count($projects) > 0) {
                $this->debug('No project specified: offering a choice...');
                $projectId = $this->offerProjectChoice($projects);

                return $this->selectProject($projectId);
            }
        }
        if (!$this->project) {
            if ($detectCurrent) {
                throw new RootNotFoundException(
                    "Could not determine the current project."
                    . "\n\nSpecify it using --project, or go to a project directory."
                );
            } else {
                throw new \RuntimeException('You must specify a project.');
            }
        }

        return $this->project;
    }

    /**
     * Format an error message about a not-found project.
     *
     * @param string $projectId
     *
     * @return string
     */
    private function getProjectNotFoundMessage($projectId)
    {
        $message = 'Specified project not found: ' . $projectId;
        if ($projects = $this->api()->getProjects()) {
            $message .= "\n\nYour projects are:";
            $limit = 8;
            foreach (array_slice($projects, 0, $limit) as $project) {
                $message .= "\n    " . $project->id;
                if ($project->title) {
                    $message .= ' - ' . $project->title;
                }
            }
            if (count($projects) > $limit) {
                $message .= "\n    ...";
                $message .= "\n\n    List projects with: " . $this->config()->get('application.executable') . ' project:list';
            }
        }

        return $message;
    }

    /**
     * Select the current environment for the user.
     *
     * @throws \RuntimeException If the current environment cannot be found.
     *
     * @param string|null $environmentId
     *   The environment ID specified by the user, or null to auto-detect the
     *   environment.
     * @param bool $required
     *   Whether it's required to have an environment.
     * @param bool $selectDefaultEnv
     *   Whether to select a default environment.
     * @param bool $detectCurrentEnv
     *   Whether to detect the current environment from Git.
     */
    protected function selectEnvironment($environmentId = null, $required = true, $selectDefaultEnv = false, $detectCurrentEnv = true)
    {
        $envPrefix = $this->config()->get('service.env_prefix');
        if ($environmentId === null && getenv($envPrefix . 'BRANCH')) {
            $environmentId = getenv($envPrefix . 'BRANCH');
            $this->stdErr->writeln(sprintf(
                'Environment ID read from environment variable %s: %s',
                $envPrefix . 'BRANCH',
                $environmentId
            ), OutputInterface::VERBOSITY_VERBOSE);
        }

        if ($environmentId !== null) {
            $environment = $this->api()->getEnvironment($environmentId, $this->project, null, true);
            if (!$environment) {
                throw new ConsoleInvalidArgumentException('Specified environment not found: ' . $environmentId);
            }

            $this->environment = $environment;
            $this->debug('Selected environment: ' . $this->api()->getEnvironmentLabel($environment));
            return;
        }

        if ($detectCurrentEnv && ($environment = $this->getCurrentEnvironment($this->project ?: null))) {
            $this->environment = $environment;
            return;
        }

        if ($selectDefaultEnv) {
            $this->debug('No environment specified or detected: finding a default...');
            $environment = $this->api()->getDefaultEnvironment($this->project);
            if ($environment) {
                $this->environment = $environment;
                return;
            }
        }

        if ($required && isset($this->input) && $this->input->isInteractive()) {
            $this->debug('No environment specified or detected: offering a choice...');
            $this->environment = $this->offerEnvironmentChoice($this->api()->getEnvironments($this->project));
            return;
        }

        if ($required) {
            if ($this->getProjectRoot() || !$detectCurrentEnv) {
                $message = 'Could not determine the current environment.'
                    . "\n" . 'Specify it manually using --environment (-e).';
            } else {
                $message = 'No environment specified.'
                    . "\n" . 'Specify one using --environment (-e), or go to a project directory.';
            }
            throw new ConsoleInvalidArgumentException($message);
        }
    }

    /**
     * Add the --app and --worker options.
     */
    protected function addRemoteContainerOptions()
    {
        if (!$this->getDefinition()->hasOption('app')) {
            $this->addAppOption();
        }
        if (!$this->getDefinition()->hasOption('worker')) {
            $this->addOption('worker', null, InputOption::VALUE_REQUIRED, 'A worker name');
        }
    }

    /**
     * Find what app or worker container the user wants to select.
     *
     * Needs the --app and --worker options, as applicable.
     *
     * @param InputInterface $input
     *   The user input object.
     * @param bool $includeWorkers
     *   Whether to include workers in the selection.
     *
     * @return \Platformsh\Cli\Model\RemoteContainer\RemoteContainerInterface
     *   A class representing a container that allows SSH access.
     */
    protected function selectRemoteContainer(InputInterface $input, $includeWorkers = true)
    {
        if (isset($this->remoteContainer)) {
            return $this->remoteContainer;
        }

        $environment = $this->getSelectedEnvironment();

        try {
            $deployment = $this->api()->getCurrentDeployment(
                $environment,
                $input->hasOption('refresh') && $input->getOption('refresh')
            );
        } catch (EnvironmentStateException $e) {
            if ($environment->isActive() && $e->getMessage() === 'Current deployment not found') {
                $appName = $input->hasOption('app') ? $input->getOption('app') : '';

                return $this->remoteContainer = new RemoteContainer\BrokenEnv($environment, $appName);
            }
            throw $e;
        }

        // Read the --app and --worker options, if the latter is specified then it will be used.
        $appOption = $input->hasOption('app') ? $input->getOption('app') : null;
        $workerOption = $includeWorkers && $input->hasOption('worker') ? $input->getOption('worker') : null;

        if ($appOption !== null) {
            try {
                $webApp = $deployment->getWebApp($appOption);
            } catch (\InvalidArgumentException $e) {
                throw new ConsoleInvalidArgumentException('Application not found: ' . $appOption);
            }

            // No worker option specified so select app directly.
            if ($workerOption === null) {
                return $this->remoteContainer = new RemoteContainer\App($webApp, $environment);
            }

            unset($webApp); // object is no longer required.
        }

        if ($workerOption !== null) {
            // Check for a conflict with the --app option.
            if ($appOption !== null
                && strpos($workerOption, '--') !== false
                && stripos($workerOption, $appOption . '--') !== 0) {
                throw new \InvalidArgumentException(sprintf(
                    'App name "%s" conflicts with worker name "%s"',
                    $appOption,
                    $workerOption
                ));
            }

            // If we have the app name, load the worker directly.
            if (strpos($workerOption, '--') !== false || $appOption !== null) {
                $qualifiedWorkerName = strpos($workerOption, '--') !== false
                    ? $workerOption
                    : $appOption . '--' . $workerOption;
                try {
                    $worker = $deployment->getWorker($qualifiedWorkerName);
                } catch (\InvalidArgumentException $e) {
                    throw new ConsoleInvalidArgumentException('Worker not found: ' . $workerOption);
                }

                return $this->remoteContainer = new RemoteContainer\Worker($worker, $environment);
            }

            // If we don't have the app name, find all the possible matching
            // workers, and ask the user to choose.
            $suffix = '--' . $workerOption;
            $suffixLength = strlen($suffix);
            $workerNames = [];
            foreach ($deployment->workers as $worker) {
                if (substr($worker->name, -$suffixLength) === $suffix) {
                    $workerNames[] = $worker->name;
                }
            }
            if (count($workerNames) === 0) {
                throw new ConsoleInvalidArgumentException('Worker not found: ' . $workerOption);
            }
            if (count($workerNames) === 1) {
                $workerName = reset($workerNames);

                return $this->remoteContainer = new RemoteContainer\Worker($deployment->getWorker($workerName), $environment);
            }
            if (!$input->isInteractive()) {
                throw new ConsoleInvalidArgumentException(sprintf(
                    'Ambiguous worker name: %s (matches: %s)',
                    $workerOption,
                    implode(', ', $workerNames)
                ));
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $workerName = $questionHelper->choose(
                $workerNames,
                'Enter a number to choose a worker:'
            );

            return $this->remoteContainer = new RemoteContainer\Worker($deployment->getWorker($workerName), $environment);
        }

        // Enterprise environments do not have separate containers for workers.
        // Disable interactive selection of worker.
        // @todo revise this when the API provides an explicit list of SSH endpoints
        if ($includeWorkers && $environment->deployment_target !== 'local') {
            $includeWorkers = false;
        }

        // Prompt the user to choose between the app(s) or worker(s) that have
        // been found.
        $default = null;
        $appNames = $appOption !== null
            ? [$appOption]
            : array_map(function (WebApp $app) { return $app->name; }, $deployment->webapps);
        if (count($appNames) === 1) {
            $default = reset($appNames);
            $choices = [];
            $choices[$default] = $default . ' (default)';
        } else {
            $choices = array_combine($appNames, $appNames);
        }
        if ($includeWorkers) {
            foreach ($deployment->workers as $worker) {
                list($appPart, ) = explode('--', $worker->name, 2);
                if (in_array($appPart, $appNames, true)) {
                    $choices[$worker->name] = $worker->name;
                }
            }
        }
        if (count($choices) === 0) {
            throw new \RuntimeException('Failed to find apps or workers for environment: ' . $environment->id);
        }
        ksort($choices, SORT_NATURAL);
        if (count($choices) === 1) {
            $choice = key($choices);
        } elseif ($input->isInteractive()) {
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            if ($includeWorkers && count($deployment->workers)) {
                $text = sprintf('Enter a number to choose %s app or %s worker:',
                    count($appNames) === 1 ? 'the' : 'an',
                    count($choices) === 2 ? 'its' : 'a'
                );
            } else {
                $text = sprintf('Enter a number to choose %s app:',
                    count($appNames) === 1 ? 'the' : 'an'
                );
            }
            $choice = $questionHelper->choose(
                $choices,
                $text,
                $default
            );
        } elseif (count($appNames) === 1) {
            $choice = reset($appNames);
        } else {
            throw new ConsoleInvalidArgumentException(
                $includeWorkers
                    ? 'Specifying the --app or --worker is required in non-interactive mode'
                    : 'Specifying the --app is required in non-interactive mode'
            );
        }

        // Match the choice to a worker or app destination.
        if (strpos($choice, '--') !== false) {
            return $this->remoteContainer = new RemoteContainer\Worker($deployment->getWorker($choice), $environment);
        }

        return $this->remoteContainer = new RemoteContainer\App($deployment->getWebApp($choice), $environment);
    }

    /**
     * Find the name of the app the user wants to use.
     *
     * @param InputInterface $input
     *   The user input object.
     *
     * @return string|null
     *   The application name, or null if it could not be found.
     */
    protected function selectApp(InputInterface $input)
    {
        $appName = $input->getOption('app');
        if ($appName) {
            return $appName;
        }

        return $this->selectRemoteContainer($input, false)->getName();
    }

    /**
     * Offer the user an interactive choice of projects.
     *
     * @param Project[] $projects
     * @param string    $text
     *
     * @return string
     *   The chosen project ID.
     */
    final protected function offerProjectChoice(array $projects, $text = 'Enter a number to choose a project:')
    {
        if (!isset($this->input) || !isset($this->output) || !$this->input->isInteractive()) {
            throw new \BadMethodCallException('Not interactive: a project choice cannot be offered.');
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if (count($projects) >= 25 || count($projects) > (new Terminal())->getHeight() - 3) {
            $autocomplete = [];
            foreach ($projects as $project) {
                if ($project->title) {
                    $autocomplete[$project->id] = $project->id . ' - <question>' . $project->title . '</question>';
                } else {
                    $autocomplete[$project->id] = $project->id;
                }
            }
            asort($autocomplete, SORT_NATURAL | SORT_FLAG_CASE);
            return $questionHelper->askInput('Enter a project ID', null, array_values($autocomplete), function ($value) use ($autocomplete) {
                list($id, ) = explode(' - ', $value);
                if (!isset($autocomplete[$id]) && !$this->api()->getProject($id)) {
                    throw new \RuntimeException('Project not found: ' . $id);
                }
                return $id;
            });
        }

        $projectList = [];
        foreach ($projects as $project) {
            $projectList[$project->id] = $this->api()->getProjectLabel($project, false);
        }
        asort($projectList, SORT_NATURAL | SORT_FLAG_CASE);

        return $questionHelper->choose($projectList, $text, null, false);
    }

    /**
     * Offers a choice of environments.
     *
     * @param Environment[] $environments
     * @param string $text
     *
     * @return Environment
     */
    final protected function offerEnvironmentChoice(array $environments, $text = 'Enter a number to choose an environment:')
    {
        if (!isset($this->input) || !isset($this->output) || !$this->input->isInteractive()) {
            throw new \BadMethodCallException('Not interactive: an environment choice cannot be offered.');
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $defaultEnvironment = $this->api()->getDefaultEnvironment($this->project);
        $defaultEnvironmentId = $defaultEnvironment ? $defaultEnvironment->id : null;

        if (count($environments) > (new Terminal())->getHeight() / 2) {
            $ids = array_keys($environments);
            sort($ids, SORT_NATURAL | SORT_FLAG_CASE);

            $id = $questionHelper->askInput('Enter an environment ID', $defaultEnvironmentId, array_keys($environments), function ($value) use ($environments) {
                if (!isset($environments[$value])) {
                    throw new \RuntimeException('Environment not found: ' . $value);
                }

                return $value;
            });
        } else {
            $environmentList = [];
            foreach ($environments as $environment) {
                $environmentList[$environment->id] = $this->api()->getEnvironmentLabel($environment, false);
            }
            asort($environmentList, SORT_NATURAL | SORT_FLAG_CASE);

            if ($defaultEnvironmentId) {
                $text .= "\n" . 'Default: <question>' . $defaultEnvironmentId . '</question>';
            }

            $id = $questionHelper->choose($environmentList, $text, $defaultEnvironmentId);
        }

        return $environments[$id];
    }

    /**
     * @param InputInterface $input
     * @param bool           $envNotRequired
     * @param bool           $selectDefaultEnv
     * @param bool           $detectCurrent Whether to detect the project/environment from the current working directory.
     */
    final protected function validateInput(InputInterface $input, $envNotRequired = false, $selectDefaultEnv = false, $detectCurrent = true)
    {
        $projectId = $input->hasOption('project') ? $input->getOption('project') : null;
        $projectHost = $input->hasOption('host') ? $input->getOption('host') : null;
        $environmentId = null;

        // Identify the project.
        if ($projectId !== null) {
            /** @var \Platformsh\Cli\Service\Identifier $identifier */
            $identifier = $this->getService('identifier');
            $result = $identifier->identify($projectId);
            $projectId = $result['projectId'];
            $projectHost = $projectHost ?: $result['host'];
            $environmentId = $result['environmentId'];
        }

        // Load the project ID from an environment variable, if available.
        $envPrefix = $this->config()->get('service.env_prefix');
        if ($projectId === null && getenv($envPrefix . 'PROJECT')) {
            $projectId = getenv($envPrefix . 'PROJECT');
            $this->stdErr->writeln(sprintf(
                'Project ID read from environment variable %s: %s',
                $envPrefix . 'PROJECT',
                $projectId
            ), OutputInterface::VERBOSITY_VERBOSE);
        }

        // Set the --app option.
        if ($input->hasOption('app') && !$input->getOption('app')) {
            // An app ID might be provided from the parsed project URL.
            if (isset($result) && isset($result['appId'])) {
                $input->setOption('app', $result['appId']);
                $this->debug(sprintf(
                    'App name identified as: %s',
                    $input->getOption('app')
                ));
            }
            // Or from an environment variable.
            elseif (getenv($envPrefix . 'APPLICATION_NAME')) {
                $input->setOption('app', getenv($envPrefix . 'APPLICATION_NAME'));
                $this->stdErr->writeln(sprintf(
                    'App name read from environment variable %s: %s',
                    $envPrefix . 'APPLICATION_NAME',
                    $input->getOption('app')
                ), OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        // Select the project.
        $this->selectProject($projectId, $projectHost, $detectCurrent);

        // Select the environment.
        $envOptionName = 'environment';
        if ($input->hasArgument($this->envArgName)
            && $input->getArgument($this->envArgName) !== null
            && $input->getArgument($this->envArgName) !== []) {
            if ($input->hasOption($envOptionName) && $input->getOption($envOptionName) !== null) {
                throw new ConsoleInvalidArgumentException(
                    sprintf(
                        'You cannot use both the <%s> argument and the --%s option',
                        $this->envArgName,
                        $envOptionName
                    )
                );
            }
            $argument = $input->getArgument($this->envArgName);
            if (is_array($argument) && count($argument) == 1) {
                $argument = $argument[0];
            }
            if (!is_array($argument)) {
                $this->debug('Selecting environment based on input argument');
                $this->selectEnvironment($argument, true, $selectDefaultEnv, $detectCurrent);
            }
        } elseif ($input->hasOption($envOptionName)) {
            if ($input->getOption($envOptionName) !== null) {
                $environmentId = $input->getOption($envOptionName);
            }
            $this->selectEnvironment($environmentId, !$envNotRequired, $selectDefaultEnv, $detectCurrent);
        }
    }

    /**
     * Check whether a project is selected.
     *
     * @return bool
     */
    protected function hasSelectedProject()
    {
        return !empty($this->project);
    }

    /**
     * Get the project selected by the user.
     *
     * The project is selected via validateInput(), if there is a --project
     * option in the command.
     *
     * @throws \BadMethodCallException
     *
     * @return Project
     */
    protected function getSelectedProject()
    {
        if (!$this->project) {
            throw new \BadMethodCallException('No project selected');
        }

        return $this->project;
    }

    /**
     * Check whether a single environment is selected.
     *
     * @return bool
     */
    protected function hasSelectedEnvironment()
    {
        return !empty($this->environment);
    }

    /**
     * Get the environment selected by the user.
     *
     * The project is selected via validateInput(), if there is an
     * --environment option in the command.
     *
     * @return Environment
     */
    protected function getSelectedEnvironment()
    {
        if (!$this->environment) {
            throw new \BadMethodCallException('No environment selected');
        }

        return $this->environment;
    }

    /**
     * Run another CLI command.
     *
     * @param string         $name
     *   The name of the other command.
     * @param array          $arguments
     *   Arguments for the other command.
     * @param OutputInterface $output
     *   The output for the other command. Defaults to the current output.
     *
     * @return int
     */
    protected function runOtherCommand($name, array $arguments = [], OutputInterface $output = null)
    {
        /** @var \Platformsh\Cli\Application $application */
        $application = $this->getApplication();
        /** @var Command $command */
        $command = $application->find($name);

        // Pass on interactivity arguments to the other command.
        if (isset($this->input) && $command->getDefinition()->hasOption('yes')) {
            $arguments += [
                '--yes' => $this->input->getOption('yes'),
                '--no' => $this->input->getOption('no'),
            ];
        }

        $cmdInput = new ArrayInput(['command' => $name] + $arguments);
        if (!empty($arguments['--yes']) || !empty($arguments['--no'])) {
            $cmdInput->setInteractive(false);
        } elseif (isset($this->input)) {
            $cmdInput->setInteractive($this->input->isInteractive());
        }

        $this->debug('Running command: ' . $name);

        // Give the other command an entirely new service container, because the
        // "input" and "output" parameters, and all their dependents, need to
        // change.
        $container = self::$container;
        self::$container = null;
        $application->setCurrentCommand($command);

        // Use a try/finally pattern to ensure that state is restored, even if
        // an exception is thrown in $command->run() and caught by the caller.
        try {
            $result = $command->run($cmdInput, $output ?: $this->output);
        } finally {
            $application->setCurrentCommand($this);
            // Restore the old service container.
            self::$container = $container;
        }

        return $result;
    }

    /**
     * Add aliases that should be hidden from help.
     *
     * @see parent::setAliases()
     *
     * @param array $hiddenAliases
     *
     * @return CommandBase
     */
    protected function setHiddenAliases(array $hiddenAliases)
    {
        $this->hiddenAliases = $hiddenAliases;
        $this->setAliases(array_merge($this->getAliases(), $hiddenAliases));

        return $this;
    }

    /**
     * Get aliases that should be visible in help.
     *
     * @return array
     */
    public function getVisibleAliases()
    {
        return array_diff($this->getAliases(), $this->hiddenAliases);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides the default method so that the description is not repeated
     * twice.
     */
    public function getProcessedHelp()
    {
        $help = $this->getHelp();
        if ($help === '') {
            return $help;
        }
        $name = $this->getName();

        $placeholders = ['%command.name%', '%command.full_name%'];
        $replacements = [$name, $this->config()->get('application.executable') . ' ' . $name];

        return str_replace($placeholders, $replacements, $help);
    }

    /**
     * Print a message if debug output is enabled.
     *
     * @param string $message
     */
    protected function debug($message)
    {
        $this->labeledMessage('DEBUG', $message, OutputInterface::VERBOSITY_DEBUG);
    }

    /**
     * Print a warning about deprecated option(s).
     *
     * @param string[]    $options  A list of option names (without "--").
     * @param string|null $template The warning message template. "%s" is
     *                              replaced by the option name.
     */
    protected function warnAboutDeprecatedOptions(array $options, $template = null)
    {
        if (!isset($this->input)) {
            return;
        }
        if ($template === null) {
            $template = 'The option --%s is deprecated and no longer used. It will be removed in a future version.';
        }
        foreach ($options as $option) {
            if ($this->input->hasOption($option) && $this->input->getOption($option)) {
                $this->labeledMessage(
                    'DEPRECATED',
                    sprintf($template, $option)
                );
            }
        }
    }

    /**
     * Print a message with a label.
     *
     * @param string $label
     * @param string $message
     * @param int    $options
     */
    private function labeledMessage($label, $message, $options = 0)
    {
        if (isset($this->stdErr)) {
            $this->stdErr->writeln('<options=reverse>' . strtoupper($label) . '</> ' . $message, $options);
        }
    }

    /**
     * Get a service object.
     *
     * Services are configured in services.yml, and loaded via the Symfony
     * Dependency Injection component.
     *
     * When using this method, always store the result in a temporary variable,
     * so that the service's type can be hinted in a variable docblock (allowing
     * IDEs and other analysers to check subsequent code). For example:
     * <code>
     *   /** @var \Platformsh\Cli\Service\Filesystem $fs *\/
     *   $fs = $this->getService('fs');
     * </code>
     *
     * @param string $name The service name. See services.yml for a list.
     *
     * @return object The associated service object.
     */
    protected function getService($name)
    {
        return $this->container()->get($name);
    }

    /**
     * @return ContainerBuilder
     */
    private function container()
    {
        if (!isset(self::$container)) {
            self::$container = new ContainerBuilder();
            $loader = new YamlFileLoader(self::$container, new FileLocator());
            $loader->load(CLI_ROOT . '/services.yaml');
        }

        return self::$container;
    }

    /**
     * Get the configuration service.
     *
     * @return \Platformsh\Cli\Service\Config
     */
    protected function config()
    {
        static $config;
        if (!isset($config)) {
            /** @var \Platformsh\Cli\Service\Config $config */
            $config = $this->getService('config');
        }

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public function canBeRunMultipleTimes()
    {
        return $this->canBeRunMultipleTimes;
    }

    /**
     * {@inheritdoc}
     */
    public function setRunningViaMulti($runningViaMulti = true)
    {
        $this->runningViaMulti = $runningViaMulti;
    }

    /**
     * {@inheritdoc}
     */
    public function getSynopsis($short = false)
    {
        $key = $short ? 'short' : 'long';

        if (!isset($this->synopsis[$key])) {
            $aliases = $this->getVisibleAliases();
            $name = $this->getName();
            $shortName = count($aliases) === 1 ? reset($aliases) : $name;
            $this->synopsis[$key] = trim(sprintf(
                '%s %s %s',
                $this->config()->get('application.executable'),
                $shortName,
                $this->getDefinition()->getSynopsis($short)
            ));
        }

        return $this->synopsis[$key];
    }

    /**
     * @param resource|int $descriptor
     *
     * @return bool
     */
    protected function isTerminal($descriptor)
    {
        /** @noinspection PhpComposerExtensionStubsInspection */
        return !function_exists('posix_isatty') || posix_isatty($descriptor);
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled()
    {
        return $this->config()->isCommandEnabled($this->getName());
    }

    /**
     * Get help on how to use API tokens non-interactively.
     *
     * @param string $tag
     *
     * @return string
     */
    protected function getNonInteractiveAuthHelp($tag = 'info')
    {
        $prefix = $this->config()->get('application.env_prefix');

        return "To authenticate non-interactively, configure an API token using the <$tag>${prefix}TOKEN</$tag> environment variable.";
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription() {
        $description = parent::getDescription();

        if ($this->stability !== self::STABILITY_STABLE) {
            $prefix = '<fg=white;bg=red>[ ' . strtoupper($this->stability) . ' ]</> ';
            $description = $prefix . $description;
        }

        return $description;
    }

    /**
     * @param InputInterface $input
     * @param bool $allowLocal
     * @param RemoteContainer\RemoteContainerInterface|null $remoteContainer
     * @param bool $includeWorkers
     *
     * @return HostInterface
     */
    public function selectHost(InputInterface $input, $allowLocal = true, RemoteContainer\RemoteContainerInterface $remoteContainer = null, $includeWorkers = false)
    {
        /** @var Shell $shell */
        $shell = $this->getService('shell');

        if ($allowLocal && !LocalHost::conflictsWithCommandLineOptions($input, $this->config()->get('service.env_prefix'))) {
            $this->debug('Selected host: localhost');

            return new LocalHost($shell);
        }

        if ($remoteContainer === null) {
            if (!$this->hasSelectedEnvironment()) {
                $this->validateInput($input);
            }
            $remoteContainer = $this->selectRemoteContainer($input, $includeWorkers);
        }

        /** @var Ssh $ssh */
        $ssh = $this->getService('ssh');
        /** @var \Platformsh\Cli\Service\SshDiagnostics $sshDiagnostics */
        $sshDiagnostics = $this->getService('ssh_diagnostics');

        $this->debug('Selected host: ' . $remoteContainer->getSshUrl());

        return new RemoteHost($remoteContainer->getSshUrl(), $ssh, $shell, $sshDiagnostics);
    }

    /**
     * Finalizes login: refreshes SSH certificate, prints account information.
     */
    protected function finalizeLogin()
    {
        // Reset the API client so that it will use the new tokens.
        $client = $this->api()->getClient(false, true);
        $this->stdErr->writeln('You are logged in.');

        // Generate a new certificate from the certifier API.
        /** @var \Platformsh\Cli\Service\SshConfig $sshConfig */
        $sshConfig = $this->getService('ssh_config');
        /** @var \Platformsh\Cli\SshCert\Certifier $certifier */
        $certifier = $this->getService('certifier');
        if ($certifier->isAutoLoadEnabled() && $sshConfig->checkRequiredVersion()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Generating SSH certificate...');
            try {
                $certifier->generateCertificate();
                $this->stdErr->writeln('A new SSH certificate has been generated.');
                $this->stdErr->writeln('It will be automatically refreshed when necessary.');
            } catch (\Exception $e) {
                $this->stdErr->writeln('Failed to generate SSH certificate: <error>' . $e->getMessage() . '</error>');
            }
        }

        // Write SSH configuration.
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if ($sshConfig->configureSessionSsh()) {
            $sshConfig->addUserSshConfig($questionHelper);
        }

        // Show user account info.
        $info = $client->getAccountInfo();
        $this->stdErr->writeln(sprintf(
            "\nUsername: <info>%s</info>\nEmail address: <info>%s</info>",
            $info['username'],
            $info['mail']
        ));
    }

    /**
     * Shows information about the currently logged in user and their session, if applicable.
     *
     * @param bool $newline Whether to prepend a newline if there is output.
     */
    protected function showSessionInfo($newline = true)
    {
        $api = $this->api();
        $config = $this->config();
        $sessionId = $config->getSessionId();
        if ($sessionId !== 'default' || count($api->listSessionIds()) > 1) {
            if ($newline) {
                $this->stdErr->writeln('');
                $newline = false;
            }
            $this->stdErr->writeln(sprintf('The current session ID is: <info>%s</info>', $sessionId));
            if (!$config->isSessionIdFromEnv()) {
                $this->stdErr->writeln(sprintf('Change this using: <info>%s session:switch</info>', $config->get('application.executable')));
            }
        }
        if ($api->isLoggedIn()) {
            if ($newline) {
                $this->stdErr->writeln('');
            }
            $accountInfo = $api->getMyAccount();
            $this->stdErr->writeln(sprintf(
                'You are logged in as <info>%s</info> (<info>%s</info>)',
                $accountInfo['username'],
                $accountInfo['mail']
            ));
        }
    }
}
