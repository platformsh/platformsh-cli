<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local\BuildFlavor;

use Symfony\Component\Console\Output\OutputInterface;

class Composer extends BuildFlavorBase
{
    public function getStacks()
    {
        return ['php', 'hhvm'];
    }

    public function getKeys()
    {
        return ['composer', 'default'];
    }

    public function build()
    {
        $buildDir = $this->copyToBuildDir();

        // The composer.json file may not exist at this stage, even if the user
        // has manually specified the `composer` build flavor.
        if (file_exists($buildDir . '/composer.json')) {
            $this->stdErr->writeln("Found a composer.json file; installing dependencies");

            $args = [
                $this->shellHelper->resolveCommand('composer'),
                'install',
                '--no-progress',
                '--prefer-dist',
                '--optimize-autoloader',
                '--no-interaction',
            ];
            if ($this->stdErr->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $args[] = '-vvv';
            } elseif ($this->stdErr->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $args[] = '-vv';
            } elseif ($this->stdErr->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $args[] = '-v';
            }
            $this->shellHelper->execute($args, $buildDir, true, false);
        }

        $this->processSpecialDestinations();
    }

    public function install()
    {
        parent::install();
        $this->copyGitIgnore('gitignore-composer');

        if (Drupal::isDrupal($this->getWebRoot())) {
            $this->installDrupalSettingsLocal();
        }
    }
}
