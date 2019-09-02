<?php
declare(strict_types=1);

namespace Platformsh\Cli\SiteAlias;

use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Drush;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;

/**
 * Base class for generating Drush site aliases.
 *
 * @see Drush::createAliases()
 */
abstract class DrushAlias implements SiteAliasTypeInterface
{
    protected $config;
    protected $drush;

    public function __construct(Config $config, Drush $drush)
    {
        $this->config = $config;
        $this->drush = $drush;
    }

    /**
     * {@inheritdoc}
     */
    public function createAliases(Project $project, $aliasGroup, array $apps, array $environments, $previousGroup = null)
    {
        if (!count($apps)) {
            return false;
        }

        // Prepare the Drush directory and file.
        $aliasDir = $this->drush->getSiteAliasDir();
        if (!is_dir($aliasDir) && !mkdir($aliasDir, 0755, true)) {
            throw new \RuntimeException('Drush aliases directory not found: ' . $aliasDir);
        }
        if (!is_writable($aliasDir)) {
            throw new \RuntimeException('Drush aliases directory not writable: ' . $aliasDir);
        }
        $filename = $this->getFilename($aliasGroup);
        if (file_exists($filename) && !is_writable($filename)) {
            throw new \RuntimeException("Drush alias file not writable: $filename");
        }

        // Gather existing aliases.
        $existingAliases = $this->getExistingAliases($aliasGroup, $previousGroup);

        // Generate the new aliases.
        $newAliases = $this->generateNewAliases($apps, $environments);

        // Merge new aliases with existing ones.
        $newAliases = $this->mergeExisting($newAliases, $existingAliases);

        // Add any user-defined (pre-existing) aliases.
        $autoRemoveKey = $this->getAutoRemoveKey();
        $userDefinedAliases = [];
        foreach ($existingAliases as $name => $alias) {
            if (!empty($alias[$autoRemoveKey]) || !empty($alias['options'][$autoRemoveKey])) {
                // This is probably for a deleted environment.
                continue;
            }
            $userDefinedAliases[$name] = $alias;
        }
        $aliases = $userDefinedAliases + $newAliases;

        // Normalize the aliases.
        $aliases = $this->normalize($aliases);

        // Format the aliases as a string.
        $header = rtrim($this->getHeader($project)) . "\n\n";
        $content = $header . $this->formatAliases($aliases);

        (new Filesystem())->writeFile($filename, $content);

        return true;
    }

    /**
     * Merge new aliases with existing ones.
     *
     * @param array $new
     * @param array $existing
     *
     * @return array
     */
    protected function mergeExisting($new, $existing)
    {
        foreach ($new as $aliasName => &$newAlias) {
            // If the alias already exists, recursively replace existing
            // settings with new ones.
            if (isset($existing[$aliasName])) {
                $newAlias = array_replace_recursive($existing[$aliasName], $newAlias);
            }
        }

        return $new;
    }

    /**
     * Normalize the aliases.
     *
     * @param array $aliases
     *
     * @return array
     */
    protected function normalize(array $aliases)
    {
        return $aliases;
    }

    /**
     * Get the filename for the aliases.
     *
     * @param string $groupName
     *
     * @return string
     */
    abstract protected function getFilename($groupName);

    /**
     * Get the header at the top of the file.
     *
     * @param Project $project
     *
     * @return string
     */
    abstract protected function getHeader(Project $project);

    /**
     * Find the existing defined aliases so they can be merged with new ones.
     *
     * @param string      $currentGroup
     * @param string|null $previousGroup
     *
     * @return array
     *   The aliases, with their group prefixes removed.
     */
    protected function getExistingAliases($currentGroup, $previousGroup = null)
    {
        $aliases = [];
        foreach (array_filter([$currentGroup, $previousGroup]) as $groupName) {
            foreach ($this->drush->getAliases($groupName) as $name => $alias) {
                // Remove the group prefix from the alias name.
                $name = ltrim($name, '@');
                if (strpos($name, $groupName . '.') === 0) {
                    $name = substr($name, strlen($groupName . '.'));
                }

                $aliases[$name] = $alias;
            }
        }

        return $aliases;
    }

    /**
     * Generate new aliases.
     *
     * @param LocalApplication[] $apps
     * @param array $environments
     *
     * @return array
     */
    protected function generateNewAliases(array $apps, array $environments)
    {
        $aliases = [];

        foreach ($apps as $app) {
            $appId = $app->getId();

            // Generate an alias for the local environment.
            $localAliasName = '_local';
            if (count($apps) > 1) {
                $localAliasName .= '--' . $appId;
            }
            $aliases[$localAliasName] = $this->generateLocalAlias($app);

            // Generate aliases for the remote environments.
            foreach ($environments as $environment) {
                $alias = $this->generateRemoteAlias($environment, $app);
                if (!$alias) {
                    continue;
                }

                $aliasName = $environment->id;
                if (count($apps) > 1) {
                    $aliasName .= '--' . $appId;
                }

                $aliases[$aliasName] = $alias;
            }
        }

        return $aliases;
    }

    /**
     * Format a list of aliases as a string.
     *
     * @param array $aliases
     *   A list of aliases.
     *
     * @return string
     */
    abstract protected function formatAliases(array $aliases);

    /**
     * Generate an alias for the local environment.
     *
     * @param \Platformsh\Cli\Local\LocalApplication $app
     *
     * @return array
     */
    protected function generateLocalAlias(LocalApplication $app)
    {
        return [
            'root' => $app->getLocalWebRoot(),
            'options' => [
                $this->getAutoRemoveKey() => true,
            ],
        ];
    }

    /**
     * Generate a remote Drush alias.
     *
     * @param Environment $environment
     * @param LocalApplication $app
     *
     * @return array|false
     */
    protected function generateRemoteAlias(Environment $environment, LocalApplication $app)
    {
        if (!$environment->hasLink('ssh')) {
            return false;
        }

        $sshUrl = $environment->getSshUrl($app->getName());

        $alias = [
            'options' => [
                $this->getAutoRemoveKey() => true,
            ],
        ];

        // For most environments, we know the application root directory is
        // '/app'. It's different in Enterprise environments.
        if ($environment->deployment_target !== 'local') {
            $appRoot = $this->drush->getCachedAppRoot($sshUrl);
            if ($appRoot) {
                $alias['root'] = rtrim($appRoot, '/') . '/' . $app->getDocumentRoot();
            }
        } else {
            $alias['root'] = '/app/' . $app->getDocumentRoot();
        }

        list($alias['user'], $alias['host']) = explode('@', $sshUrl, 2);

        if ($url = $this->drush->getSiteUrl($environment, $app)) {
            $alias['uri'] = $url;
        }

        return $alias;
    }

    /**
     * Generate a key that allows an alias to be automatically deleted later.
     *
     * @see DrushAlias::createAliases()
     *
     * @return string
     *     A string based on the application name, for example
     *     'platformsh-cli-auto-remove'.
     */
    private function getAutoRemoveKey()
    {
        return preg_replace(
                '/[^a-z-]+/',
                '-',
                str_replace('.', '', strtolower($this->config->get('application.name')))
            ) . '-auto-remove';
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAliases($group)
    {
        $filename = $this->getFilename($group);
        if (file_exists($filename)) {
            unlink($filename);
        }
    }

    /**
     * Swap the key names in an array of aliases.
     *
     * @param array $aliases
     * @param array $map
     *
     * @return array
     */
    protected function swapKeys(array $aliases, array $map)
    {
        return array_map(function ($alias) use ($map) {
            foreach ($map as $from => $to) {
                if (isset($alias[$from])) {
                    $alias[$to] = $alias[$from];
                    unset($alias[$from]);
                }
            }

            return $alias;
        }, $aliases);
    }
}
