<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;
use Platformsh\Cli\Model\Host\HostInterface;
use Platformsh\Cli\Model\Host\LocalHost;

/**
 * A service for reading environment variables on a host.
 */
class RemoteEnvVars
{

    protected $config;
    protected $ssh;
    protected $cache;

    /**
     * Constructor (dependencies are injected via the DIC).
     *
     * @param Ssh             $ssh
     * @param CacheProvider   $cache
     * @param Config          $config
     */
    public function __construct(Ssh $ssh, CacheProvider $cache, Config $config)
    {
        $this->ssh = $ssh;
        $this->cache = $cache;
        $this->config = $config;
    }

    /**
     * Read an environment variable from a remote application.
     *
     * @param string $variable The unprefixed name of the variable.
     * @param HostInterface $host The host of the application.
     * @param bool $refresh Whether to refresh the cache.
     * @param int $ttl The cache lifetime of the result.
     *
     * @return string The environment variable or an empty string.
     */
    public function getEnvVar($variable, HostInterface $host, $refresh = false, $ttl = 3600)
    {
        $varName = $this->config->get('service.env_prefix') . $variable;
        if ($host instanceof LocalHost) {
            return getenv($varName) !== false ? getenv($varName) : '';
        }
        $cacheKey = 'env-' . $host->getCacheKey() . '-' . $varName;
        $cached = $this->cache->fetch($cacheKey);
        if ($refresh || $cached === false) {
            $cached = $host->runCommand('echo "$' . $varName . '"');
            $this->cache->save($cacheKey, $cached, $ttl);
        }

        return $cached ?: '';
    }

    /**
     * Read a complex environment variable (an associative array) from the application.
     *
     * @param string $variable
     * @param HostInterface $host
     * @param bool $refresh
     *
     * @return array
     * @see \Platformsh\Cli\Service\RemoteEnvVars::getEnvVar()
     */
    public function getArrayEnvVar($variable, HostInterface $host, $refresh = false)
    {
        $value = $this->getEnvVar($variable, $host, $refresh);

        return json_decode(base64_decode($value), true) ?: [];
    }

    /**
     * Clear caches for remote environment variables.
     *
     * @param string $sshUrl    The SSH URL to the application.
     * @param array  $variables A list of unprefixed variables.
     */
    public function clearCaches($sshUrl, array $variables = ['APPLICATION', 'RELATIONSHIPS', 'ROUTES'])
    {
        $prefix = $this->config->get('service.env_prefix');
        foreach ($variables as $variable) {
            $this->cache->delete('env-' . $sshUrl . '-' . $prefix . $variable);
        }
    }
}
