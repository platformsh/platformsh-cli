<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local\DependencyManager;

class Pip extends DependencyManagerBase
{
    protected $command = 'pip';

    /**
     * {@inheritdoc}
     */
    public function getInstallHelp()
    {
        return 'See https://pip.pypa.io/en/stable/installing/ for installation instructions.';
    }

    /**
     * {@inheritdoc}
     */
    public function getBinPaths($prefix)
    {
        return [$prefix . '/bin'];
    }

    /**
     * {@inheritdoc}
     */
    public function install($path, array $dependencies, $global = false)
    {
        file_put_contents($path . '/requirements.txt', $this->formatRequirementsTxt($dependencies));
        $command = 'pip install --requirement=requirements.txt';
        if (!$global) {
            $command .= ' --prefix=.';
        }
        $this->runCommand($command, $path);
    }

    /**
     * {@inheritdoc}
     */
    public function getEnvVars($path)
    {
        $envVars = [];

        // The PYTHONPATH needs to be set as something like
        // "lib/python2.7/site-packages". So here we are scanning "lib" to find
        // the correct subdirectory.
        if (file_exists($path . '/lib')) {
            $subdirectories = scandir($path . '/lib') ?: [];
            foreach ($subdirectories as $subdirectory) {
                if (strpos($subdirectory, '.') !== 0) {
                    $envVars['PYTHONPATH'] = $path . '/lib/' . $subdirectory . '/site-packages';
                    break;
                }
            }
        }

        return $envVars;
    }

    /**
     * @param array $dependencies
     *
     * @return string
     */
    private function formatRequirementsTxt(array $dependencies)
    {
        $lines = [];
        foreach ($dependencies as $package => $version) {
            if (in_array($version[0], ['<', '!', '>', '='])) {
                $lines[] = sprintf('%s%s', $package, $version);
            } elseif ($version === '*') {
                $lines[] = $package;
            } else {
                $lines[] = sprintf('%s==%s', $package, $version);
            }
        }

        return implode("\n", $lines);
    }
}
