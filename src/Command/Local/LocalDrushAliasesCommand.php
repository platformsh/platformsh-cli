<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Local;

use Cocur\Slugify\Slugify;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Drush;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\HostFactory;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\RemoteEnvVars;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class LocalDrushAliasesCommand extends CommandBase
{
    protected static $defaultName = 'local:drush-aliases';

    private $api;
    private $drush;
    private $filesystem;
    private $hostFactory;
    private $localProject;
    private $remoteEnvVars;
    private $selector;
    private $questionHelper;

    public function __construct(
        Api $api,
        Drush $drush,
        Filesystem $filesystem,
        HostFactory $hostFactory,
        LocalProject $localProject,
        RemoteEnvVars $remoteEnvVars,
        Selector $selector,
        QuestionHelper $questionHelper
    ) {
        $this->api = $api;
        $this->drush = $drush;
        $this->filesystem = $filesystem;
        $this->hostFactory = $hostFactory;
        $this->localProject = $localProject;
        $this->remoteEnvVars = $remoteEnvVars;
        $this->selector = $selector;
        $this->questionHelper = $questionHelper;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['drush-aliases'])
            ->addOption('recreate', 'r', InputOption::VALUE_NONE, 'Recreate the aliases.')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Recreate the aliases with a new group name.')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the current group name (do nothing else).')
            ->setDescription('Find the project\'s Drush aliases');
        $this->addExample('Change the alias group to @example', '-g example');
    }

    public function isHidden()
    {
        // Hide this command in the list if the project is not Drupal.
        $projectRoot = $this->localProject->getProjectRoot();
        if ($projectRoot && !Drupal::isDrupal($projectRoot)) {
            return true;
        }

        return parent::isHidden();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->localProject->getProjectRoot();
        $project = $this->selector->getCurrentProject();
        if (!$projectRoot || !$project) {
            throw new RootNotFoundException();
        }

        $drush = $this->drush;

        $apps = $drush->getDrupalApps($projectRoot);
        if (empty($apps)) {
            $this->stdErr->writeln('No Drupal applications found.');

            return 1;
        }

        $current_group = $drush->getAliasGroup($project, $projectRoot);

        if ($input->getOption('pipe')) {
            $output->writeln($current_group);

            return 0;
        }

        if ($drush->getVersion() === false) {
            $this->stdErr->writeln('Drush is not installed, or the Drush version could not be determined.');
            return 1;
        }

        if ($input->isInteractive()) {
            $this->migrateAliasFiles($drush);
        }

        $aliases = $drush->getAliases($current_group);
        $new_group = ltrim($input->getOption('group'), '@');
        if (empty($aliases) && !$new_group && $project && $current_group === $project->id) {
            $new_group = (new Slugify())->slugify($project->title);
        }

        if (($new_group && $new_group != $current_group) || empty($aliases) || $input->getOption('recreate')) {
            $new_group = $new_group ?: $current_group;

            $this->stdErr->writeln("Creating Drush aliases in the group <info>@$new_group</info>");

            if ($new_group !== $current_group) {
                $existing = $drush->getAliases($new_group);
                if (!empty($existing)) {
                    $question = "The Drush alias group <info>@$new_group</info> already exists. Overwrite?";
                    if (!$this->questionHelper->confirm($question, false)) {
                        return 1;
                    }
                }
                $drush->setAliasGroup($new_group, $projectRoot);
            }

            $environments = $this->api->getEnvironments($project, true, false);

            // Attempt to find the absolute application root directory for
            // each Enterprise environment. This will be cached by the Drush
            // service ($drush), for use while generating aliases.
            foreach ($environments as $environment) {

                // Cache the environment's deployment information.
                // This will at least be used for \Platformsh\Cli\Service\Drush::getSiteUrl().
                if (!$this->api->hasCachedCurrentDeployment($environment) && $environment->isActive()) {
                    $this->debug('Fetching deployment information for environment: ' . $environment->id);
                    $this->api->getCurrentDeployment($environment);
                }

                if ($environment->deployment_target === 'local') {
                    continue;
                }
                foreach ($apps as $app) {
                    $sshUrl = $environment->getSshUrl($app->getName());
                    if (empty($sshUrl)) {
                        continue;
                    }
                    try {
                        $appRoot = $this->remoteEnvVars->getEnvVar('APP_DIR', $this->hostFactory->remote($sshUrl));
                    } catch (\Symfony\Component\Process\Exception\RuntimeException $e) {
                        $this->stdErr->writeln(sprintf(
                            'Unable to find app root for environment %s, app %s',
                            $this->api->getEnvironmentLabel($environment, 'comment'),
                            '<comment>' . $app->getName() . '</comment>'
                        ));
                        $this->stdErr->writeln($e->getMessage());
                        continue;
                    }
                    if (!empty($appRoot)) {
                        $this->debug(sprintf('App root for %s: %s', $sshUrl, $appRoot));
                        $drush->setCachedAppRoot($sshUrl, $appRoot);
                    }
                }
            }

            $drush->createAliases($project, $projectRoot, $environments, $current_group);

            $this->ensureDrushConfig($drush);

            if ($new_group !== $current_group && !empty($aliases)) {
                if ($this->questionHelper->confirm("Delete old Drush alias group <info>@$current_group</info>?")) {
                    $drush->deleteOldAliases($current_group);
                }
            }

            // Clear the Drush cache now that the aliases have been updated.
            $drush->clearCache();

            // Read the new aliases.
            $aliases = $drush->getAliases($new_group, true);
        }

        if (!empty($aliases)) {
            $this->stdErr->writeln('Drush aliases for ' . $this->api->getProjectLabel($project) . ':');
            foreach (array_keys($aliases) as $name) {
                $output->writeln('    @' . ltrim($name, '@'));
            }
        }

        return 0;
    }

    /**
     * Ensure that the .drush/drush.yml file has the right config.
     *
     * @param \Platformsh\Cli\Service\Drush $drush
     */
    protected function ensureDrushConfig(Drush $drush)
    {
        if (!is_dir($drush->getSiteAliasDir())) {
            return;
        }

        $drushYml = $drush->getDrushDir() . '/drush.yml';
        $drushConfig = [];
        if (file_exists($drushYml)) {
            $drushConfig = (array) Yaml::parse(file_get_contents($drushYml));
        }
        $aliasPath = $drush->getSiteAliasDir();
        if (getenv('HOME')) {
            $aliasPath = str_replace(getenv('HOME') . '/', '${env.home}/', $aliasPath);
        }
        if (empty($drushConfig['drush']['paths']['alias-path'])
            || !in_array($aliasPath, $drushConfig['drush']['paths']['alias-path'], true)) {
            if (file_exists($drushYml)) {
                $this->stdErr->writeln('Writing to <info>~/.drush/drush.yml</info> file to configure the global alias-path');
            } else {
                $this->stdErr->writeln('Creating <info>~/.drush/drush.yml</info> file to configure the global alias-path');
            }

            $drushConfig['drush']['paths']['alias-path'][] = $aliasPath;

            $this->filesystem->writeFile($drushYml, Yaml::dump($drushConfig, 5));
        }
    }

    /**
     * Migrate old alias file(s) from ~/.drush to ~/.drush/site-aliases.
     *
     * @param \Platformsh\Cli\Service\Drush $drush
     */
    protected function migrateAliasFiles(Drush $drush)
    {
        $newDrushDir = $drush->getHomeDir() . '/.drush/site-aliases';
        $oldFilenames = $drush->getLegacyAliasFiles();
        if (empty($oldFilenames)) {
            return;
        }

        $newDrushDirRelative = str_replace($drush->getHomeDir() . '/', '~/', $newDrushDir);
        $confirmText = "Do you want to move your global Drush alias files from <comment>~/.drush</comment> to <comment>$newDrushDirRelative</comment>?";
        if (!$this->questionHelper->confirm($confirmText)) {
            return;
        }

        if (!file_exists($newDrushDir) && !mkdir($newDrushDir, 0755, true)) {
            $this->stdErr->writeln(sprintf('Failed to create directory: <error>%s</error>', $newDrushDir));
            $this->stdErr->writeln('The alias files have not been moved.');
            return;
        }

        $success = true;
        foreach ($oldFilenames as $oldFilename) {
            $newFilename = $newDrushDir . '/' . basename($oldFilename);
            if (file_exists($newFilename)) {
                $this->stdErr->writeln("Failed to move file <error>$oldFilename</error>, because the destination file already exists.");
                $success = false;
            } elseif (!rename($oldFilename, $newFilename)) {
                $this->stdErr->writeln("Failed to move file <error>$oldFilename</error> to <error>$newFilename</error>");
                $success = false;
            }
        }

        if ($success) {
            $this->stdErr->writeln(sprintf('Global Drush alias files have been successfully moved to <info>%s</info>.', $newDrushDirRelative));
        }
    }
}
