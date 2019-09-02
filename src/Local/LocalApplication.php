<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Exception\InvalidConfigException;
use Platformsh\Cli\Local\BuildFlavor\BuildFlavorInterface;
use Platformsh\Cli\Util\YamlParser;
use Symfony\Component\Finder\Finder;

class LocalApplication
{

    protected $appRoot;
    protected $config;
    protected $sourceDir;
    protected $cliConfig;

    private $single = false;

    /**
     * @param string      $appRoot
     * @param Config|null $cliConfig
     * @param string|null $sourceDir
     */
    public function __construct($appRoot, Config $cliConfig = null, $sourceDir = null)
    {
        if (!is_dir($appRoot)) {
            throw new \InvalidArgumentException("Application directory not found: $appRoot");
        }
        $this->cliConfig = $cliConfig ?: new Config();
        $this->appRoot = $appRoot;
        $this->sourceDir = $sourceDir ?: $appRoot;
    }

    /**
     * Get a unique identifier for this app.
     *
     * @return string
     */
    public function getId()
    {
        return $this->getName() ?: $this->getPath() ?: 'default';
    }

    /**
     * Returns whether this application is the only one in the project.
     *
     * @return bool
     */
    public function isSingle()
    {
       return $this->single;
    }

    /**
     * Set that this is is the only application in the project.
     *
     * @param bool $single
     */
    public function setSingle($single = true)
    {
        $this->single = $single;
    }

    /**
     * Get the source directory where the application was found.
     *
     * In a single-app project, this is usually the project root.
     *
     * @return string
     */
    public function getSourceDir()
    {
        return $this->sourceDir;
    }

    /**
     * @return string
     */
    protected function getPath()
    {
        return str_replace($this->sourceDir . '/', '', $this->appRoot);
    }

    /**
     * @return string|null
     */
    public function getName()
    {
        $config = $this->getConfig();

        return !empty($config['name']) ? $config['name'] : null;
    }

    /**
     * @return string
     */
    public function getRoot()
    {
        return $this->appRoot;
    }

    /**
     * Get the absolute path to the local web root of this app.
     *
     * @param string|null $destination
     *
     * @return string
     */
    public function getLocalWebRoot($destination = null)
    {
        $destination = $destination ?: $this->getSourceDir() . '/' . $this->cliConfig->get('local.web_root');
        if ($this->isSingle()) {
            return $destination;
        }

        return $destination . '/' . str_replace('/', '-', $this->getId());
    }

    /**
     * Override the application config.
     *
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->config = new AppConfig($config);
    }

    /**
     * Get the application's configuration, parsed from its YAML definition.
     *
     * @throws \Exception if the configuration file cannot be read
     * @throws InvalidConfigException if config is invalid
     *
     * @return array
     * @throws \Exception
     */
    public function getConfig()
    {
        return $this->getConfigObject()->getNormalized();
    }

    /**
     * Get the application's configuration as an object.
     *
     * @throws InvalidConfigException if config is not found or invalid
     *
     * @return AppConfig
     */
    private function getConfigObject()
    {
        if (!isset($this->config)) {
            $file = $this->appRoot . '/' . $this->cliConfig->get('service.app_config_file');
            if (!file_exists($file)) {
                throw new InvalidConfigException('Configuration file not found: ' . $file);
            }
            $config = (array) (new YamlParser())->parseFile($file);
            $this->config = new AppConfig($config);
        }

        return $this->config;
    }

    /**
     * Get a list of shared file mounts configured for the app.
     *
     * @return array
     */
    public function getSharedFileMounts()
    {
        $appConfig = $this->getConfig();
        $sharedFileMounts = [];
        if (!empty($appConfig['mounts'])) {
            foreach ($appConfig['mounts'] as $path => $definition) {
                if (isset($definition['source_path'])) {
                    $sharedFileMounts[$path] = $definition['source_path'] ?: 'files';
                }
            }
        }

        return $sharedFileMounts;
    }

    /**
     * @return BuildFlavorInterface[]
     */
    public function getBuildFlavors()
    {
        return [
            new BuildFlavor\Drupal(),
            new BuildFlavor\Symfony(),
            new BuildFlavor\Composer(),
            new BuildFlavor\NodeJs(),
            new BuildFlavor\NoBuildFlavor(),
        ];
    }

    /**
     * Get the build flavor for the application.
     *
     * @throws InvalidConfigException If a build flavor is not found.
     *
     * @return BuildFlavorInterface
     */
    public function getBuildFlavor()
    {
        $appConfig = $this->getConfig();
        if (!isset($appConfig['type'])) {
            throw new InvalidConfigException('Application configuration key not found: `type`');
        }

        $key = isset($appConfig['build']['flavor']) ? $appConfig['build']['flavor'] : 'default';
        list($stack, ) = explode(':', $appConfig['type'], 2);
        foreach (self::getBuildFlavors() as $candidate) {
            if (in_array($key, $candidate->getKeys())
                && ($candidate->getStacks() === [] || in_array($stack, $candidate->getStacks()))) {
                return $candidate;
            }
        }
        throw new InvalidConfigException('Build flavor not found: ' . $key);
    }

    /**
     * Get a list of applications in a directory.
     *
     * @param string $directory
     *     The absolute path to a directory.
     * @param Config|null $config
     *     CLI configuration.
     *
     * @return LocalApplication[]
     */
    public static function getApplications($directory, Config $config = null)
    {
        // Finder can be extremely slow with a deep directory structure. The
        // search depth is limited to safeguard against this.
        $finder = new Finder();
        $config = $config ?: new Config();
        $finder->in($directory)
               ->ignoreDotFiles(false)
               ->name($config->get('service.app_config_file'))
               ->ignoreUnreadableDirs()
               ->exclude([
                   '.idea',
                   $config->get('local.local_dir'),
                   'builds',
                   'node_modules',
                   'vendor',
               ])
               ->depth('< 5');

        /** @var \Platformsh\Cli\Local\LocalApplication[] $applications */
        $applications = [];

        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($finder as $file) {
            $appRoot = dirname($file->getRealPath());
            $applications[$appRoot] = new LocalApplication($appRoot, $config, $directory);
        }

        // If there are no application config files found, treat the
        // directory as a single application.
        if (empty($applications)) {
            $applications[$directory] = new LocalApplication($directory, $config, $directory);
        }

        if (count($applications) === 1) {
            foreach ($applications as $application) {
                $application->setSingle(true);
                break;
            }
        }

        return $applications;
    }

    /**
     * Get the configured document root for the application, as a relative path.
     *
     * @param string $default
     *
     * @todo stop using 'public' as the default
     *
     * @return string
     */
    public function getDocumentRoot($default = 'public')
    {
        return $this->getConfigObject()->getDocumentRoot() ?: $default;
    }

    /**
     * Check whether the whole app should be moved into the document root.
     *
     * @return string
     */
    public function shouldMoveToRoot()
    {
        $config = $this->getConfig();

        if (isset($config['move_to_root']) && $config['move_to_root'] === true) {
            return true;
        }

        return $this->getDocumentRoot() === 'public' && !is_dir($this->getRoot() . '/public');
    }
}
