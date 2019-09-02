<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local\BuildFlavor;

use Platformsh\Cli\Exception\DependencyMissingException;
use Symfony\Component\Console\Output\OutputInterface;

class NodeJs extends BuildFlavorBase
{
    public function getStacks()
    {
        return ['nodejs'];
    }

    public function build()
    {
        $buildDir = $this->copyToBuildDir();

        if (file_exists($buildDir . '/package.json')) {
            $this->stdErr->writeln("Found a package.json file, installing dependencies");

            if (!$this->shellHelper->commandExists('npm')) {
                throw new DependencyMissingException('npm is not installed');
            }

            $npm = $this->shellHelper->resolveCommand('npm');
            $npmArgs = [$npm];
            if ($this->stdErr->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $npmArgs[] = '--loglevel=verbose';
            }

            $pruneArgs = $npmArgs;
            $pruneArgs[] = 'prune';
            $this->shellHelper->execute($pruneArgs, $buildDir, true, false);

            $installArgs = $npmArgs;
            $installArgs[] = 'install';
            $this->shellHelper->execute($installArgs, $buildDir, true, false);
        }

        $this->processSpecialDestinations();
    }

    public function install()
    {
        parent::install();
        $this->copyGitIgnore('gitignore-nodejs');
    }
}
