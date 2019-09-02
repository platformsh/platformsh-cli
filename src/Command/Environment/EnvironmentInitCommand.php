<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentInitCommand extends CommandBase
{
    protected static $defaultName = 'environment:init';

    private $api;
    private $activityService;
    private $config;
    private $questionHelper;
    private $selector;

    public function __construct(
        Api $api,
        ActivityService $activityService,
        Config $config,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->api = $api;
        $this->activityService = $activityService;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Initialize an environment from a public Git repository');
        $this->addArgument('url', InputArgument::REQUIRED, 'A URL to a Git repository')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'The name of the profile');

        $definition = $this->getDefinition();
        $this->selector->addEnvironmentOption($definition);
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);

        if ($this->config->get('service.name') === 'Platform.sh') {
            $this->addExample('Initialize using the Platform.sh Go template', 'https://github.com/platformsh/template-golang');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();
        if ($selection->hasEnvironment()) {
            $environment = $selection->getEnvironment();
        } else {
            $environment = $this->api->getEnvironment(
                $this->api->getDefaultEnvironmentId($this->api->getEnvironments($project)),
                $project
            );
            if (!$environment) {
                throw new InvalidArgumentException('No environment selected');
            }
        }

        $url = $input->getArgument('url');
        $profile = $input->getOption('profile') ?: basename($url);

        if (parse_url($url) === false) {
            $this->stdErr->writeln(sprintf('Invalid repository URL: <error>%s</error>', $url));

            return 1;
        }

        if (!$environment->operationAvailable('initialize', true)) {
            $this->stdErr->writeln(sprintf(
                "Operation not available: The environment <error>%s</error> can't be initialized.",
                $environment->id
            ));

            if ($environment->has_code) {
                $this->stdErr->writeln('The environment already contains code.');
            }

            return 1;
        }

        $this->api->clearEnvironmentsCache($project->id);

        // Summarize this action with a message.
        $message = 'Initializing project ';
        $message .= $this->api->getProjectLabel($project);
        if ($environment->id !== 'master') {
            $message .= ', environment ' . $this->api->getEnvironmentLabel($environment);
        }
        if ($input->getOption('profile')) {
            $message .= ' with profile <info>' . $profile . '</info> (' . $url . ')';
        } else {
            $message .= ' with repository <info>' . $url . '</info>.';
        }
        $this->stdErr->writeln($message);

        $activity = $environment->initialize($profile, $url);

        if ($this->activityService->shouldWait($input)) {
            $this->activityService->waitAndLog($activity);
        }

        return 0;
    }
}
