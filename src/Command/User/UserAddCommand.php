<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Model\EnvironmentAccess;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\ProjectAccess;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class UserAddCommand extends CommandBase
{
    protected static $defaultName = 'user:add';

    protected $activityService;
    protected $selector;

    private $api;
    private $config;
    private $questionHelper;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        Config $config,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Add a user to the project')
            ->addArgument('email', InputArgument::OPTIONAL, "The user's email address");
        $this->addRoleOption();

        $this->selector->addProjectOption($this->getDefinition());
        $this->activityService->configureInput($this->getDefinition());

        $this->addExample('Add Alice as a project admin', 'alice@example.com -r admin');
    }

    /**
     * Adds the --role (-r) option to the command.
     */
    protected function addRoleOption()
    {
        $this->addOption(
            'role',
            'r',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            "The user's project role ('admin' or 'viewer') or environment-specific role (e.g. 'master:contributor' or 'stage:viewer')."
            . "\nThe character % can be used as a wildcard in the environment ID e.g. '%:viewer'."
            . "\nThe role can be abbreviated, e.g. 'master:c'."
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();

        $hasOutput = false;

        // Process the --role option.
        $roleInput = $input->getOption('role');
        if (count($input->getOption('role')) === 1) {
            // Split comma-separated values.
            $roleInput = preg_split('/[\s,]+/', reset($roleInput));
        }
        $specifiedProjectRole = $this->getSpecifiedProjectRole($roleInput);
        $specifiedEnvironmentRoles = $this->getSpecifiedEnvironmentRoles($project, $roleInput);
        unset($roleInput);

        // Process the [email] argument.
        $email = $input->getArgument('email');
        if (!$email) {
            $update = stripos($input->getFirstArgument(), ':u');
            if ($update && $input->isInteractive()) {
                $choices = [];
                foreach ($this->api->getProjectAccesses($project) as $access) {
                    $account = $this->api->getAccount($access);
                    $choices[$account['email']] = $this->getUserLabel($access);
                }
                $email = $this->questionHelper->choose($choices, 'Enter a number to choose a user to update:');
            } else {
                $question = new Question("Enter the user's email address: ");
                $question->setValidator(function ($answer) {
                    return $this->validateEmail($answer);
                });
                $question->setMaxAttempts(5);
                $email = $this->questionHelper->ask($input, $this->stdErr, $question);
                $hasOutput = true;
            }
        }
        $this->validateEmail($email);

        // Check the user's existing role on the project.
        $existingProjectAccess = $this->api->loadProjectAccessByEmail($project, $email);
        $existingEnvironmentRoles = [];
        if ($existingProjectAccess) {
            // Exit if the user is the owner already.
            if ($existingProjectAccess->id === $project->owner) {
                if ($hasOutput) {
                    $this->stdErr->writeln('');
                }

                $this->stdErr->writeln(sprintf('The user %s is the owner of %s.', $this->getUserLabel($existingProjectAccess), $this->api->getProjectLabel($project)));
                if ($specifiedProjectRole || $specifiedEnvironmentRoles) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln("<comment>The project owner's role(s) cannot be changed.</comment>");

                    return 1;
                }

                return 0;
            }

            // Check the user's existing role(s) on the project's environments.
            $existingEnvironmentRoles = $this->getEnvironmentRoles($project, $existingProjectAccess);
        }

        // If the user already exists, print a summary of their roles on the
        // project and environments.
        if ($existingProjectAccess) {
            if ($hasOutput) {
                $this->stdErr->writeln('');
            }

            $this->stdErr->writeln(sprintf('Current role(s) of <info>%s</info> on %s:', $this->getUserLabel($existingProjectAccess), $this->api->getProjectLabel($project)));
            $this->stdErr->writeln(sprintf('  Project role: <info>%s</info>', $existingProjectAccess->role));
            foreach ($existingEnvironmentRoles as $id => $role) {
                $this->stdErr->writeln(sprintf('    Role on <info>%s</info>: %s', $id, $role));
            }
            $hasOutput = true;
        }

        // Resolve or merge the project role.
        $desiredProjectRole = $specifiedProjectRole ?: ($existingProjectAccess ? $existingProjectAccess->role : ProjectAccess::ROLE_VIEWER);
        $provideProjectForm = !$input->getOption('role') && $input->isInteractive();
        if ($provideProjectForm) {
            if ($hasOutput) {
                $this->stdErr->writeln('');
            }
            $desiredProjectRole = $this->showProjectRoleForm($desiredProjectRole, $input);
            $hasOutput = true;
        }

        // Resolve or merge the environment role(s).
        $provideEnvironmentForm = $input->isInteractive()
            && $desiredProjectRole !== ProjectAccess::ROLE_ADMIN
            && !$specifiedEnvironmentRoles;
        $desiredEnvironmentRoles = [];
        if ($desiredProjectRole !== ProjectAccess::ROLE_ADMIN) {
            foreach ($this->api->getEnvironments($project) as $id => $environment) {
                if (isset($specifiedEnvironmentRoles[$id])) {
                    $desiredEnvironmentRoles[$id] = $specifiedEnvironmentRoles[$id];
                } elseif (isset($existingEnvironmentRoles[$id])) {
                    $desiredEnvironmentRoles[$id] = $existingEnvironmentRoles[$id];
                }
            }
        }
        if ($provideEnvironmentForm) {
            if ($hasOutput) {
                $this->stdErr->writeln('');
            }
            $desiredEnvironmentRoles = $this->showEnvironmentRolesForm($project, $desiredEnvironmentRoles, $input);
            $hasOutput = true;
        }

        // Build a list of the changes that are going to be made.
        $changes = [];
        if ($existingProjectAccess) {
            if ($existingProjectAccess->role !== $desiredProjectRole) {
                $changes[] = sprintf('Project role: <error>%s</error> -> <info>%s</info>', $existingProjectAccess->role, $desiredProjectRole);
            }
        } else {
            $changes[] = sprintf('Project role: <info>%s</info>', $desiredProjectRole);
        }
        if ($desiredProjectRole !== ProjectAccess::ROLE_ADMIN) {
            foreach ($this->api->getEnvironments($project) as $id => $environment) {
                $new = isset($desiredEnvironmentRoles[$id]) ? $desiredEnvironmentRoles[$id] : 'none';
                if ($existingEnvironmentRoles) {
                    $existing = isset($existingEnvironmentRoles[$id]) ? $existingEnvironmentRoles[$id] : 'none';
                    if ($existing !== $new) {
                        $changes[] = sprintf('  Role on <info>%s</info>: <error>%s</error> -> <info>%s</info>', $id, $existing, $new);
                    }
                } elseif ($new !== 'none') {
                    $changes[] = sprintf('  Role on <info>%s</info>: <info>%s</info>', $id, $new);
                }
            }
        }

        // Filter out environment roles of 'none' from the list.
        $desiredEnvironmentRoles = array_filter($desiredEnvironmentRoles, function ($role) {
            return $role !== 'none';
        });

        // Require project non-admins to be added to at least one environment.
        if ($desiredProjectRole === ProjectAccess::ROLE_VIEWER && !$desiredEnvironmentRoles) {
            if ($hasOutput) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln('<error>No environments selected.</error>');
            $this->stdErr->writeln('A non-admin user must be added to at least one environment.');

            if ($existingProjectAccess) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'To delete the user, run: <info>%s user:delete %s</info>',
                    $this->config->get('application.executable'),
                    $this->api->getAccount($existingProjectAccess)['email']
                ));
            }

            return 1;
        }

        // Prevent changing environment access for project admins.
        if ($desiredProjectRole === ProjectAccess::ROLE_ADMIN && $specifiedEnvironmentRoles) {
            if ($hasOutput) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln('<comment>A project admin has administrative access to all environments.</comment>');
            $this->stdErr->writeln("To set the user's environment role(s), set their project role to '" . ProjectAccess::ROLE_VIEWER . "'.");

            return 1;
        }

        // Exit early if there are no changes to make.
        if (empty($changes)) {
            if ($provideProjectForm || $provideEnvironmentForm) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('There are no changes to make.');
            }

            return 0;
        }

        // Add a new line if there has already been output.
        if ($hasOutput) {
            $this->stdErr->writeln('');
        }

        // Print a summary of the changes that are about to be made.
        if ($existingProjectAccess) {
            $this->stdErr->writeln('Summary of changes:');
        } else {
            $this->stdErr->writeln(sprintf('Adding the user <info>%s</info> to %s:', $email, $this->api->getProjectLabel($project)));
        }
        foreach ($changes as $change) {
            $this->stdErr->writeln('  ' . $change);
        }
        $this->stdErr->writeln('');

        // Ask for confirmation.
        if ($existingProjectAccess) {
            if (!$this->questionHelper->confirm('Are you sure you want to make these change(s)?')) {

                return 1;
            }
        } else {
            $this->stdErr->writeln('<comment>Adding users can result in additional charges.</comment>');
            $this->stdErr->writeln('');
            if (!$this->questionHelper->confirm('Are you sure you want to add this user?')) {

                return 1;
            }
        }

        // Make the required modifications on the project level: add the user,
        // change their role, or do nothing.
        if (!$existingProjectAccess) {
            $this->stdErr->writeln("Adding the user to the project");
            $result = $project->addUser($email, $desiredProjectRole);
            $activities = $result->getActivities();
            /** @var ProjectAccess $projectAccess */
            $projectAccess = $result->getEntity();
            $userId = $projectAccess->id;
        } elseif ($existingProjectAccess->role !== $desiredProjectRole) {
            $this->stdErr->writeln("Setting the user's project role to: $desiredProjectRole");
            $result = $existingProjectAccess->update(['role' => $desiredProjectRole]);
            $activities = $result->getActivities();
            $userId = $existingProjectAccess->id;
        } else {
            $userId = $existingProjectAccess->id;
            $activities = [];
        }

        // Make the desired changes at the environment level.
        if ($desiredProjectRole !== ProjectAccess::ROLE_ADMIN) {
            foreach ($this->api->getEnvironments($project) as $environmentId => $environment) {
                $role = isset($desiredEnvironmentRoles[$environmentId]) ? $desiredEnvironmentRoles[$environmentId] : 'none';
                $access = $environment->getUser($userId);
                if ($role === 'none') {
                    if ($access) {
                        $this->stdErr->writeln("Removing the user from the environment <info>$environmentId</info>");
                        $result = $access->delete();
                    } else {
                        continue;
                    }
                } else {
                    if ($access) {
                        if ($access->role === $role) {
                            continue;
                        }
                        $this->stdErr->writeln("Setting the user's role on the environment <info>$environmentId</info> to: $role");
                        $result = $access->update(['role' => $role]);
                    } else {
                        $this->stdErr->writeln("Adding the user to the environment: <info>$environmentId</info>");
                        $result = $environment->addUser($userId, $role);
                    }
                }
                $activities = array_merge($activities, $result->getActivities());
            }
        }

        // Wait for activities to complete.
        if ($this->activityService->shouldWait($input)) {
            if (!$this->activityService->waitMultiple($activities, $project)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function validateProjectRole($value)
    {
        return $this->matchRole($value, ProjectAccess::$roles);
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function validateEnvironmentRole($value)
    {
        return $this->matchRole($value, array_merge(EnvironmentAccess::$roles, ['none']));
    }

    /**
     * Validate an email address.
     *
     * @param string $value
     *
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     *
     * @return string
     */
    private function validateEmail($value)
    {
        if (empty($value)) {
            throw new InvalidArgumentException('An email address is required.');
        }
        if (!$filtered = filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address: ' . $value);
        }

        return $filtered;
    }

    /**
     * Complete a role name based on an array of allowed roles.
     *
     * @param string   $input
     * @param string[] $roles
     *
     * @return string
     */
    private function matchRole($input, array $roles)
    {
        foreach ($roles as $role) {
            if (strpos($role, strtolower($input)) === 0) {
                return $role;
            }
        }

        throw new InvalidArgumentException('Invalid role: ' . $input);
    }

    /**
     * Expand roles into a list with abbreviations.
     *
     * @param string[] $roles
     *
     * @return string
     */
    private function describeRoles(array $roles)
    {
        $withInitials = array_map(function ($role) {
            return sprintf('%s (%s)', $role, substr($role, 0, 1));
        }, $roles);
        $last = array_pop($withInitials);

        return implode(' or ', [implode(', ', $withInitials), $last]);
    }

    /**
     * Describe the input for a roles question, e.g. [a/c/v/n].
     *
     * @param string[] $roles
     *
     * @return string
     */
    private function describeRoleInput(array $roles)
    {
        return '[' . implode('/', array_map(function ($role) {
            return substr($role, 0, 1);
        }, $roles)) . ']';
    }

    /**
     * Return a label describing a user.
     *
     * @param \Platformsh\Client\Model\ProjectAccess $access
     *
     * @return string
     */
    private function getUserLabel(ProjectAccess $access)
    {
        $account = $this->api->getAccount($access);

        return sprintf('<info>%s</info> (%s)', $account['display_name'], $account['email']);
    }

    /**
     * Show the form for entering the project role.
     *
     * @param string                                          $defaultRole
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *
     * @return string
     */
    private function showProjectRoleForm($defaultRole, InputInterface $input)
    {
        $this->stdErr->writeln("The user's project role can be " . $this->describeRoles(ProjectAccess::$roles) . '.');
        $this->stdErr->writeln('');
        $question = new Question(
            sprintf('Project role (default: %s) <question>%s</question>: ', $defaultRole, $this->describeRoleInput(ProjectAccess::$roles)),
            $defaultRole
        );
        $question->setValidator(function ($answer) {
            return $this->validateProjectRole($answer);
        });
        $question->setMaxAttempts(5);
        $question->setAutocompleterValues(ProjectAccess::$roles);

        return $this->questionHelper->ask($input, $this->stdErr, $question);
    }

    /**
     * Load the user's roles on the project's environments.
     *
     * @param \Platformsh\Client\Model\Project       $project
     * @param \Platformsh\Client\Model\ProjectAccess $projectAccess
     *
     * @return array
     */
    private function getEnvironmentRoles(Project $project, ProjectAccess $projectAccess)
    {
        $environmentRoles = [];
        if ($projectAccess->role === ProjectAccess::ROLE_ADMIN) {
            return [];
        }

        // @todo find out why $environment->getUser() has permission issues - it would be a lot faster than this

        $progress = new ProgressBar(isset($this->stdErr) && $this->stdErr->isDecorated() ? $this->stdErr : new NullOutput());
        $progress->setMessage('Loading environments...');
        $progress->setFormat('%message% %current%/%max%');
        $environments = $this->api->getEnvironments($project);
        $progress->start(count($environments));
        foreach ($environments as $environment) {
            if ($access = $environment->getUser($projectAccess->id)) {
                $environmentRoles[$environment->id] = $access->role;
            }
            $progress->advance();
        }
        $progress->finish();
        $progress->clear();

        return $environmentRoles;
    }

    /**
     * Show the form for entering environment roles.
     *
     * @param \Platformsh\Client\Model\Project                $project
     * @param array                                           $defaultEnvironmentRoles
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *
     * @return array
     *   The environment roles (keyed by environment ID) including the user's
     *   answers.
     */
    private function showEnvironmentRolesForm(Project $project, array $defaultEnvironmentRoles, InputInterface $input)
    {
        $desiredEnvironmentRoles = [];
        $validEnvironmentRoles = array_merge(EnvironmentAccess::$roles, ['none']);
        $this->stdErr->writeln("The user's environment role(s) can be " . $this->describeRoles($validEnvironmentRoles) . '.');
        $initials = $this->describeRoleInput($validEnvironmentRoles);
        $this->stdErr->writeln('');
        foreach (array_keys($this->api->getEnvironments($project)) as $id) {
            $default = isset($defaultEnvironmentRoles[$id]) ? $defaultEnvironmentRoles[$id] : 'none';
            $question = new Question(
                sprintf('Role on <info>%s</info> (default: %s) <question>%s</question>: ', $id, $default, $initials),
                $default
            );
            $question->setValidator(function ($answer) {
                if ($answer === 'q' || $answer === 'quit') {
                    return $answer;
                }

                return $this->validateEnvironmentRole($answer);
            });
            $question->setAutocompleterValues(array_merge($validEnvironmentRoles, ['quit']));
            $question->setMaxAttempts(5);
            $answer = $this->questionHelper->ask($input, $this->stdErr, $question);
            if ($answer === 'q' || $answer === 'quit') {
                break;
            } else {
                $desiredEnvironmentRoles[$id] = $answer;
            }
        }

        return $desiredEnvironmentRoles;
    }

    /**
     * Extract the specified project role from the list (given in --role).
     *
     * @param array $roles
     *
     * @return string|null
     *   The project role, or null if none is specified.
     */
    private function getSpecifiedProjectRole(array $roles)
    {
        foreach ($roles as $role) {
            if (strpos($role, ':') === false) {
                return $this->validateProjectRole($role);
            }
        }

        return null;
    }

    /**
     * Extract the specified environment roles from the list (given in --role).
     *
     * @param Project  $project
     * @param string[] $roles
     *
     * @return array
     *   An array of environment roles, keyed by environment ID.
     */
    private function getSpecifiedEnvironmentRoles(Project $project, array $roles)
    {
        $environmentRoles = [];
        $environments = $this->api->getEnvironments($project);
        foreach ($roles as $role) {
            if (strpos($role, ':') === false) {
                continue;
            }
            list($id, $role) = explode(':', $role, 2);
            $role = $this->validateEnvironmentRole($role);
            // Match environment IDs by wildcard.
            if (strpos($id, '%') !== false) {
                $pattern = '/^' . str_replace('%', '.*', preg_quote($id)) . '$/';
                $matched = preg_grep($pattern, array_keys($environments));
                if (empty($matched)) {
                    throw new InvalidArgumentException('No environment IDs match: ' . $id);
                }
                foreach ($matched as $environmentId) {
                    $environmentRoles[$environmentId] = $role;
                }
                continue;
            }
            if (!$this->api->getEnvironment($id, $project)) {
                throw new InvalidArgumentException('Environment not found: ' . $id);
            }
            $environmentRoles[$id] = $role;
        }

        return $environmentRoles;
    }
}
