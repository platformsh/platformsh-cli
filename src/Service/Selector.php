<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Console\Selection;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\RemoteContainer;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Service allowing the user to select the current project, environment and app.
 */
class Selector
{
    private $identifier;
    private $config;
    private $stdErr;
    private $api;
    private $localProject;
    private $questionHelper;
    private $git;
    private $hostFactory;

    /** @var Project|null */
    private $currentProject;

    /** @var string|null */
    private $projectRoot;

    /** @var string */
    private $envArgName = 'environment';

    public function __construct(
        OutputInterface $output,
        Identifier $identifier,
        Config $config,
        Api $api,
        LocalProject $localProject,
        QuestionHelper $questionHelper,
        Git $git,
        HostFactory $hostFactory
    ) {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->identifier = $identifier;
        $this->config = $config;
        $this->api = $api;
        $this->localProject = $localProject;
        $this->questionHelper = $questionHelper;
        $this->git = $git;
        $this->hostFactory = $hostFactory;
    }

    public function setEnvArgName($envArgName = 'environment')
    {
        $this->envArgName = $envArgName;
    }

    /**
     * Prints a message if debug output is enabled.
     *
     * @todo refactor this
     *
     * @param string $message
     */
    private function debug($message)
    {
        $this->stdErr->writeln('<options=reverse>DEBUG</> ' . $message, OutputInterface::VERBOSITY_DEBUG);
    }

    /**
     * Processes and validates input to select the project, environment and app.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param bool                                            $envNotRequired
     * @param bool                                            $allowLocalHost
     * @param bool                                            $requireApiOnLocal
     *
     * @return Selection
     */
    public function getSelection(InputInterface $input, $envNotRequired = false, $allowLocalHost = false, $requireApiOnLocal = false)
    {
        // Determine whether the localhost can be used.
        $envPrefix = $this->config->get('service.env_prefix');
        if ($allowLocalHost && LocalHost::conflictsWithCommandLineOptions($input, $envPrefix)) {
            $allowLocalHost = false;
        }

        // If the user is not logged in, then return the localhost without selecting the project/environment.
        if ($allowLocalHost && !$requireApiOnLocal && !$this->api->isLoggedIn()) {
            return new Selection(null, null, getenv($envPrefix . 'APPLICATION_NAME') ?: null, null, $this->hostFactory->local());
        }

        $projectId = $input->hasOption('project') ? $input->getOption('project') : null;
        $projectHost = $input->hasOption('host') ? $input->getOption('host') : null;
        $environmentId = null;

        // Identify the project.
        if ($projectId !== null) {
            $result = $this->identifier->identify($projectId);
            $projectId = $result['projectId'];
            $projectHost = $projectHost ?: $result['host'];
            $environmentId = $result['environmentId'];
        }

        // Load the project ID from an environment variable, if available.
        if ($projectId === null && getenv($envPrefix . 'PROJECT')) {
            $projectId = getenv($envPrefix . 'PROJECT');
            $this->stdErr->writeln(sprintf(
                'Project ID read from environment variable %s: %s',
                $envPrefix . 'PROJECT',
                $projectId
            ), OutputInterface::VERBOSITY_VERBOSE);
        }

        // Select the project.
        $project = $this->selectProject($input, $projectId, $projectHost);

        // Select the environment.
        $envOptionName = 'environment';
        $environment = null;

        if ($input->hasArgument($this->envArgName)
            && $input->getArgument($this->envArgName) !== null
            && $input->getArgument($this->envArgName) !== []) {
            if ($input->hasOption($envOptionName) && $input->getOption($envOptionName)) {
                throw new InvalidArgumentException(
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
                $environment = $this->selectEnvironment($input, $project, $argument);
            }
        } elseif ($input->hasOption($envOptionName)) {
            if ($input->getOption($envOptionName) !== null) {
                $environmentId = $input->getOption($envOptionName);
            }
            $environment = $this->selectEnvironment($input, $project, $environmentId, !$envNotRequired);
        }

        // Select the app.
        $appName = null;
        if ($input->hasOption('app') && !$input->getOption('app')) {
            // An app ID might be provided from the parsed project URL.
            if (isset($result) && isset($result['appId'])) {
                $appName = $result['appId'];
                $this->debug(sprintf(
                    'App name identified as: %s',
                    $input->getOption('app')
                ));
            }
            // Or from an environment variable.
            elseif (getenv($envPrefix . 'APPLICATION_NAME')) {
                $appName = getenv($envPrefix . 'APPLICATION_NAME');
                $this->stdErr->writeln(sprintf(
                    'App name read from environment variable %s: %s',
                    $envPrefix . 'APPLICATION_NAME',
                    $input->getOption('app')
                ), OutputInterface::VERBOSITY_VERBOSE);
            } elseif ($input->getOption('app')) {
                $appName = $input->getOption('app');
            }
        }

        $remoteContainer = null;
        if ($environment !== null && $input->hasOption('app')) {
            $remoteContainer = $this->selectRemoteContainer($environment, $input, $appName);
        }

        $host = null;
        if ($allowLocalHost) {
            $host = $this->hostFactory->local();
        } elseif ($remoteContainer !== null) {
            $host = $this->hostFactory->remote($remoteContainer->getSshUrl());
        }

        return new Selection($project, $environment, $appName, $remoteContainer, $host);
    }

    /**
     * Select the project for the user, based on input or the environment.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param string                                          $projectId
     * @param string                                          $host
     *
     * @return Project
     */
    private function selectProject(InputInterface $input, $projectId = null, $host = null)
    {
        if (!empty($projectId)) {
            $project = $this->api->getProject($projectId, $host);
            if (!$project) {
                throw new InvalidArgumentException($this->getProjectNotFoundMessage($projectId));
            }

            $this->debug('Selected project: ' . $project->id);

            return $project;
        }

        $project = $this->getCurrentProject();
        if (!$project && $input->isInteractive()) {
            $projects = $this->api->getProjects();
            if (count($projects) > 0 && count($projects) < 25) {
                $this->debug('No project specified: offering a choice...');
                $projectId = $this->offerProjectChoice($input, $projects);

                return $this->selectProject($input, $projectId);
            }
        }
        if (!$project) {
            throw new RootNotFoundException(
                "Could not determine the current project."
                . "\n\nSpecify it using --project, or go to a project directory."
            );
        }

        return $project;
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
        if ($projects = $this->api->getProjects()) {
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
                $message .= "\n\n    List projects with: " . $this->config->get('application.executable') . ' project:list';
            }
        }

        return $message;
    }

    /**
     * Select the current environment for the user.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param Project                                         $project
     *   The project, or null if no project is selected.
     * @param string|null                                     $environmentId
     *   The environment ID specified by the user, or null to auto-detect the
     *   environment.
     * @param bool                                            $required
     *   Whether it's required to have an environment.
     *
     * @return Environment|null
     */
    private function selectEnvironment(InputInterface $input, Project $project, $environmentId = null, $required = true)
    {
        $envPrefix = $this->config->get('service.env_prefix');
        if ($environmentId === null && getenv($envPrefix . 'BRANCH')) {
            $environmentId = getenv($envPrefix . 'BRANCH');
            $this->stdErr->writeln(sprintf(
                'Environment ID read from environment variable %s: %s',
                $envPrefix . 'BRANCH',
                $environmentId
            ), OutputInterface::VERBOSITY_VERBOSE);
        }

        if ($environmentId !== null) {
            $environment = $this->api->getEnvironment($environmentId, $project, null, true);
            if (!$environment) {
                throw new InvalidArgumentException('Specified environment not found: ' . $environmentId);
            }

            $this->debug('Selected environment: ' . $this->api->getEnvironmentLabel($environment));
            return $environment;
        }

        if ($environment = $this->getCurrentEnvironment($project)) {
            return $environment;
        }

        if ($required && $input->isInteractive()) {
            $this->debug('No environment specified: offering a choice...');
            return $this->offerEnvironmentChoice($input, $this->api->getEnvironments($project));
        }

        if ($required) {
            if ($this->getProjectRoot()) {
                $message = 'Could not determine the current environment.'
                    . "\n" . 'Specify it manually using --environment (-e).';
            } else {
                $message = 'No environment specified.'
                    . "\n" . 'Specify one using --environment (-e), or go to a project directory.';
            }
            throw new InvalidArgumentException($message);
        }

        return null;
    }

    /**
     * Offer the user an interactive choice of projects.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param Project[]                                       $projects
     *
     * @return string
     *   The chosen project ID.
     */
    private function offerProjectChoice(InputInterface $input, array $projects)
    {
        if (!$input->isInteractive()) {
            throw new \BadMethodCallException('Not interactive: a project choice cannot be offered.');
        }

        // Build and sort a list of project options.
        $projectList = [];
        foreach ($projects as $project) {
            $projectList[$project->id] = $this->api->getProjectLabel($project, false);
        }
        asort($projectList, SORT_NATURAL | SORT_FLAG_CASE);

        $id = $this->questionHelper->choose($projectList, 'Enter a number to choose a project:', null, false);

        return $id;
    }

    /**
     * Offers a choice of environments.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param Environment[]                                   $environments
     *
     * @return Environment
     */
    private function offerEnvironmentChoice(InputInterface $input, array $environments)
    {
        if (!$input->isInteractive()) {
            throw new \BadMethodCallException('Not interactive: an environment choice cannot be offered.');
        }

        $default = $this->api->getDefaultEnvironmentId($environments);

        // Build and sort a list of options (environment IDs).
        $ids = array_keys($environments);
        sort($ids, SORT_NATURAL | SORT_FLAG_CASE);

        $id = $this->questionHelper->askInput('Environment ID', $default, $ids, function ($value) use ($environments) {
            if (!isset($environments[$value])) {
                throw new \RuntimeException('Environment not found: ' . $value);
            }

            return $value;
        });

        $this->stdErr->writeln('');

        return $environments[$id];
    }

    /**
     * Get the current project if the user is in a project directory.
     *
     * @throws \RuntimeException
     *
     * @return Project|false The current project
     */
    public function getCurrentProject()
    {
        if (isset($this->currentProject)) {
            return $this->currentProject;
        }
        if (!$projectRoot = $this->getProjectRoot()) {
            return false;
        }

        $project = false;
        $config = $this->localProject->getProjectConfig($projectRoot);
        if ($config) {
            $project = $this->api->getProject($config['id'], isset($config['host']) ? $config['host'] : null);
            if (!$project) {
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
            || !($project = $this->getCurrentProject())
            || ($expectedProject !== null && $expectedProject->id !== $project->id)) {
            return false;
        }

        $this->git->setDefaultRepositoryDir($this->getProjectRoot());
        $config = $this->localProject->getProjectConfig($projectRoot);

        // Check if there is a manual mapping set for the current branch.
        if (!empty($config['mapping'])
            && ($currentBranch = $this->git->getCurrentBranch())
            && !empty($config['mapping'][$currentBranch])) {
            $environment = $this->api->getEnvironment($config['mapping'][$currentBranch], $project, $refresh);
            if ($environment) {
                $this->debug('Found mapped environment for branch ' . $currentBranch . ': ' . $this->api->getEnvironmentLabel($environment));
                return $environment;
            } else {
                unset($config['mapping'][$currentBranch]);
                $this->localProject->writeCurrentProjectConfig($config, $projectRoot);
            }
        }

        // Check whether the user has a Git upstream set to a remote environment
        // ID.
        $upstream = $this->git->getUpstream();
        if ($upstream && strpos($upstream, '/') !== false) {
            list(, $potentialEnvironment) = explode('/', $upstream, 2);
            $environment = $this->api->getEnvironment($potentialEnvironment, $project, $refresh);
            if ($environment) {
                $this->debug('Selected environment ' . $this->api->getEnvironmentLabel($environment) . ', based on Git upstream: ' . $upstream);
                return $environment;
            }
        }

        // There is no Git remote set. Fall back to trying the current branch
        // name.
        if (!empty($currentBranch) || ($currentBranch = $this->git->getCurrentBranch())) {
            $environment = $this->api->getEnvironment($currentBranch, $project, $refresh);
            if (!$environment) {
                // Try a sanitized version of the branch name too.
                $currentBranchSanitized = Environment::sanitizeId($currentBranch);
                if ($currentBranchSanitized !== $currentBranch) {
                    $environment = $this->api->getEnvironment($currentBranchSanitized, $project, $refresh);
                }
            }
            if ($environment) {
                $this->debug('Selected environment ' . $this->api->getEnvironmentLabel($environment) . ' based on branch name: ' . $currentBranch);
                return $environment;
            }
        }

        return false;
    }

    /**
     * @return string|false
     */
    public function getProjectRoot()
    {
        if (!isset($this->projectRoot)) {
            $this->debug('Finding the project root');
            $this->projectRoot = $this->localProject->getProjectRoot();
            $this->debug(
                $this->projectRoot
                    ? 'Project root found: ' . $this->projectRoot
                    : 'Project root not found'
            );
        }

        return $this->projectRoot;
    }

    /**
     * Add the --project and --host options.
     *
     * @param \Symfony\Component\Console\Input\InputDefinition $inputDefinition
     */
    public function addProjectOption(InputDefinition $inputDefinition)
    {
        $inputDefinition->addOption(new InputOption('project', 'p', InputOption::VALUE_REQUIRED, 'The project ID or URL'));
        $inputDefinition->addOption(new InputOption('host', null, InputOption::VALUE_REQUIRED, "The project's API hostname"));
    }

    /**
     * Add the --environment option.
     *
     * @param \Symfony\Component\Console\Input\InputDefinition $inputDefinition
     */
    public function addEnvironmentOption(InputDefinition $inputDefinition)
    {
        $inputDefinition->addOption(new InputOption('environment', 'e', InputOption::VALUE_REQUIRED, 'The environment ID'));
    }

    /**
     * Add the --app option.
     *
     * @param \Symfony\Component\Console\Input\InputDefinition $inputDefinition
     */
    public function addAppOption(InputDefinition $inputDefinition)
    {
        $inputDefinition->addOption(new InputOption('app', 'A', InputOption::VALUE_REQUIRED, 'The remote application name'));
    }

    /**
     * Add all selection options (project, environment and app).
     *
     * @param \Symfony\Component\Console\Input\InputDefinition $inputDefinition
     * @param bool                                             $includeWorkers
     */
    public function addAllOptions(InputDefinition $inputDefinition, $includeWorkers = false)
    {
        $this->addProjectOption($inputDefinition);
        $this->addEnvironmentOption($inputDefinition);
        $this->addAppOption($inputDefinition);
        if ($includeWorkers) {
            $inputDefinition->addOption(new InputOption('worker', null, InputOption::VALUE_REQUIRED, 'A worker name'));
        }
    }

    /**
     * Find what app or worker container the user wants to select.
     *
     * Needs the --app and --worker options, as applicable.
     *
     * @param \Platformsh\Client\Model\Environment $environment
     * @param InputInterface                       $input
     *   The user input object.
     * @param string|null                          $appName
     *
     * @return \Platformsh\Cli\Model\RemoteContainer\RemoteContainerInterface
     *   A class representing a container that allows SSH access.
     */
    private function selectRemoteContainer(Environment $environment, InputInterface $input, ?string $appName)
    {
        $includeWorkers = $input->hasOption('worker');
        try {
            $deployment = $this->api->getCurrentDeployment(
                $environment,
                $input->hasOption('refresh') && $input->getOption('refresh')
            );
        } catch (EnvironmentStateException $e) {
            if ($environment->isActive() && $e->getMessage() === 'Current deployment not found') {
                $appName = $input->hasOption('app') ? $input->getOption('app') : '';

                return new RemoteContainer\BrokenEnv($environment, $appName);
            }
            throw $e;
        }

        // Validate the --app option, without doing anything with it.
        if ($appName === null) {
            $appName = $input->hasOption('app') ? $input->getOption('app') : null;
        }
        if ($appName !== null) {
            try {
                $deployment->getWebApp($appName);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidArgumentException('Application not found: ' . $appName);
            }
        }

        // Handle the --worker option first, as it's more specific.
        $workerOption = $input->hasOption('worker') ? $input->getOption('worker') : null;
        if ($workerOption !== null) {
            // Check for a conflict with the --app option.
            if ($appName !== null
                && strpos($workerOption, '--') !== false
                && stripos($workerOption, $appName . '--') !== 0) {
                throw new \InvalidArgumentException(sprintf(
                    'App name "%s" conflicts with worker name "%s"',
                    $appName,
                    $workerOption
                ));
            }

            // If we have the app name, load the worker directly.
            if (strpos($workerOption, '--') !== false || $appName !== null) {
                $qualifiedWorkerName = strpos($workerOption, '--') !== false
                    ? $workerOption
                    : $appName . '--' . $workerOption;
                try {
                    $worker = $deployment->getWorker($qualifiedWorkerName);
                } catch (\InvalidArgumentException $e) {
                    throw new InvalidArgumentException('Worker not found: ' . $workerOption);
                }

                return new RemoteContainer\Worker($worker, $environment);
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
                throw new InvalidArgumentException('Worker not found: ' . $workerOption);
            }
            if (count($workerNames) === 1) {
                $workerName = reset($workerNames);

                return new RemoteContainer\Worker($deployment->getWorker($workerName), $environment);
            }
            if (!$input->isInteractive()) {
                throw new InvalidArgumentException(sprintf(
                    'Ambiguous worker name: %s (matches: %s)',
                    $workerOption,
                    implode(', ', $workerNames)
                ));
            }
            $workerName = $this->questionHelper->choose(
                $workerNames,
                'Enter a number to choose a worker:'
            );

            return new RemoteContainer\Worker($deployment->getWorker($workerName), $environment);
        }

        // Prompt the user to choose between the app(s) or worker(s) that have
        // been found.
        $default = null;
        $appNames = $appName !== null
            ? [$appName]
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
            if ($includeWorkers) {
                $text = sprintf('Enter a number to choose %s app or %s worker:',
                    count($appNames) === 1 ? 'the' : 'an',
                    count($choices) === 2 ? 'its' : 'a'
                );
            } else {
                $text = sprintf('Enter a number to choose %s app:',
                    count($appNames) === 1 ? 'the' : 'an'
                );
            }
            $choice = $this->questionHelper->choose(
                $choices,
                $text,
                $default
            );
        } elseif (count($appNames) === 1) {
            $choice = reset($appNames);
        } else {
            throw new InvalidArgumentException(
                $includeWorkers
                    ? 'Specifying the --app or --worker is required in non-interactive mode'
                    : 'Specifying the --app is required in non-interactive mode'
            );
        }

        // Match the choice to a worker or app destination.
        if (strpos($choice, '--') !== false) {
            return new RemoteContainer\Worker($deployment->getWorker($choice), $environment);
        }

        return new RemoteContainer\App($deployment->getWebApp($choice), $environment);
    }
}
