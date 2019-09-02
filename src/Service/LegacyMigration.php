<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LegacyMigration
{
    private $config;
    private $localProject;
    private $input;
    private $subCommandRunner;
    private $questionHelper;
    private $stdErr;

    public function __construct(
        Config $config,
        InputInterface $input,
        OutputInterface $output,
        LocalProject $localProject,
        QuestionHelper $questionHelper,
        SubCommandRunner $subCommandRunner
    ) {
        $this->config = $config;
        $this->input = $input;
        $this->localProject = $localProject;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->subCommandRunner = $subCommandRunner;
        $this->questionHelper = $questionHelper;
    }

    /**
     * Prompt the user to migrate from the legacy project file structure.
     *
     * If the input is interactive, the user will be asked to migrate up to once
     * per hour. The time they were last asked will be stored in the project
     * configuration. If the input is not interactive, the user will be warned
     * (on every command run) that they should run the 'legacy-migrate' command.
     */
    public function check()
    {
        static $asked = false;
        if (!$this->localProject->getLegacyProjectRoot()) {
            $asked = true;
            return;
        }
        if ($asked) {
            return;
        }
        $asked = true;

        $projectRoot = $this->localProject->getProjectRoot();
        $timestamp = time();
        $promptMigrate = true;
        if ($projectRoot) {
            $projectConfig = $this->localProject->getProjectConfig($projectRoot);
            if (isset($projectConfig['migrate']['3.x']['last_asked'])
                && $projectConfig['migrate']['3.x']['last_asked'] > $timestamp - 3600) {
                $promptMigrate = false;
            }
        }

        $this->stdErr->writeln(sprintf(
            'You are in a project using an old file structure, from previous versions of the %s.',
            $this->config->get('application.name')
        ));
        if ($this->input->isInteractive() && $promptMigrate) {
            if ($projectRoot && isset($projectConfig)) {
                $projectConfig['migrate']['3.x']['last_asked'] = $timestamp;
                /** @noinspection PhpUnhandledExceptionInspection */
                $this->localProject->writeCurrentProjectConfig($projectConfig, $projectRoot);
            }
            if ($this->questionHelper->confirm('Migrate to the new structure?')) {
                $code = $this->subCommandRunner->run('legacy-migrate');
                exit($code);
            }
        } else {
            $this->stdErr->writeln(sprintf(
                'Fix this with: <comment>%s legacy-migrate</comment>',
                $this->config->get('application.executable')
            ));
        }
        $this->stdErr->writeln('');
    }
}