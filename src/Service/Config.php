<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\NestedArrayUtil;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuration used throughout the CLI.
 */
class Config
{
    protected static $config = [];

    protected $env = [];

    protected $userConfig = null;

    private $fs;

    private $version;

    /**
     * @param array|null  $env
     * @param string|null $defaultsFile
     * @param bool        $reset
     */
    public function __construct(array $env = null, $defaultsFile = null, $reset = false)
    {
        $this->env = $env !== null ? $env : $this->getDefaultEnv();

        if (empty(self::$config) || $reset) {
            $defaultsFile = $defaultsFile ?: CLI_ROOT . '/config.yaml';
            self::$config = $this->loadConfigFromFile($defaultsFile);
            $this->applyUserConfigOverrides();
            $this->applyEnvironmentOverrides();
        }
    }

    /**
     * Find all current environment variables.
     *
     * @return array
     */
    private function getDefaultEnv()
    {
        return PHP_VERSION_ID >= 70100 ? getenv() : $_ENV;
    }

    /**
     * Check if a configuration value is defined.
     *
     * @param string $name    The configuration name (e.g. 'application.name').
     * @param bool   $notNull Set false to treat null configuration values as
     *                        defined.
     *
     * @return bool
     */
    public function has($name, $notNull = true)
    {
        $value = NestedArrayUtil::getNestedArrayValue(self::$config, explode('.', $name), $exists);

        return $exists && (!$notNull || $value !== null);
    }

    /**
     * Get a configuration value.
     *
     * @param string $name The configuration name (e.g. 'application.name').
     *
     * @throws \RuntimeException if the configuration is not defined.
     *
     * @return null|string|bool|array
     */
    public function get($name)
    {
        $value = NestedArrayUtil::getNestedArrayValue(self::$config, explode('.', $name), $exists);
        if (!$exists) {
            throw new \RuntimeException('Configuration not defined: ' . $name);
        }

        return $value;
    }

    /**
     * Get a configuration value, specifying a default if it does not exist.
     *
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getWithDefault($name, $default)
    {
        $value = NestedArrayUtil::getNestedArrayValue(self::$config, explode('.', $name), $exists);
        if (!$exists) {
            return $default;
        }

        return $value;
    }

    /**
     * Get the directory where the CLI is normally installed and configured.
     *
     * @param bool $absolute Whether to return an absolute path. If false,
     *                       the path will be relative to the home directory.
     *
     * @return string
     */
    public function getUserConfigDir($absolute = true)
    {
        $path = $this->get('application.user_config_dir');

        return $absolute ? $this->fs()->getHomeDirectory() . '/' . $path : $path;
    }

    /**
     * @return \Platformsh\Cli\Service\Filesystem
     */
    private function fs()
    {
        return $this->fs ?: new Filesystem();
    }

    /**
     * @return string
     */
    public function getWritableUserDir()
    {
        $configDir = $this->getUserConfigDir();

        // If the config directory is not writable (e.g. if we are on a
        // Platform.sh environment), use a temporary directory instead.
        if (!$this->fs()->canWrite($configDir) || (file_exists($configDir) && !is_dir($configDir))) {
            return sys_get_temp_dir() . '/' . $this->get('application.tmp_sub_dir');
        }

        return $configDir;
    }

    /**
     * @return string
     */
    public function getSessionDir()
    {
        return $this->getWritableUserDir() . '/.session';
    }

    /**
     * Override a config value.
     *
     * @param string $name
     * @param mixed  $value
     *
     * @internal Used for tests etc.
     */
    public function override($name, $value)
    {
        NestedArrayUtil::setNestedArrayValue(self::$config, explode('.', $name), $value);
    }

    /**
     * @param string $filename
     *
     * @return array
     */
    public function loadConfigFromFile($filename)
    {
        /* Should this call the service?? */
        $fs = new Filesystem();
        return $fs->readYamlFile($filename);
    }

    protected function applyEnvironmentOverrides()
    {
        $overrideMap = [
            'TOKEN' => 'api.token',
            'API_TOKEN' => 'api.access_token', // Deprecated
            'COPY_ON_WINDOWS' => 'local.copy_on_windows',
            'DEBUG' => 'api.debug',
            'DISABLE_CACHE' => 'api.disable_cache',
            'DRUSH' => 'local.drush_executable',
            'SESSION_ID' => 'api.session_id',
            'SKIP_SSL' => 'api.skip_ssl',
            'ACCOUNTS_API' => 'api.accounts_api_url',
            'UPDATES_CHECK' => 'updates.check',
        ];

        foreach ($overrideMap as $var => $key) {
            $value = $this->getEnv($var);
            if ($value !== false) {
                NestedArrayUtil::setNestedArrayValue(self::$config, explode('.', $key), $value, true);
            }
        }
    }

    /**
     * Get an environment variable
     *
     * @param string $name
     *   The variable name. The configured prefix will be prepended.
     *
     * @return mixed|false
     *   The value of the environment variable, or false if it is not set.
     */
    protected function getEnv($name)
    {
        $prefix = isset(self::$config['application']['env_prefix']) ? self::$config['application']['env_prefix'] : '';
        if (array_key_exists($prefix . $name, $this->env)) {
            return $this->env[$prefix . $name];
        }

        return getenv($prefix . $name);
    }

    /**
     * @return array
     */
    public function getUserConfig()
    {
        if (!isset($this->userConfig)) {
            $this->userConfig = [];
            $userConfigFile = $this->getUserConfigDir() . '/config.yaml';
            if (file_exists($userConfigFile)) {
                $this->userConfig = $this->loadConfigFromFile($userConfigFile);
            }
        }

        return $this->userConfig;
    }

    protected function applyUserConfigOverrides()
    {
        // A whitelist of allowed overrides.
        $overrideMap = [
            'api' => 'api',
            'local.copy_on_windows' => 'local.copy_on_windows',
            'local.drush_executable' => 'local.drush_executable',
            'experimental' => 'experimental',
            'updates' => 'updates',
            'application.login_method' => 'application.login_method',
            'application.date_format' => 'application.date_format',
            'application.timezone' => 'application.timezone',
        ];

        $userConfig = $this->getUserConfig();
        if (!empty($userConfig)) {
            foreach ($overrideMap as $userConfigKey => $configKey) {
                $value = NestedArrayUtil::getNestedArrayValue($userConfig, explode('.', $userConfigKey), $exists);
                if ($exists) {
                    $configParents = explode('.', $configKey);
                    $default = NestedArrayUtil::getNestedArrayValue(self::$config, $configParents, $defaultExists);
                    if ($defaultExists && is_array($default)) {
                        if (!is_array($value)) {
                            continue;
                        }
                        $value = array_replace_recursive($default, $value);
                    }
                    NestedArrayUtil::setNestedArrayValue(self::$config, $configParents, $value, true);
                }
            }
        }
    }

    /**
     * Test if an experiment (a feature flag) is enabled.
     *
     * @param string $name
     *
     * @return bool
     */
    public function isExperimentEnabled($name)
    {
        return !empty(self::$config['experimental']['all_experiments']) || !empty(self::$config['experimental'][$name]);
    }

    /**
     * Test if a command should be enabled.
     *
     * @param string $name
     *
     * @return bool
     */
    public function isCommandEnabled($name)
    {
        if (!empty(self::$config['application']['disabled_commands'])
            && in_array($name, self::$config['application']['disabled_commands'])) {
            return false;
        }
        if (!empty(self::$config['application']['experimental_commands'])
            && in_array($name, self::$config['application']['experimental_commands'])) {
            return !empty(self::$config['experimental']['all_experiments'])
                || (
                    !empty(self::$config['experimental']['enable_commands'])
                    && in_array($name, self::$config['experimental']['enable_commands'])
                );
        }

        return true;
    }

    /**
     * Returns this application version.
     *
     * @return string
     */
    public function getVersion() {
        if (isset($this->version)) {
            return $this->version;
        }
        $version = $this->get('application.version');
        if (substr($version, 0, 1) === '@' && substr($version, -1) === '@') {
            // Silently try getting the version from Git.
            $tag = (new Shell())->execute(['git', 'describe', '--tags'], CLI_ROOT);
            if ($tag !== false && substr($tag, 0, 1) === 'v') {
                $version = trim($tag);
            }
        }
        $this->version = $version;

        return $version;
    }
}
