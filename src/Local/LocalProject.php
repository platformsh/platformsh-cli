<?php

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Client\Model\Project;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

class LocalProject
{
    protected $config;
    protected $fs;
    protected $git;

    protected static $projectConfigs = [];
    protected static $fleetConfigs = [];

    public function __construct(Config $config = null, Git $git = null)
    {
        $this->config = $config ?: new Config();
        $this->git = $git ?: new Git();
        $this->fs = new Filesystem();
    }

    /**
     * Read a config file for a project.
     *
     * @param string $dir        The project root.
     * @param string $configFile A config file such as 'services.yaml'.
     *
     * @return array|null
     */
    public function readProjectConfigFile($dir, $configFile)
    {
        $result = null;
        $filename = $dir . '/' . $this->config->get('service.project_config_dir') . '/' . $configFile;
        if (file_exists($filename)) {
            $parser = new Parser();
            $result = $parser->parse(file_get_contents($filename));
        }

        return $result;
    }

    /**
     * @param string $gitUrl
     *
     * @return array|false
     *   An array containing 'id' and 'host', or false on failure.
     */
    protected function parseGitUrl($gitUrl)
    {
        $gitDomain = $this->config->get('detection.git_domain');
        $pattern = '/^([a-z0-9]{12,})@git\.(([a-z0-9\-]+\.)?' . preg_quote($gitDomain) . '):\1\.git$/';
        if (!preg_match($pattern, $gitUrl, $matches)) {
            return false;
        }

        return ['id' => $matches[1], 'host' => $matches[2]];
    }

    /**
     * @param string $dir
     *
     * @throws \RuntimeException
     *   If no remote can be found.
     *
     * @return string|false
     *   The Git remote URL.
     */
    protected function getGitRemoteUrl($dir)
    {
        $this->git->ensureInstalled();
        foreach ([$this->config->get('detection.git_remote_name'), 'origin'] as $remote) {
            if ($url = $this->git->getConfig("remote.$remote.url", $dir)) {
                return $url;
            }
        }

        return false;
    }

    /**
     * Ensure there is an appropriate Git remote in the repository.
     *
     * @param string $dir
     *   The repository directory.
     * @param string $url
     *   The Git URL.
     */
    public function ensureGitRemote($dir, $url)
    {
        if (!file_exists($dir . '/.git')) {
            throw new \InvalidArgumentException('The directory is not a Git repository');
        }
        $this->git->ensureInstalled();
        $currentUrl = $this->git->getConfig(
            sprintf('remote.%s.url', $this->config->get('detection.git_remote_name')),
            $dir
        );
        if (!$currentUrl) {
            $this->git->execute(
                ['remote', 'add', $this->config->get('detection.git_remote_name'), $url],
                $dir,
                true
            );
        } elseif ($currentUrl !== $url) {
            $this->git->execute([
                'remote',
                'set-url',
                $this->config->get('detection.git_remote_name'),
                $url
            ], $dir, true);
        }
    }

    /**
     * Find the highest level directory that contains a file.
     *
     * @param string $file
     *   The filename to look for.
     * @param callable $callback
     *   A callback to validate the directory when found. Accepts one argument
     *   (the directory path). Return true to use the directory, or false to
     *   continue traversing upwards.
     *
     * @return string|false
     *   The path to the directory, or false if the file is not found.
     */
    protected static function findTopDirectoryContaining($file, callable $callback = null)
    {
        static $roots = [];
        $cwd = getcwd();
        if ($cwd === false) {
            return false;
        }
        if (isset($roots[$cwd][$file])) {
            return $roots[$cwd][$file];
        }

        $roots[$cwd][$file] = false;
        $root = &$roots[$cwd][$file];

        $currentDir = $cwd;
        while (!$root) {
            if (file_exists($currentDir . '/' . $file)) {
                if ($callback === null || $callback($currentDir)) {
                    $root = $currentDir;
                    break;
                }
            }

            // The file was not found, go one directory up.
            $levelUp = dirname($currentDir);
            if ($levelUp === $currentDir || $levelUp === '.') {
                break;
            }
            $currentDir = $levelUp;
        }

        return $root;
    }

    /**
     * Initialize a directory as a project root.
     *
     * @param string $directory
     *   The Git repository that should be initialized.
     * @param Project $project
     *   The project.
     */
    public function mapDirectory($directory, Project $project)
    {
        if (!file_exists($directory . '/.git')) {
            throw new \InvalidArgumentException('Not a Git repository: ' . $directory);
        }
        $projectConfig = [
            'id' => $project->id,
        ];
        if ($host = parse_url($project->getUri(), PHP_URL_HOST)) {
            $projectConfig['host'] = $host;
        }
        $this->writeCurrentProjectConfig($projectConfig, $directory, true);
        $this->ensureGitRemote($directory, $project->getGitUrl());
    }

    /**
     * Find the legacy root of the current project, from CLI versions <3.
     *
     * @return string|false
     */
    public function getLegacyProjectRoot()
    {
        return $this->findTopDirectoryContaining($this->config->get('local.project_config_legacy'));
    }

    /**
     * Find the root of the current project.
     *
     * @return string|false
     */
    public function getProjectRoot()
    {
        // Backwards compatibility - if in an old-style project root, change
        // directory to the repository.
        if (is_dir('repository') && file_exists($this->config->get('local.project_config_legacy'))) {
            $cwd = getcwd();
            chdir('repository');
        }

        // The project root is a Git repository, which contains a project
        // configuration file, and/or contains a Git remote with the appropriate
        // domain.
        $dir = $this->findTopDirectoryContaining('.git', function ($dir) {
            $config = $this->getProjectConfig($dir);

            return !empty($config);
        });

        if (isset($cwd)) {
            chdir($cwd);
        }

        return $dir;
    }

    /**
     * Get the configuration for the current project.
     *
     * @param string|null $projectRoot
     *
     * @return array|null
     *   The current project's configuration.
     */
    public function getProjectConfig($projectRoot = null)
    {
        $projectRoot = $projectRoot ?: $this->getProjectRoot();
        if (isset(self::$projectConfigs[$projectRoot])) {
            return self::$projectConfigs[$projectRoot];
        }
        $projectConfig = null;
        $configFilename = $this->config->get('local.project_config');
        if ($projectRoot && file_exists($projectRoot . '/' . $configFilename)) {
            $yaml = new Parser();
            $projectConfig = $yaml->parse(file_get_contents($projectRoot . '/' . $configFilename));
            self::$projectConfigs[$projectRoot] = $projectConfig;
        } elseif ($projectRoot && is_dir($projectRoot . '/.git')) {
            $gitUrl = $this->getGitRemoteUrl($projectRoot);
            if ($gitUrl && ($projectConfig = $this->parseGitUrl($gitUrl))) {
                $this->writeCurrentProjectConfig($projectConfig, $projectRoot);
            }
        }

        return $projectConfig;
    }

    /**
     * Write configuration for a project.
     *
     * Configuration is stored as YAML, in the location configured by
     * 'local.project_config'.
     *
     * @param array $config
     *   The configuration.
     * @param string $projectRoot
     *   The project root.
     * @param bool   $merge
     *   Whether to merge with existing configuration.
     *
     * @throws \Exception On failure
     *
     * @return array
     *   The updated project configuration.
     */
    public function writeCurrentProjectConfig(array $config, $projectRoot = null, $merge = false)
    {
        $projectRoot = $projectRoot ?: $this->getProjectRoot();
        if (!$projectRoot) {
            throw new \Exception('Project root not found');
        }
        $this->ensureLocalDir($projectRoot);
        $file = $projectRoot . '/' . $this->config->get('local.project_config');
        if ($merge) {
            $projectConfig = $this->getProjectConfig($projectRoot) ?: [];
            $config = array_merge($projectConfig, $config);
        }
        $yaml = (new Dumper())->dump($config, 10);
        $this->fs->dumpFile($file, $yaml);

        self::$projectConfigs[$projectRoot] = $config;

        return $config;
    }

    /**
     * @param string $projectRoot
     */
    public function ensureLocalDir($projectRoot)
    {
        $localDirRelative = $this->config->get('local.local_dir');
        $dir = $projectRoot . '/' . $localDirRelative;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->writeGitExclude($projectRoot);
        if (!file_exists($dir . '/.gitignore')) {
            file_put_contents($dir . '/.gitignore', '/' . PHP_EOL);
        }
        if (!file_exists($dir . '/README.txt')) {
            $cliName = $this->config->get('application.name');
            file_put_contents($dir . '/README.txt', <<<EOF
{$localDirRelative}
===============

This directory is where the {$cliName} stores configuration files, builds, and
other data to help work with your project locally.

It is not used on remote environments at all - the directory is excluded from
your Git repository (via .git/info/exclude).

EOF
            );
        }
    }

    /**
     * Write to the Git exclude file.
     *
     * @param string $dir
     */
    public function writeGitExclude($dir)
    {
        $filesToExclude = ['/' . $this->config->get('local.local_dir'), '/' . $this->config->get('local.web_root')];
        $excludeFilename = $dir . '/.git/info/exclude';
        $existing = '';

        // Skip writing anything if the contents already include the
        // application.name.
        if (file_exists($excludeFilename)) {
            $existing = file_get_contents($excludeFilename);
            if (strpos($existing, $this->config->get('application.name')) !== false) {
                // Backwards compatibility between versions 3.0.0 and 3.0.2.
                $newRoot = "\n" . '/' . $this->config->get('application.name') . "\n";
                $oldRoot = "\n" . '/.www' . "\n";
                if (strpos($existing, $oldRoot) !== false && strpos($existing, $newRoot) === false) {
                    $this->fs->dumpFile($excludeFilename, str_replace($oldRoot, $newRoot, $existing));
                }
                if (is_link($dir . '/.www')) {
                    unlink($dir . '/.www');
                }
                // End backwards compatibility block.

                return;
            }
        }

        $content = "# Automatically added by the " . $this->config->get('application.name') . "\n"
            . implode("\n", $filesToExclude)
            . "\n";
        if (!empty($existing)) {
            $content = $existing . "\n" . $content;
        }
        $this->fs->dumpFile($excludeFilename, $content);
    }
}
