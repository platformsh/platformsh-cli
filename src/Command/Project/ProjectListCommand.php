<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectListCommand extends CommandBase
{
    protected static $defaultName = 'project:list';

    private $api;
    private $config;
    private $table;

    public function __construct(
        Api $api,
        Config $config,
        Table $table
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['projects', 'pro'])
            ->setDescription('Get a list of all active projects')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a simple list of project IDs')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Filter by region hostname (exact match)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Filter by title (case-insensitive search)')
            ->addOption('my', null, InputOption::VALUE_NONE, 'Display only the projects you own')
            ->addOption('refresh', null, InputOption::VALUE_REQUIRED, 'Whether to refresh the list', 1)
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property to sort by', 'title')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse (descending) order');
        $this->table->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $refresh = $input->hasOption('refresh') && $input->getOption('refresh');

        // Fetch the list of projects.
        $projects = $this->api->getProjects($refresh ? true : null);

        // Filter the list of projects.
        $filters = [];
        if ($host = $input->getOption('host')) {
            $filters['host'] = $host;
        }
        if (($title = $input->getOption('title')) !== null) {
            $filters['title'] = $title;
        }
        if ($input->getOption('my')) {
            $filters['my'] = true;
        }
        $this->filterProjects($projects, $filters);

        // Sort the list of projects.
        if ($input->getOption('sort')) {
            $this->api->sortResources($projects, $input->getOption('sort'));
        }
        if ($input->getOption('reverse')) {
            $projects = array_reverse($projects, true);
        }

        // Display a simple list of project IDs, if --pipe is used.
        if ($input->getOption('pipe')) {
            $output->writeln(array_keys($projects));

            return 0;
        }

        $machineReadable = $this->table->formatIsMachineReadable();

        $header = ['id' => 'ID', 'title' => 'Title', 'url' => 'URL', 'host' => 'Region hostname'];
        $defaultColumns = ['id', 'title', 'host'];

        $rows = [];
        foreach ($projects as $project) {
            $title = $project->title ?: '[Untitled Project]';

            // Add a warning next to the title if the project is suspended.
            if (!$machineReadable && $project->isSuspended()) {
                $title = sprintf(
                    '<fg=white;bg=black>%s</> <fg=yellow;bg=black>(suspended)</>',
                    $title
                );
            }

            $rows[] = [
                'id' => new AdaptiveTableCell($project->id, ['wrap' => false]),
                'title' => $title,
                'url' => $project->getLink('#ui'),
                'host' => parse_url($project->getUri(), PHP_URL_HOST)
            ];
        }

        // Display a simple table (and no messages) if the --format is
        // machine-readable (e.g. csv or tsv).
        if ($machineReadable) {
            $this->table->render($rows, $header, $defaultColumns);

            return 0;
        }

        // Display a message if no projects are found.
        if (empty($projects)) {
            if (!empty($filters)) {
                $filtersUsed = '<comment>--'
                    . implode('</comment>, <comment>--', array_keys($filters))
                    . '</comment>';
                $this->stdErr->writeln('No projects found (filters in use: ' . $filtersUsed . ').');
            } else {
                $this->stdErr->writeln(
                    'You do not have any ' . $this->config->get('service.name') . ' projects yet.'
                );
            }

            return 0;
        }

        // Display the projects.
        if (empty($filters)) {
            $this->stdErr->writeln('Your projects are: ');
        }

        $this->table->render($rows, $header, $defaultColumns);

        $commandName = $this->config->get('application.executable');
        $this->stdErr->writeln([
            '',
            'Get a project by running: <info>' . $commandName . ' get [id]</info>',
            "List a project's environments by running: <info>" . $commandName . ' environments -p [id]</info>',
        ]);

        return 0;
    }

    /**
     * Filter the list of projects.
     *
     * @param Project[]     &$projects
     * @param mixed[string] $filters
     */
    protected function filterProjects(array &$projects, array $filters)
    {
        foreach ($filters as $filter => $value) {
            switch ($filter) {
                case 'host':
                    $projects = array_filter($projects, function (Project $project) use ($value) {
                        return $value === parse_url($project->getUri(), PHP_URL_HOST);
                    });
                    break;

                case 'title':
                    $projects = array_filter($projects, function (Project $project) use ($value) {
                        return stripos($project->title, $value) !== false;
                    });
                    break;

                case 'my':
                    $ownerId = $this->api->getMyAccount()['id'];
                    $projects = array_filter($projects, function (Project $project) use ($ownerId) {
                        return $project->owner === $ownerId;
                    });
                    break;
            }
        }
    }
}
