<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local\DependencyManager;

class Npm extends DependencyManagerBase
{
    protected $command = 'npm';
    private $globalList;

    /**
     * {@inheritdoc}
     */
    public function getInstallHelp()
    {
        return 'See https://docs.npmjs.com/getting-started/installing-node for installation instructions.';
    }

    /**
     * {@inheritdoc}
     */
    public function getBinPaths($prefix)
    {
        return [$prefix . '/node_modules/.bin'];
    }

    /**
     * {@inheritdoc}
     */
    public function install($path, array $dependencies, $global = false)
    {
        if ($global) {
            $this->installGlobal($dependencies);
            return;
        }
        $packageJsonFile = $path . '/package.json';
        $packageJson = json_encode([
            'name' => 'temporary-build-dependencies',
            'version' => '1.0.0',
            'private' => true,
            'dependencies' => $dependencies,
        ], JSON_PRETTY_PRINT);
        $current = file_exists($packageJsonFile) ? file_get_contents($packageJsonFile) : false;
        if ($current !== $packageJson) {
            file_put_contents($packageJsonFile, $packageJson);
        }
        $this->runCommand('npm install --global-style', $path);
    }

    /**
     * Install dependencies globally.
     *
     * @param array $dependencies
     */
    private function installGlobal(array $dependencies)
    {
        foreach ($dependencies as $package => $version) {
            if (!$this->isInstalledGlobally($package)) {
                $arg = $version === '*' ? $package : $package . '@' . $version;
                $this->runCommand('npm install --global ' . escapeshellarg($arg));
            }
        }
    }

    /**
     * @param string $package
     *
     * @return bool
     */
    private function isInstalledGlobally($package)
    {
        if (!isset($this->globalList)) {
            $this->globalList = $this->shell->execute(
                ['npm', 'ls', '--global', '--no-progress', '--depth=0']
            );
        }

        return $this->globalList && strpos($this->globalList, $package . '@') !== false;
    }
}
