<?php
declare(strict_types=1);

namespace Platformsh\Cli\Model;

use Platformsh\Cli\Exception\InvalidConfigException;
use Platformsh\Cli\Service\Mount;
use Platformsh\Client\Model\Deployment\WebApp;

/**
 * A class to help reading and normalizing an application's configuration.
 */
class AppConfig
{
    private $config;
    private $normalizedConfig;

    /**
     * AppConfig constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->normalizedConfig = $this->normalizeConfig($config);
    }

    /**
     * @param \Platformsh\Client\Model\Deployment\WebApp $app
     *
     * @return static
     */
    public static function fromWebApp(WebApp $app)
    {
        return new static($app->getProperties());
    }

    /**
     * Get normalized configuration.
     *
     * @return array
     */
    public function getNormalized()
    {
        if (!isset($this->normalizedConfig)) {
            $this->normalizedConfig = $this->normalizeConfig($this->config);
        }

        return $this->normalizedConfig;
    }

    /**
     * Get the (normalized) document root as a relative path.
     *
     * @return string
     */
    public function getDocumentRoot()
    {
        $documentRoot = '';
        $normalized = $this->getNormalized();
        if (empty($normalized['web']['locations'])) {
            return $documentRoot;
        }
        foreach ($this->getNormalized()['web']['locations'] as $path => $location) {
            if (isset($location['root'])) {
                $documentRoot = $location['root'];
            }
            if ($path === '/') {
                break;
            }
        }

        return ltrim($documentRoot, '/');
    }

    /**
     * Normalize the application config.
     *
     * @param array $config
     *
     * @return array
     */
    private function normalizeConfig(array $config)
    {
        // Backwards compatibility with old config format: `toolstack` is
        // changed to application `type` and `build`.`flavor`.
        if (isset($config['toolstack'])) {
            if (!strpos($config['toolstack'], ':')) {
                throw new InvalidConfigException("Invalid value for 'toolstack'");
            }
            list($config['type'], $config['build']['flavor']) = explode(':', $config['toolstack'], 2);
        }

        // The `web` section has changed to `web`.`locations`.
        if (isset($config['web']['document_root']) && empty($config['web']['locations'])) {
            $oldConfig = $config['web'] + $this->getOldWebDefaults();

            $location = &$config['web']['locations']['/'];

            $location['root'] = $oldConfig['document_root'];
            $location['expires'] = $oldConfig['expires'];
            $location['passthru'] = $oldConfig['passthru'];
            $location['allow'] = true;

            foreach ($oldConfig['whitelist'] as $pattern) {
                $location['allow'] = false;
                $location['rules'][$pattern]['allow'] = true;
            }

            foreach ($oldConfig['blacklist'] as $pattern) {
                $location['rules'][$pattern]['allow'] = false;
            }
        }

        if (!empty($config['mounts'])) {
            $config['mounts'] = (new Mount())->normalizeMounts($config['mounts']);
        }

        return $config;
    }

    /**
     * @return array
     */
    private function getOldWebDefaults()
    {
        return [
            'document_root' => '/public',
            'expires' => 0,
            'passthru' => '/index.php',
            'blacklist' => [],
            'whitelist' => [
                // CSS and Javascript.
                '\.css$',
                '\.js$',

                // image/* types.
                '\.gif$',
                '\.jpe?g$',
                '\.png$',
                '\.tiff?$',
                '\.wbmp$',
                '\.ico$',
                '\.jng$',
                '\.bmp$',
                '\.svgz?$',

                // audio/* types.
                '\.midi?$',
                '\.mpe?ga$',
                '\.mp2$',
                '\.mp3$',
                '\.m4a$',
                '\.ra$',
                '\.weba$',

                // video/* types.
                '\.3gpp?$',
                '\.mp4$',
                '\.mpe?g$',
                '\.mpe$',
                '\.ogv$',
                '\.mov$',
                '\.webm$',
                '\.flv$',
                '\.mng$',
                '\.asx$',
                '\.asf$',
                '\.wmv$',
                '\.avi$',

                // application/ogg.
                '\.ogx$',

                // application/x-shockwave-flash.
                '\.swf$',

                // application/java-archive.
                '\.jar$',

                // fonts types.
                '\.ttf$',
                '\.eot$',
                '\.woff$',
                '\.otf$',

                // robots.txt.
                '/robots\.txt$',
            ],
        ];
    }
}
