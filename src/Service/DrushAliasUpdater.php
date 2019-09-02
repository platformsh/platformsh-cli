<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Application;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DrushAliasUpdater
{
    private $application;
    private $drush;
    private $localProject;
    private $stdErr;

    public function __construct(
        Application $application,
        Drush $drush,
        OutputInterface $output,
        LocalProject $localProject
    ) {
        $this->application = $application;
        $this->drush = $drush;
        $this->localProject = $localProject;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * React on the environments list changing, to update Drush aliases.
     *
     * @param \Platformsh\Cli\Event\EnvironmentsChangedEvent $event
     */
    public function onEnvironmentsChanged(EnvironmentsChangedEvent $event)
    {
        $projectRoot = $this->localProject->getProjectRoot();
        if (!$projectRoot) {
            return;
        }
        // Make sure the local:drush-aliases command is enabled.
        if (!$this->application->has('local:drush-aliases')) {
            return;
        }
        // Double-check that the passed project is the current one.
        $projectConfig = $this->localProject->getProjectConfig($projectRoot);
        if (!$projectConfig || empty($projectConfig['id']) || $projectConfig['id'] !== $event->getProject()->id) {
            return;
        }
        // Ignore the project if it doesn't contain a Drupal application.
        if (!Drupal::isDrupal($projectRoot)) {
            return;
        }
        if ($this->drush->getVersion() === false) {
            $this->stdErr->writeln('<options=reverse>DEBUG</> Not updating Drush aliases: the Drush version cannot be determined.', OutputInterface::VERBOSITY_DEBUG);
            return;
        }
        $this->stdErr->writeln('<options=reverse>DEBUG</> Updating Drush aliases', OutputInterface::VERBOSITY_DEBUG);
        try {
            $this->drush->createAliases($event->getProject(), $projectRoot, $event->getEnvironments());
        } catch (\Exception $e) {
            $this->stdErr->writeln(sprintf(
                "<comment>Failed to update Drush aliases:</comment>\n%s\n",
                preg_replace('/^/m', '  ', trim($e->getMessage()))
            ));
        }
    }

}