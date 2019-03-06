<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Util\NestedArrayUtil;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentInfoCommand extends CommandBase
{
    /** @var \Platformsh\Cli\Service\PropertyFormatter|null */
    protected $formatter;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:info')
            ->addArgument('property', InputArgument::OPTIONAL, 'The name of the property')
            ->addArgument('value', InputArgument::OPTIONAL, 'Set a new value for the property')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache')
            ->setDescription('Read or set properties for an environment');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
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
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();
        if ($input->getOption('refresh')) {
            $environment->refresh();
        }

        $property = $input->getArgument('property');

        $this->formatter = $this->getService('property_formatter');

        if (!$property) {
            return $this->listProperties($environment);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $environment, !$this->shouldWait($input));
        }

        switch ($property) {
            case 'url':
                $value = $environment->getUri(true);
                break;

            default:
                $value = $this->api()->getNestedProperty($environment, $property);
        }

        $output->writeln($this->formatter->format($value, $property));

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
        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $table->renderSimple($values, $headings);

        return 0;
    }

    /**
     * @param string      $property
     * @param string      $value
     * @param Environment $environment
     * @param bool        $noWait
     *
     * @return int
     */
    protected function setProperty($property, $value, Environment $environment, $noWait)
    {
        if (!$this->validateValue($property, $value)) {
            return 1;
        }
        $type = $this->getType($property);
        if ($type === 'boolean' && $value === 'false') {
            $value = false;
        }
        settype($value, $type);
        $currentValue = $this->api()->getNestedProperty($environment, $property, false);
        if ($currentValue === $value) {
            $this->stdErr->writeln(sprintf(
                'Property <info>%s</info> already set as: %s',
                $property,
                $this->formatter->format($currentValue, $property)
            ));

            return 0;
        }
        if (strpos($property, '.') !== false) {
            $parents = explode('.', $property);
            $propertyName = array_shift($parents);
            $parentValue = $environment->getProperty($propertyName, true, false);
            NestedArrayUtil::setNestedArrayValue($parentValue, $parents, $value);
            $patch = [$propertyName => $parentValue];
        } else {
            $patch = [$property => $value];
        }
        $result = $environment->update($patch);
        $this->stdErr->writeln(sprintf(
            'Property <info>%s</info> set to: %s',
            $property,
            $this->formatter->format($this->api()->getNestedProperty($environment, $property, false), $property)
        ));

        $this->api()->clearEnvironmentsCache($environment->project);

        $rebuildProperties = ['enable_smtp', 'restrict_robots'];
        $success = true;
        if ($result->countActivities() && !$noWait) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        } elseif (!$result->countActivities() && in_array($property, $rebuildProperties)) {
            $this->redeployWarning();
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
            'backups.manual_count' => 'int',
        ];

        return isset($writableProperties[$property]) ? $writableProperties[$property] : false;
    }

    /**
     * @param string          $property
     * @param string          $value
     *
     * @return bool
     */
    protected function validateValue($property, $value)
    {
        $type = $this->getType($property);
        if (!$type) {
            $this->stdErr->writeln("Property not writable: <error>$property</error>");

            return false;
        }
        $valid = true;
        $message = '';
        // @todo find out exactly how these should best be validated
        $selectedEnvironment = $this->getSelectedEnvironment();
        switch ($property) {
            case 'parent':
                if ($selectedEnvironment->id === 'master') {
                    $message = "The master environment cannot have a parent";
                    $valid = false;
                } elseif ($value === $selectedEnvironment->id) {
                    $message = "An environment cannot be the parent of itself";
                    $valid = false;
                } elseif (!$parentEnvironment = $this->api()->getEnvironment($value, $this->getSelectedProject())) {
                    $message = "Environment not found: <error>$value</error>";
                    $valid = false;
                } elseif ($parentEnvironment->parent === $selectedEnvironment->id) {
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
