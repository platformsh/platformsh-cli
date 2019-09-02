<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\IntegrationService;
use Platformsh\Cli\Service\Selector;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Util\NestedArrayUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationUpdateCommand extends CommandBase
{
    protected static $defaultName = 'integration:update';

    private $activityService;
    private $api;
    private $integrationService;
    private $selector;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        IntegrationService $integration,
        Selector $selector
    ) {
        $this->api = $api;
        $this->activityService = $activityService;
        $this->integrationService = $integration;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The ID of the integration to update')
            ->setDescription('Update an integration');

        $definition = $this->getDefinition();
        $form = $this->integrationService->getForm();
        $form->getField('type')->set('includeAsOption', false);
        $this->integrationService->getForm()->configureInputDefinition($definition);
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);

        $this->addExample(
            'Switch on the "fetch branches" option for a specific integration',
            'ZXhhbXBsZSB --fetch-branches 1'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $id = $input->getArgument('id');
        $integration = $project->getIntegration($id);
        if (!$integration) {
            try {
                $integration = $this->api->matchPartialId($id, $project->getIntegrations(), 'Integration');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        $form = $this->integrationService->getForm();
        $newValues = [];
        foreach ($form->getFields() as $key => $field) {
            // Get the values supplied via the command-line options.
            $value = $field->getValueFromInput($input);
            $parents = $field->getValueKeys() ?: [$key];
            if ($value !== null) {
                NestedArrayUtil::setNestedArrayValue($newValues, $parents, $value, true);
            }
        }

        $this->integrationService->postProcessValues($newValues, $integration);

        // Merge current values with new values, accounting for nested arrays.
        foreach ($integration->getProperties() as $key => $currentValue) {
            if (isset($newValues[$key])) {
                // If the new value is an array, it needs to be merged with the
                // old values, e.g. ['foo' => 1, 'bar' => 7] plus ['foo' => 2]
                // will become ['foo' => 2, 'bar' => 7].
                if (is_array($currentValue)) {
                    $newValues[$key] = array_replace_recursive($currentValue, $newValues[$key]);
                }

                // Remove any new values that are the same as the current value.
                if ($this->valuesAreEqual($currentValue, $newValues[$key])) {
                    unset($newValues[$key]);
                }
            }
        }

        if (!$newValues) {
            $this->stdErr->writeln('No changed values were provided to update.');

            $this->stdErr->writeln('');
            $this->integrationService->ensureHooks($integration, $project);

            return 1;
        }

        try {
            $result = $integration->update($newValues);
        } catch (BadResponseException $e) {
            $errors = $integration->listValidationErrors($e);
            if ($errors) {
                $this->stdErr->writeln(sprintf(
                    'The integration <error>%s</error> (type: %s) is invalid.',
                    $integration->id,
                    $integration->type
                ));
                $this->stdErr->writeln('');
                $this->integrationService->listValidationErrors($errors, $output);

                return 4;
            }

            throw $e;
        }

        $this->stdErr->writeln("Integration <info>{$integration->id}</info> (<info>{$integration->type}</info>) updated");
        $this->integrationService->ensureHooks($integration, $project);

        $this->integrationService->displayIntegration($integration);

        if ($this->activityService->shouldWait($input)) {
            $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }

    /**
     * Compare new and old integration values.
     *
     * @param mixed $a
     * @param mixed $b
     *
     * @return bool
     *   True if the values are considered the same, false otherwise.
     */
    private function valuesAreEqual($a, $b)
    {
        if (is_array($a) && is_array($b)) {
            ksort($a);
            ksort($b);
        }

        return $a === $b;
    }
}
