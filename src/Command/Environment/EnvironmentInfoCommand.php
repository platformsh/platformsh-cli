<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentInfoCommand extends CommandBase
{
    protected static $defaultName = 'environment:info';

    private $activityService;
    private $api;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        PropertyFormatter $formatter,
        Selector $selector,
        Table $table
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('property', InputArgument::OPTIONAL, 'The name of the property')
            ->addArgument('value', InputArgument::OPTIONAL, 'Set a new value for the property')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache')
            ->setDescription('Read or set properties for an environment');

        $definition = $this->getDefinition();
        $this->formatter->configureInput($definition);
        $this->table->configureInput($definition);
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);

        $this->addExample('Read all environment properties')
             ->addExample("Show the environment's status", 'status')
             ->addExample('Show the date the environment was created', 'created_at')
             ->addExample('Enable email sending', 'enable_smtp true')
             ->addExample('Change the environment title', 'title "New feature"')
             ->addExample("Change the environment's parent branch", 'parent sprint-2');
        $this->setHiddenAliases(['environment:metadata']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $environment = $selection->getEnvironment();
        if ($input->getOption('refresh')) {
            $environment->refresh();
        }

        $property = $input->getArgument('property');

        if (!$property) {
            return $this->listProperties($environment);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $environment, $selection->getProject(), $this->activityService->shouldWait($input));
        }

        switch ($property) {
            case 'url':
                $value = $environment->getUri(true);
                break;

            default:
                $value = $this->api->getNestedProperty($environment, $property);
        }

        $output->write($this->formatter->format($value, $property));

        return 0;
    }

    /**
     * @param Environment $environment
     *
     * @return int
     */
    protected function listProperties(Environment $environment)
    {
        $headings = [];
        $values = [];
        foreach ($environment->getProperties() as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->formatter->format($value, $key);
        }
        $this->table->renderSimple($values, $headings);

        return 0;
    }

    /**
     * @param string      $property
     * @param string      $value
     * @param Environment $environment
     * @param Project     $project
     * @param bool        $shouldWait
     *
     * @return int
     */
    protected function setProperty($property, $value, Environment $environment, Project $project, $shouldWait)
    {
        if (!$this->validateValue($property, $value, $environment, $project)) {
            return 1;
        }
        $type = $this->getType($property);
        if ($type === 'boolean' && $value === 'false') {
            $value = false;
        }
        settype($value, $type);
        $currentValue = $environment->getProperty($property, false);
        if ($currentValue === $value) {
            $this->stdErr->writeln(sprintf(
                'Property <info>%s</info> already set as: %s',
                $property,
                $this->formatter->format($environment->getProperty($property, false), $property)
            ));

            return 0;
        }
        $result = $environment->update([$property => $value]);
        $this->stdErr->writeln(sprintf(
            'Property <info>%s</info> set to: %s',
            $property,
            $this->formatter->format($environment->$property, $property)
        ));

        $this->api->clearEnvironmentsCache($environment->project);

        $rebuildProperties = ['enable_smtp', 'restrict_robots'];
        $success = true;
        if ($result->countActivities() && $shouldWait) {
            $success = $this->activityService->waitMultiple($result->getActivities(), $project);
        } elseif (!$result->countActivities() && in_array($property, $rebuildProperties)) {
            $this->activityService->redeployWarning();
        }

        return $success ? 0 : 1;
    }

    /**
     * Get the type of a writable environment property.
     *
     * @param string $property
     *
     * @return string|false
     */
    protected function getType($property)
    {
        $writableProperties = [
            'enable_smtp' => 'boolean',
            'parent' => 'string',
            'title' => 'string',
            'restrict_robots' => 'boolean',
        ];

        return isset($writableProperties[$property]) ? $writableProperties[$property] : false;
    }

    /**
     * @param string      $property
     * @param string      $value
     * @param Environment $environment
     * @param Project     $project
     *
     * @return bool
     */
    protected function validateValue($property, $value, Environment $environment, Project $project)
    {
        $type = $this->getType($property);
        if (!$type) {
            $this->stdErr->writeln("Property not writable: <error>$property</error>");

            return false;
        }
        $valid = true;
        $message = '';
        switch ($property) {
            case 'parent':
                if ($environment->id === 'master') {
                    $message = "The master environment cannot have a parent";
                    $valid = false;
                } elseif ($value === $environment->id) {
                    $message = "An environment cannot be the parent of itself";
                    $valid = false;
                } elseif (!$parentEnvironment = $this->api->getEnvironment($value, $project)) {
                    $message = "Environment not found: <error>$value</error>";
                    $valid = false;
                } elseif ($parentEnvironment->parent === $environment->id) {
                    $valid = false;
                }
                break;
        }
        switch ($type) {
            case 'boolean':
                $valid = in_array($value, ['1', '0', 'false', 'true']);
                break;
        }
        if (!$valid) {
            if ($message) {
                $this->stdErr->writeln($message);
            } else {
                $this->stdErr->writeln("Invalid value for <error>$property</error>: $value");
            }

            return false;
        }

        return true;
    }
}
