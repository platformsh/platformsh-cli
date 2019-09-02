<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\VoidCache;

class CacheFactory
{

    /**
     * @param \Platformsh\Cli\Service\Config $cliConfig
     *
     * @return \Doctrine\Common\Cache\CacheProvider
     */
    public static function createCacheProvider(Config $cliConfig): CacheProvider
    {
        if (!empty($cliConfig->get('api.disable_cache'))) {
            return new VoidCache();
        }

        return new FilesystemCache(
            $cliConfig->getWritableUserDir() . '/cache',
            FilesystemCache::EXTENSION,
            0077 // Remove all permissions from the group and others.
        );
    }
}
