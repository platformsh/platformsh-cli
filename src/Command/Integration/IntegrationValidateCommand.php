<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\IntegrationService;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationValidateCommand extends CommandBase
{
    public static $defaultName = 'integration:validate';

    private $api;
    private $integrationService;
    private $questionHelper;
    private $selector;

    public function __construct(
        Api $api,
        IntegrationService $integrationService,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->api = $api;
        $this->integrationService = $integrationService;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->setDescription('Validate an existing integration');
        $this->selector->addProjectOption($this->getDefinition());
        $this->setHelp(<<<EOF
This command allows you to check whether an integration is valid.

An exit code of 0 means the integration is valid, while 4 means it is invalid.
Any other exit code indicates an unexpected error.

Integrations are validated automatically on creation and on update. However,
because they involve external resources, it is possible for a valid integration
to become invalid. For example, an access token may be revoked, or an external
repository may be deleted.
EOF
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $id = $input->getArgument('id');
        if (!$id && !$input->isInteractive()) {
            $this->stdErr->writeln('An integration ID is required.');

            return 1;
        } elseif (!$id) {
            $integrations = $project->getIntegrations();
            if (empty($integrations)) {
                $this->stdErr->writeln('No integrations found.');

                return 1;
            }
            $choices = [];
            foreach ($integrations as $integration) {
                $choices[$integration->id] = sprintf('%s (%s)', $integration->id, $integration->type);
            }
            $id = $this->questionHelper->choose($choices, 'Enter a number to choose an integration:');
        }

        $integration = $project->getIntegration($id);
        if (!$integration) {
            try {
                $integration = $this->api->matchPartialId($id, $project->getIntegrations(), 'Integration');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        $this->stdErr->writeln(sprintf(
            'Validating the integration <info>%s</info> (type: %s)...',
            $integration->id,
            $integration->type
        ));

        try {
            $errors = $integration->validate();
        } catch (OperationUnavailableException $e) {
            $this->stdErr->writeln('This integration does not support validation.');

            return 1;
        }
        if (empty($errors)) {
            $this->stdErr->writeln('The integration is valid.');

            return 0;
        }

        $this->stdErr->writeln('');

        $this->integrationService->listValidationErrors($errors, $output);

        // The exit code for an invalid integration (see the command help).
        return 4;
    }
}
