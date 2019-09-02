<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Output\OutputInterface;

class DependencyInstaller
{
    protected $output;
    protected $shell;

    public function __construct(OutputInterface $output, Shell $shell)
    {
        $this->output = $output;
        $this->shell = $shell;
    }

    /**
     * Modify the environment to make the installed dependencies available.
     *
     * @param string $destination
     * @param array  $dependencies
     */
    public function putEnv($destination, array $dependencies)
    {
        $env = [];
        $paths = [];
        foreach ($dependencies as $stack => $stackDependencies) {
            $manager = $this->getManager($stack);
            $path = $destination . '/' . $stack;
            $paths = array_merge($paths, $manager->getBinPaths($path));
            $env = array_merge($env, $manager->getEnvVars($path));
        }
        $paths = array_filter(array_map('realpath', $paths));
        if (!empty($paths)) {
            $pathVariable = OsUtil::isWindows() ? 'Path' : 'PATH';
            $env[$pathVariable] = implode(':', $paths);
            if (getenv($pathVariable)) {
                $env[$pathVariable] .= ':' . getenv($pathVariable);
            }
        }
        foreach ($env as $name => $value) {
            putenv($name . '=' . $value);
        }
    }

    /**
     * Install dependencies into a directory.
     *
     * @param string $destination
     * @param array  $dependencies
     * @param bool   $global
     *
     * @throws \Exception If a dependency fails to install.
     *
     * @return bool
     *     False if a dependency manager is not available; otherwise true.
     */
    public function installDependencies($destination, array $dependencies, $global = false)
    {
        $success = true;
        foreach ($dependencies as $stack => $stackDependencies) {
            $manager = $this->getManager($stack);
            $this->output->writeln(sprintf(
                "Installing <info>%s</info> dependencies with '%s': %s",
                $stack,
                $manager->getCommandName(),
                implode(', ', array_keys($stackDependencies))
            ));
            if (!$manager->isAvailable()) {
                $this->output->writeln(sprintf(
                    "Cannot install <comment>%s</comment> dependencies: '%s' is not installed.",
                    $stack,
                    $manager->getCommandName()
                ));
                if ($manager->getInstallHelp()) {
                    $this->output->writeln($manager->getInstallHelp());
                }
                $success = false;
                continue;
            }
            $path = $destination . '/' . $stack;
            $this->ensureDirectory($path);
            $manager->install($path, $stackDependencies, $global);
        }

        return $success;
    }

    /**
     * @param string $path
     */
    protected function ensureDirectory($path)
    {
        if (!is_dir($path) && !mkdir($path, 0755, true)) {
            throw new \RuntimeException('Failed to create directory: ' . $path);
        }
    }

    /**
     * Finds the right dependency manager for a given stack.
     *
     * @param string $name
     *
     * @return \Platformsh\Cli\Local\DependencyManager\DependencyManagerInterface
     */
    protected function getManager($name)
    {
        // Python has 'python', 'python2', and 'python3'.
        if (strpos($name, 'python') === 0) {
            return new DependencyManager\Pip($this->shell);
        }

        $stacks = [
            'nodejs' => new DependencyManager\Npm($this->shell),
            'ruby' => new DependencyManager\Bundler($this->shell),
            'php' => new DependencyManager\Composer($this->shell),
        ];
        if (isset($stacks[$name])) {
            return $stacks[$name];
        }

        throw new \InvalidArgumentException(sprintf('Unknown dependencies stack: %s', $name));
    }
}
