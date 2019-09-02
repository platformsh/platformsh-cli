<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local\DependencyManager;

class Bundler extends DependencyManagerBase
{
    protected $command = 'bundler';

    /**
     * {@inheritdoc}
     */
    public function getInstallHelp()
    {
        return 'See http://bundler.io/ for installation instructions.';
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
        $gemFile = $path . '/Gemfile';
        $gemFileContent = $this->formatGemfile($dependencies);
        $current = file_exists($gemFile) ? file_get_contents($gemFile) : false;
        if ($current !== $gemFileContent) {
            file_put_contents($gemFile, $gemFileContent);
            if (file_exists($path . '/Gemfile.lock')) {
                unlink('Gemfile.lock');
            }
        }
        if ($global) {
            $this->runCommand('bundle install --system --gemfile=Gemfile', $path);
        } else {
            $this->runCommand('bundle install --path=. --binstubs --gemfile=Gemfile', $path);
        }
    }

    /**
     * @param array $dependencies
     *
     * @return string
     */
    private function formatGemfile(array $dependencies)
    {
        $lines = ["source 'https://rubygems.org'"];
        foreach ($dependencies as $package => $version) {
            if ($version != '*') {
                $lines[] = sprintf("gem '%s', '%s', :require => false", $package, $version);
            } else {
                $lines[] = sprintf("gem '%s', :require => false", $package);
            }
        }

        return implode("\n", $lines);
    }
}
