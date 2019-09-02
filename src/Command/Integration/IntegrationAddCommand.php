<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\IntegrationService;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Client\Model\Integration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationAddCommand extends CommandBase
{
    protected static $defaultName = 'integration:add';

    private $activityService;
    private $integrationService;
    private $questionHelper;
    private $selector;

    public function __construct(
        ActivityService $activityService,
        IntegrationService $integration,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->activityService = $activityService;
        $this->integrationService = $integration;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Add an integration to the project');

        $definition = $this->getDefinition();
        $this->integrationService->getForm()->configureInputDefinition($definition);
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);

        $this->addExample(
            'Add an integration with a GitHub repository',
            '--type github --repository myuser/example-repo --token 9218376e14c2797e0d06e8d2f918d45f --fetch-branches 0'
        );
        $this->addExample(
            'Add an integration with a GitLab repository',
            '--type gitlab --repository mygroup/example-repo --token 22fe4d70dfbc20e4f668568a0b5422e2 --base-url https://gitlab.example.com'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $values = $this->integrationService->getForm()
                       ->resolveOptions($input, $this->stdErr, $this->questionHelper);

        // Validate credentials for new Bitbucket integrations.
        if (isset($values['type']) && $values['type'] === 'bitbucket' && isset($values['app_credentials'])) {
            $result = $this->integrationService->validateBitbucketCredentials($values['app_credentials']);
            if ($result !== true) {
                $this->stdErr->writeln($result);

                return 1;
            }
        }

        // Omit all empty, non-required fields when creating a new integration.
        foreach ($this->integrationService->getForm()->getFields() as $name => $field) {
            if (isset($values[$name]) && !$field->isRequired() && $field->isEmpty($values[$name])) {
                unset($values[$name]);
            }
        }

        $values = $this->integrationService->postProcessValues($values);

        // Confirm this action for Git source integrations.
        if (isset($values['type']) && in_array($values['type'], ['github', 'gitlab', 'bitbucket', 'bitbucket_server'])) {
            $this->stdErr->writeln(
                "<comment>Warning:</comment> adding a '" . $values['type'] . "' integration will automatically synchronize code from the external Git repository."
                . "\nThis means it can overwrite all the code in your project.\n"
            );
            if (!$this->questionHelper->confirm('Are you sure you want to continue?', false)) {
                return 1;
            }
        }

        try {
            $result = $selection->getProject()
                ->addIntegration($values['type'], $values);
        } catch (BadResponseException $e) {
            if ($errors = Integration::listValidationErrors($e)) {
                $this->stdErr->writeln('<error>The integration is invalid.</error>');
                $this->stdErr->writeln('');
                $this->integrationService->listValidationErrors($errors, $output);

                return 4;
            }

            throw $e;
        }

        /** @var \Platformsh\Client\Model\Integration $integration */
        /** @noinspection PhpUnhandledExceptionInspection */
        $integration = $result->getEntity();

        $this->integrationService->ensureHooks($integration, $selection->getProject());

        $this->stdErr->writeln("Created integration <info>$integration->id</info> (type: {$values['type']})");

        $success = true;
        if ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitMultiple($result->getActivities(), $selection->getProject());
        }

        $this->integrationService->displayIntegration($integration);

        return $success ? 0 : 1;
    }
}
