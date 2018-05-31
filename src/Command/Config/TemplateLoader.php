<?php

namespace Platformsh\Cli\Command\Config;

use Doctrine\Common\Cache\CacheProvider;
use Twig_Error_Loader;

class TemplateLoader implements \Twig_LoaderInterface
{
    protected $filePath;
    protected $cache;

    /**
     * @param string                               $filePath
     * @param \Doctrine\Common\Cache\CacheProvider $cache
     */
    public function __construct($filePath, CacheProvider $cache)
    {
        $this->filePath = $filePath;
        $this->cache = $cache;
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheKey($name)
    {
        return $name;
    }

    /**
     * {@inheritdoc}
     */
    public function isFresh($name, $time)
    {
        return time() - $time < 300;
    }

    protected function isUrl($name)
    {
        return strpos($name, 'https://') !== false || strpos($name, 'http://') !== false;
    }

    /**
     * @param string $url
     *
     * @throws Twig_Error_Loader
     *
     * @return string
     */
    protected function download($url)
    {
        if ($this->cache->contains($url)) {
            return $this->cache->fetch($url);
        }
        $context = stream_context_create(['http' => ['timeout' => 5]]);
        $content = file_get_contents($url, FILE_BINARY, $context);
        if ($content === false) {
            throw new Twig_Error_Loader("Failed to download file: $url");
        }
        $this->cache->save($url, $content, 300);

        return $content;
    }

    /**
     * {@inheritdoc}
     */
    public function getSource($name)
    {
        if ($this->isUrl($name)) {
            return $this->download($name);
        }
        $fileName = $this->filePath . '/' . $name;
        $content = file_get_contents($fileName);
        if ($content === false) {
            throw new Twig_Error_Loader("Failed to load from file: $fileName");
        }

        return $content;
    }
}
