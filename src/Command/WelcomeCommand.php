<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WelcomeCommand extends CommandBase
{
    protected static $defaultName = 'welcome';

    private $api;
    private $config;
    private $subCommandRunner;
    private $selector;

    public function __construct(
        Api $api,
        Config $config,
        SubCommandRunner $subCommandRunner,
        Selector $selector
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->subCommandRunner = $subCommandRunner;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Welcome to ' . $this->config->get('service.name'));
        $this->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->stdErr->writeln("Welcome to " . $this->config->get('service.name') . "!\n");

        $envPrefix = $this->config->get('service.env_prefix');
        $onContainer = getenv($envPrefix . 'PROJECT') && getenv($envPrefix . 'BRANCH');

        if ($project = $this->selector->getCurrentProject()) {
            $this->welcomeForLocalProjectDir($project);
        } elseif ($onContainer) {
            $this->welcomeOnContainer();
        } else {
            $this->defaultWelcome();
        }

        $executable = $this->config->get('application.executable');

        if ($this->api->isLoggedIn()) {
            $this->stdErr->writeln("Manage your SSH keys by running <info>$executable ssh-keys</info>\n");
        }

        $this->stdErr->writeln("To view all commands, run: <info>$executable list</info>");
    }

    /**
     * Display default welcome message, when not in a project directory.
     */
    private function defaultWelcome()
    {
        // The project is not known. Show all projects.
        $this->subCommandRunner->run('projects', ['--refresh' => 0]);
        $this->stdErr->writeln('');
    }

    /**
     * Display welcome for a local project directory.
     *
     * @param \Platformsh\Client\Model\Project $project
     */
    private function welcomeForLocalProjectDir(Project $project)
    {
        $projectUri = $project->getLink('#ui');
        $this->stdErr->writeln("Project title: <info>{$project->title}</info>");
        $this->stdErr->writeln("Project ID: <info>{$project->id}</info>");
        $this->stdErr->writeln("Project dashboard: <info>$projectUri</info>\n");

        // Show the environments.
        $this->subCommandRunner->run('environments', [
            '--refresh' => 0,
            '--project' => $project->id,
        ]);
        $executable = $this->config->get('application.executable');
        $this->stdErr->writeln("\nYou can list other projects by running <info>$executable projects</info>\n");
    }

    /**
     * Warn the user if a project is suspended.
     *
     * @param \Platformsh\Client\Model\Project $project
     */
    private function warnIfSuspended(Project $project)
    {
        if ($project->isSuspended()) {
            $messages = [];
            $messages[] = '<comment>This project is suspended.</comment>';
            if ($project->owner === $this->api->getMyAccount()['id']) {
                $messages[] = '<comment>Update your payment details to re-activate it: '
                    . $this->config->get('service.accounts_url')
                    . '</comment>';
            }
            $messages[] = '';
            $this->stdErr->writeln($messages);
        }
    }

    /**
     * Display welcome when the user is in a cloud container environment.
     */
    private function welcomeOnContainer()
    {
        $envPrefix = $this->config->get('service.env_prefix');
        $executable = $this->config->get('application.executable');

        $projectId = getenv($envPrefix . 'PROJECT');
        $environmentId = getenv($envPrefix . 'BRANCH');
        $appName = getenv($envPrefix . 'APPLICATION_NAME');

        $project = false;
        $environment = false;
        if ($this->api->isLoggedIn()) {
            $project = $this->api->getProject($projectId);
            if ($project && $environmentId) {
                $environment = $this->api->getEnvironment($environmentId, $project);
            }
        }

        if ($project) {
            $this->stdErr->writeln('Project: ' . $this->api->getProjectLabel($project));
            if ($environment) {
                $this->stdErr->writeln('Environment: ' . $this->api->getEnvironmentLabel($environment));
            }
            if ($appName) {
                $this->stdErr->writeln('Application name: <info>' . $appName . '</info>');
            }

            $this->warnIfSuspended($project);
        } else {
            $this->stdErr->writeln('Project ID: <info>' . $projectId . '</info>');
            if ($environmentId) {
                $this->stdErr->writeln('Environment ID: <info>' . $environmentId . '</info>');
            }
            if ($appName) {
                $this->stdErr->writeln('Application name: <info>' . $appName . '</info>');
            }
        }

        $this->stdErr->writeln('');
        $examples = [];
        if (getenv($envPrefix . 'APPLICATION')) {
            $examples[] = "To view application config, run: <info>$executable app:config</info>";
        }
        if (getenv($envPrefix . 'RELATIONSHIPS')) {
            $examples[] = "To view relationships, run: <info>$executable relationships</info>";
        }
        if (getenv($envPrefix . 'ROUTES')) {
            $examples[] = "To view routes, run: <info>$executable routes</info>";
        }
        if (getenv($envPrefix . 'VARIABLES')) {
            $examples[] = "To view variables, run: <info>$executable decode \${$envPrefix}VARIABLES</info>";
        }
        if (!empty($examples)) {
            $this->stdErr->writeln('Local environment commands:');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(preg_replace('/^/m', '  ', implode("\n", $examples)));
            $this->stdErr->writeln('');
        }
    }
}
