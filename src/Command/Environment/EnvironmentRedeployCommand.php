<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRedeployCommand extends CommandBase
{
    protected static $defaultName = 'environment:redeploy';

    private $api;
    private $activityService;
    private $questionHelper;
    private $selector;

    public function __construct(
        Api $api,
        ActivityService $activityService,
        QuestionHelper $questionHelper,
        Selector $selector
    )
    {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['redeploy'])
            ->setDescription('Redeploy an environment');
        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environment = $this->selector->getSelection($input)->getEnvironment();

        if (!$environment->operationAvailable('redeploy', true)) {
            $this->stdErr->writeln(
                "Operation not available: The environment " . $this->api->getEnvironmentLabel($environment, 'error') . " can't be redeployed."
            );

            if (!$environment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            }

            return 1;
        }

        if (!$this->questionHelper->confirm('Are you sure you want to redeploy the environment ' . $this->api->getEnvironmentLabel($environment, 'comment') . '?')) {
            return 1;
        }

        $activity = $environment->redeploy();

        if ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitAndLog($activity);
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
