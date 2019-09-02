<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Identifier;
use Platformsh\Cli\Service\Shell;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;

class MultiCommand extends CommandBase implements CompletionAwareInterface
{
    protected static $defaultName = 'multi';

    private $api;
    private $config;
    private $identifier;
    private $shell;

    public function __construct(
        Api $api,
        Config $config,
        Identifier $identifier,
        Shell $shell
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->identifier = $identifier;
        $this->shell = $shell;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Execute a command on multiple projects')
            ->addArgument('cmd', InputArgument::REQUIRED, 'The command to execute')
            ->addOption('projects', 'p', InputOption::VALUE_REQUIRED, 'A list of project IDs, separated by commas and/or whitespace')
            ->addOption('continue', null, InputOption::VALUE_NONE, 'Continue running commands even if an exception is encountered')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property by which to sort the list of project options', 'title')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Reverse the order of project options');
        $this->addExample(
            'List variables on the "master" environment for multiple projects',
            "--projects l7ywemwizmmgb,o43m25zns6k2d,3nyujoslhydhx 'variable:get --environment master'"
        );
    }

    /**
     * {@inheritdoc}
     */
    public function canBeRunMultipleTimes(): bool
    {
        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $commandLine = $input->getArgument('cmd');
        $commandArgs = explode(' ', $commandLine);
        $commandName = reset($commandArgs);
        if (!$commandName) {
            throw new InvalidArgumentException('Invalid command: ' . $commandLine);
        }
        /** @var \Platformsh\Cli\Application $application */
        $application = $this->getApplication();
        $command = $application->find($commandName);
        if (!$command instanceof MultiAwareInterface || !$command->canBeRunMultipleTimes()) {
            $this->stdErr->writeln(sprintf(
                'The command <error>%s</error> cannot be run via "%s multi".',
                $commandName,
                $this->config->get('application.executable')
            ));
            return 1;
        } elseif (!$command->getDefinition()->hasOption('project')) {
            $this->stdErr->writeln(sprintf(
                'The command <error>%s</error> does not have a --project option.',
                $commandName
            ));
            return 1;
        }

        $projects = $this->getSelectedProjects($input);
        if ($projects === false) {
            return 1;
        }

        $success = true;
        $continue = $input->getOption('continue');
        $this->stdErr->writeln(sprintf(
            "Running command '%s' on %d %s.",
            $commandLine,
            count($projects),
            count($projects) === 1 ? 'project' : 'projects'
        ));
        foreach ($projects as $project) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<options=reverse>*</> Project: ' . $this->api->getProjectLabel($project, false));
            try {
                $application->setCurrentCommand($command);
                $commandInput = new StringInput($commandLine . ' --project ' . escapeshellarg($project->id));
                if ($command instanceof MultiAwareInterface) {
                    $command->setRunningViaMulti(true);
                }
                $returnCode = $command->run($commandInput, $output);
                $application->setCurrentCommand($this);
                if ($returnCode !== 0) {
                    $success = false;
                }
            } catch (\Exception $e) {
                if (!$continue) {
                    throw $e;
                }
                $this->getApplication()->renderException($e, $this->stdErr);
                $success = false;
            }
        }

        return $success ? 0 : 1;
    }

    /**
     * Show a checklist using the dialog utility.
     *
     * @param string $text
     * @param array  $options
     *
     * @return array
     */
    protected function showDialogChecklist(array $options, $text = 'Choose item(s)')
    {
        $width = 80;
        $height = 20;
        $listHeight = 20;
        $command = sprintf(
            'dialog --separate-output --checklist %s %d %d %d',
            escapeshellarg($text),
            $height,
            $width,
            $listHeight
        );
        foreach ($options as $tag => $option) {
            $command .= sprintf(' %s %s off', escapeshellarg($tag), escapeshellarg($option));
        }

        $dialogRc = file_get_contents(CLI_ROOT . '/resources/console/dialogrc');
        $dialogRcFile = $this->config->getWritableUserDir() . '/dialogrc';
        if ($dialogRc !== false && (file_exists($dialogRcFile) || file_put_contents($dialogRcFile, $dialogRc))) {
            putenv('DIALOGRC=' . $dialogRcFile);
        }

        $pipes = [2 => null];
        $process = proc_open($command, [
            2 => array('pipe', 'w'),
        ], $pipes);

        // Wait for and read result.
        $result = array_filter(explode("\n", trim(stream_get_contents($pipes[2]))));

        // Close handles.
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        proc_close($process);

        $this->stdErr->writeln('');

        return $result;
    }

    /**
     * Get a list of the user's projects, sorted according to the input.
     *
     * @param InputInterface $input
     *
     * @return \Platformsh\Client\Model\Project[]
     */
    protected function getAllProjects(InputInterface $input)
    {
        $projects = $this->api->getProjects();
        if ($input->getOption('sort')) {
            $this->api->sortResources($projects, $input->getOption('sort'));
        }
        if ($input->getOption('reverse')) {
            $projects = array_reverse($projects, true);
        }

        return $projects;
    }

    /**
     * Get the projects selected by the user.
     *
     * Projects can be specified via the command-line option --projects (as a
     * list of project IDs) or, if possible, the user will be prompted with a
     * checklist via the 'dialog' utility.
     *
     * @param InputInterface $input
     *
     * @return \Platformsh\Client\Model\Project[]|false
     *   An array of projects, or false on error.
     */
    protected function getSelectedProjects(InputInterface $input)
    {
        $projectList = $input->getOption('projects');

        if (!empty($projectList)) {
            $missing = [];
            $selected = [];
            foreach ($this->splitProjectList($projectList) as $projectId) {
                try {
                    $result = $this->identifier->identify($projectId);
                } catch (InvalidArgumentException $e) {
                    $missing[] = $projectId;
                    continue;
                }
                $project = $this->api->getProject($result['projectId'], $result['host']);
                if ($project !== false) {
                    $selected[$project->id] = $project;
                } else {
                    $missing[] = $projectId;
                }
            }
            if (!empty($missing)) {
                $this->stdErr->writeln(sprintf('Project ID(s) not found: <error>%s</error>', implode(', ', $missing)));
                return false;
            }

            return $selected;
        }

        if (!$input->isInteractive()) {
            $this->stdErr->writeln('In non-interactive mode, the --projects option must be specified.');
            return false;
        }

        if (!$this->shell->commandExists('dialog')) {
            $this->stdErr->writeln('The "dialog" utility is required for interactive use.');
            $this->stdErr->writeln('You can specify projects via the --projects option.');
            return false;
        }

        $projects = $this->getAllProjects($input);
        $projectOptions = [];
        foreach ($projects as $project) {
            $projectOptions[$project->id] = $project->title ?: $project->id;
        }

        $projectIds = $this->showDialogChecklist($projectOptions, 'Choose one or more projects');
        if (empty($projectIds)) {
            return false;
        }
        $selected = array_intersect_key($projects, array_flip($projectIds));
        $this->stdErr->writeln('Selected project(s): ' . implode(',', array_keys($selected)));
        $this->stdErr->writeln('');

        return $selected;
    }

    /**
     * Split a list of project IDs.
     *
     * @param string $list
     *
     * @return string[]
     */
    private function splitProjectList($list)
    {
        return array_filter(array_unique(preg_split('/[,\s]+/', $list) ?: []));
    }

    /**
     * {@inheritdoc}
     */
    public function completeOptionValues($optionName, CompletionContext $context)
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        if ($argumentName === 'cmd') {
            $commandNames = [];
            foreach ($this->getApplication()->all() as $command) {
                if ($command instanceof MultiAwareInterface
                    && $command->canBeRunMultipleTimes()
                    && $command->getDefinition()->hasOption('project')) {
                    $commandNames[] = $command->getName();
                }
            }

            return $commandNames;
        }

        return [];
    }
}
