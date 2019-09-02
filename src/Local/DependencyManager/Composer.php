<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local\DependencyManager;

class Composer extends DependencyManagerBase
{
    protected $command = 'composer';

    /**
     * {@inheritdoc}
     */
    public function getInstallHelp()
    {
        return 'See https://getcomposer.org/ for installation instructions.';
    }

    /**
     * {@inheritdoc}
     */
    public function getBinPaths($prefix)
    {
        return [$prefix . '/vendor/bin'];
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
        $composerJson = $path . '/composer.json';
        $contents = file_exists($composerJson) ? file_get_contents($composerJson) : null;
        $newContents = json_encode(['require' => $dependencies]);
        if ($contents !== $newContents) {
            file_put_contents($composerJson, $newContents);
            if (file_exists($path . '/composer.lock')) {
                unlink($path . '/composer.lock');
            }
        }

        $this->runCommand('composer install --no-progress --prefer-dist --optimize-autoloader --no-interaction', $path);
    }

    /**
     * Install dependencies globally.
     *
     * @param array $dependencies
     */
    private function installGlobal(array $dependencies)
    {
        $requirements = [];
        foreach ($dependencies as $package => $version) {
            $requirements[] = $version === '*' ? $package : $package . ':' . $version;
        }
        $this->runCommand(
            'composer global require '
            . '--no-progress --prefer-dist --optimize-autoloader --no-interaction '
            . implode(' ', array_map('escapeshellarg', $requirements))
        );
    }
}
