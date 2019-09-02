<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Exception\DependencyMissingException;
use Platformsh\Cli\Exception\ProcessFailedException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\SiteAlias\DrushPhp;
use Platformsh\Cli\SiteAlias\DrushYaml;
use Platformsh\Cli\SiteAlias\SiteAliasTypeInterface;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;

class Drush
{
    /** @var Api */
    protected $api;

    /** @var Shell */
    private $shellHelper;

    /** @var LocalProject */
    private $localProject;

    /** @var Config */
    private $config;

    /** @var string|null */
    private $homeDir;

    /** @var array */
    private $aliases = [];

    /** @var string|false|null */
    private $version;

    /** @var string|null */
    private $executable;

    /** @var string[] */
    protected $cachedAppRoots = [];

    /**
     * @param Config|null $config
     * @param Shell|null $shellHelper
     * @param LocalProject|null $localProject
     * @param Api|null $api
     */
    public function __construct(
        Config $config = null,
        Shell $shellHelper = null,
        LocalProject $localProject = null,
        Api $api = null
    ) {
        $this->shellHelper = $shellHelper ?: new Shell();
        $this->config = $config ?: new Config();
        $this->localProject = $localProject ?: new LocalProject();
        $this->api = $api ?: new Api($this->config);
    }

    public function setHomeDir(string $homeDir): void
    {
        $this->homeDir = $homeDir;
    }

    public function getHomeDir(): string
    {
        return $this->homeDir ?: Filesystem::getHomeDirectory();
    }

    /**
     * @param string $sshUrl
     * @param string $enterpriseAppRoot
     */
    public function setCachedAppRoot($sshUrl, $enterpriseAppRoot)
    {
        $this->cachedAppRoots[$sshUrl] = $enterpriseAppRoot;
    }

    /**
     * @param string $sshUrl
     *
     * @return string
     */
    public function getCachedAppRoot($sshUrl)
    {
        return isset($this->cachedAppRoots[$sshUrl]) ? $this->cachedAppRoots[$sshUrl] : false;
    }

    /**
     * Find the global Drush configuration directory.
     *
     * @return string
     */
    public function getDrushDir(): string
    {
        return $this->getHomeDir() . '/.drush';
    }

    /**
     * Find the directory where global Drush site aliases should be stored.
     *
     * @return string
     */
    public function getSiteAliasDir(): string
    {
        $aliasDir = $this->getDrushDir() . '/site-aliases';
        if (!file_exists($aliasDir) && $this->getLegacyAliasFiles()) {
            $aliasDir = $this->getDrushDir();
        }

        return $aliasDir;
    }

    /**
     * Get a list of legacy alias files.
     *
     * @return string[]
     */
    public function getLegacyAliasFiles(): array
    {
        return glob($this->getDrushDir() . '/*.alias*.*', GLOB_NOSORT);
    }

    /**
     * Get the installed Drush version.
     *
     * @param bool $reset
     *
     * @return string|false
     *   The Drush version, or false if it cannot be determined.
     */
    public function getVersion(bool $reset = false)
    {
        if ($reset || !isset($this->version)) {
            $this->version = $this->shellHelper->execute(
                [$this->getDrushExecutable(), 'version', '--format=string']
            );
        }


        return $this->version;
    }

    /**
     * @throws DependencyMissingException
     */
    public function ensureInstalled(): void
    {
        if ($this->getVersion() === false) {
            throw new DependencyMissingException('Drush is not installed');
        }
    }

    /**
     * Checks whether Drush supports the --lock argument for the 'make' command.
     *
     * @return bool
     */
    public function supportsMakeLock(): bool
    {
        return version_compare($this->getVersion(), '7.0.0-rc1', '>=');
    }

    /**
     * Execute a Drush command.
     *
     * @param string[] $args
     *   Command arguments (everything after 'drush').
     * @param string   $dir
     *   The working directory.
     * @param bool     $mustRun
     *   Enable exceptions if the command fails.
     * @param bool     $quiet
     *   Suppress command output.
     *
     * @return string|bool
     */
    public function execute(array $args, ?string $dir = null, bool $mustRun = false, bool $quiet = true)
    {
        array_unshift($args, $this->getDrushExecutable());

        return $this->shellHelper->execute($args, $dir, $mustRun, $quiet);
    }

    /**
     * Get the full path to the Drush executable.
     *
     * @return string
     *   The absolute path to the executable, or 'drush' if the path is not
     *   known.
     */
    private function getDrushExecutable(): string
    {
        if (isset($this->executable)) {
            return $this->executable;
        }

        if ($this->config->has('local.drush_executable')) {
            return $this->executable = $this->config->get('local.drush_executable');
        }

        // Find a locally installed Drush instance: first check the Composer
        // 'vendor' directory.
        $projectRoot = $this->localProject->getProjectRoot();
        $localDir = $projectRoot ?: getcwd();
        $drushLocal = $localDir . '/vendor/bin/drush';
        if (is_executable($drushLocal)) {
            return $this->executable = $drushLocal;
        }

        // Check the local dependencies directory (created via 'platform
        // build').
        $drushDep = $localDir . '/' . $this->config->get('local.dependencies_dir') . '/php/vendor/bin/drush';
        if (is_executable($drushDep)) {
            return $this->executable = $drushDep;
        }

        // Use the global Drush, if there is one installed.
        if ($this->shellHelper->commandExists('drush')) {
            return $this->executable = $this->shellHelper->resolveCommand('drush');
        }

        // Fall back to the Drush that may be installed within the CLI.
        $drushCli = CLI_ROOT . '/vendor/bin/drush';
        if (is_executable($drushCli)) {
            return $this->executable = $drushCli;
        }

        return $this->executable = 'drush';
    }

    /**
     * @return bool
     */
    public function clearCache(): bool
    {
        return (bool) $this->execute(['cache-clear', 'drush']);
    }

    /**
     * Get existing Drush aliases for a group.
     *
     * @param string $groupName
     * @param bool   $reset
     *
     * @throws \Exception If the "drush sa" command fails.
     *
     * @return array
     */
    public function getAliases(string $groupName, bool $reset = false): array
    {
        if (!$reset && isset($this->aliases[$groupName])) {
            return $this->aliases[$groupName];
        }

        // Drush 9 uses 'site:alias', Drush <9 uses 'site-alias'. Fortunately
        // the alias 'sa' exists in both.
        $args = [$this->getDrushExecutable(), '@none', 'sa', '--format=json', '@' . $groupName];

        $aliases = [];

        // Run the command with a 5-second timeout. An exception will be thrown
        // if it fails.
        try {
            $result = $this->shellHelper->execute($args, null, true, true, [], 5);
            if (is_string($result)) {
                $aliases = (array) json_decode($result, true);
            }
        } catch (ProcessFailedException $e) {
            // The command will fail if the alias is not found. Throw an
            // exception for any other failures.
            if (stripos($e->getProcess()->getErrorOutput(), 'not found') === false) {
                throw $e;
            }
        }

        $this->aliases[$groupName] = $aliases;

        return $aliases;
    }

    /**
     * Get the alias group for a project.
     *
     * @param Project $project
     * @param string  $projectRoot
     *
     * @return string
     */
    public function getAliasGroup(Project $project, string $projectRoot): string
    {
        $config = $this->localProject->getProjectConfig($projectRoot);

        return !empty($config['alias-group']) ? $config['alias-group'] : $project->id;
    }

    /**
     * @param string $newGroup
     * @param string $projectRoot
     */
    public function setAliasGroup(string $newGroup, string $projectRoot): void
    {
        $this->localProject->writeCurrentProjectConfig(['alias-group' => $newGroup], $projectRoot, true);
    }

    /**
     * Create Drush aliases for the provided project and environments.
     *
     * @param Project       $project      The project
     * @param string        $projectRoot  The project root
     * @param Environment[] $environments The environments
     * @param string        $original     The original group name
     *
     * @return bool True on success, false on failure.
     */
    public function createAliases(Project $project, string $projectRoot, array $environments, ?string $original = null): bool
    {
        if (!$apps = $this->getDrupalApps($projectRoot)) {
            return false;
        }

        $group = $this->getAliasGroup($project, $projectRoot);

        $success = true;
        foreach ($this->getSiteAliasTypes() as $type) {
            $success = $success && $type->createAliases($project, $group, $apps, $environments, $original);
        }

        return $success;
    }

    /**
     * Find Drupal applications in a project.
     *
     * @param string $projectRoot
     *
     * @return LocalApplication[]
     */
    public function getDrupalApps(string $projectRoot): array
    {
        return array_filter(
            LocalApplication::getApplications($projectRoot, $this->config),
            function (LocalApplication $app) {
                return Drupal::isDrupal($app->getRoot());
            }
        );
    }

    /**
     * @return SiteAliasTypeInterface[]
     */
    private function getSiteAliasTypes(): array
    {
        $types = [];
        $types[] = new DrushYaml($this->config, $this);
        $types[] = new DrushPhp($this->config, $this);

        return $types;
    }

    /**
     * Returns the site URL.
     *
     * @param Environment      $environment
     * @param LocalApplication $app
     *
     * @todo this is really a hidden dependency on the Api service
     *
     * @return string|null
     */
    public function getSiteUrl(Environment $environment, LocalApplication $app)
    {
        if ($this->api->hasCachedCurrentDeployment($environment)) {
            return $this->api->getSiteUrl($environment, $app->getName());
        }

        $urls = $environment->getRouteUrls();
        if (count($urls) === 1) {
            return reset($urls) ?: null;
        }

        return null;
    }

    /**
     * @param string $group
     */
    public function deleteOldAliases(string $group): void
    {
        foreach ($this->getSiteAliasTypes() as $type) {
            $type->deleteAliases($group);
        }
    }
}
