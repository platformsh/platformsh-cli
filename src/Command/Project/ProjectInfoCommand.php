<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectInfoCommand extends CommandBase
{
    protected static $defaultName = 'project:info';

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
            ->setDescription('Read or set properties for a project');

        $definition = $this->getDefinition();
        $this->formatter->configureInput($definition);
        $this->table->configureInput($definition);
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);

        $this->addExample('Read all project properties')
             ->addExample("Show the project's Git URL", 'git')
             ->addExample("Change the project's title", 'title "My project"');
        $this->setHiddenAliases(['project:metadata']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        if ($input->getOption('refresh')) {
            $project->refresh();
        }

        $property = $input->getArgument('property');

        // Setting the pseudo-properties 'git' and 'url', and un-setting the
        // property 'entropy', are done twice in this command so that
        // lazy-loading still works.
        if (!$property) {
            $properties = $project->getProperties();
            $properties['git'] = $project->getGitUrl();
            $properties['url'] = $project->getUri();
            unset($properties['entropy']);

            return $this->listProperties($properties);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $project, !$this->activityService->shouldWait($input));
        }

        switch ($property) {
            case 'git':
                $value = $project->getGitUrl();
                break;

            case 'url':
                $value = $project->getUri();
                break;

            case 'entropy':
                throw new \InvalidArgumentException('Property not found: ' . $property);

            default:
                $value = $this->api->getNestedProperty($project, $property);
        }

        $output->write($this->formatter->format($value, $property));

        return 0;
    }

    /**
     * @param array $properties
     *
     * @return int
     */
    protected function listProperties(array $properties)
    {
        $headings = [];
        $values = [];
        foreach ($properties as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->formatter->format($value, $key);
        }
        $this->table->renderSimple($values, $headings);

        return 0;
    }

    /**
     * @param string  $property
     * @param string  $value
     * @param Project $project
     * @param bool    $noWait
     *
     * @return int
     */
    protected function setProperty($property, $value, Project $project, $noWait)
    {
        $type = $this->getType($property);
        if (!$type) {
            $this->stdErr->writeln("Property not writable: <error>$property</error>");
            return 1;
        }
        if ($type === 'boolean' && $value === 'false') {
            $value = false;
        }
        settype($value, $type);
        $currentValue = $project->getProperty($property);
        if ($currentValue === $value) {
            $this->stdErr->writeln(
                "Property <info>$property</info> already set as: " . $this->formatter->format($value, $property)
            );

            return 0;
        }

        $project->ensureFull();
        $result = $project->update([$property => $value]);
        $this->stdErr->writeln(sprintf(
            'Property <info>%s</info> set to: %s',
            $property,
            $this->formatter->format($value, $property)
        ));

        $this->api->clearProjectsCache();

        $success = true;
        if (!$noWait) {
            $success = $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        return $success ? 0 : 1;
    }

    /**
     * Get the type of a writable property.
     *
     * @param string $property
     *
     * @return string|false
     */
    protected function getType($property)
    {
        $writableProperties = [
            'title' => 'string',
            'description' => 'string',
            'default_domain' => 'string',
        ];

        return isset($writableProperties[$property]) ? $writableProperties[$property] : false;
    }
}
