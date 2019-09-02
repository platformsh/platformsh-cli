<?php

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Git\Commit;
use Platformsh\Client\Model\Git\Tree;
use Platformsh\Client\Model\Project;

/**
 * Service helping to read from the Git Data API.
 */
class GitDataApi
{
    public const COMMIT_SYNTAX_HELP = 'This can also accept "HEAD", and caret (^) or tilde (~) suffixes for parent commits.';

    private $api;
    private $cache;

    public function __construct(
        Api $api = null
    ) {
        $this->api = $api ?: new Api();
        $this->cache = $this->api->getCache();
    }

    /**
     * Parse the parents in a Git commit spec.
     *
     * @param string $sha
     *
     * @return int[]
     *   A list of parents.
     */
    private function parseParents($sha)
    {
        if (!strpos($sha, '^') && !strpos($sha, '~')) {
            return [];
        }
        preg_match_all('#[\^~][0-9]*#', $sha, $matches);
        $parents = [];
        foreach ($matches[0] as $match) {
            $sign = $match[0];
            $number = intval(substr($match, 1) ?: 1);
            if ($sign === '~') {
                for ($i = 1; $i <= $number; $i++) {
                    $parents[] = 1;
                }
            } elseif ($sign === '^') {
                $parents[] = intval($number);
            }
        }

        return $parents;
    }

    /**
     * Get a Git Commit object for an environment.
     *
     * @param \Platformsh\Client\Model\Environment $environment
     * @param string|null                          $sha
     *
     * @return \Platformsh\Client\Model\Git\Commit|false
     */
    public function getCommit(Environment $environment, $sha = null)
    {
        $sha = $this->normalizeSha($environment, $sha);
        if ($sha === null) {
            return false;
        }

        $parents = $this->parseParents($sha);
        $sha = preg_replace('/[\^~].*$/', '', $sha);
        if ($sha === '') {
            return false;
        }

        // Get the first commit.
        $commit = $this->getCommitByShaHash($environment, $sha);
        if (!$commit) {
            return false;
        }

        // Fetch parent commits recursively.
        while ($commit !== false && count($parents)) {
            $parent = array_shift($parents);
            if (isset($commit->parents[$parent - 1])) {
                $commit = $this->getCommitByShaHash($environment, $commit->parents[$parent - 1]);
            } else {
                return false;
            }
        }

        return $commit;
    }

    /**
     * Get a specific commit from the API.
     *
     * @param Environment $environment
     * @param string $sha The "pure" commit SHA hash.
     *
     * @return \Platformsh\Client\Model\Git\Commit|false
     */
    private function getCommitByShaHash(Environment $environment, $sha)
    {
        $cacheKey = $environment->project . ':' . $sha;
        $client = $this->api->getHttpClient();
        if ($cached = $this->cache->fetch($cacheKey)) {
            return new Commit($cached['data'], $cached['uri'], $client, true);
        }
        $baseUrl = Project::getProjectBaseFromUrl($environment->getUri()) . '/git/commits';

        $commit = Commit::get($sha, $baseUrl, $client);
        if ($commit === false) {
            return false;
        }

        $data = $commit->getData();

        // No need to cache API metadata.
        if (isset($data['_links']['self']['meta'])) {
            unset($data['_links']['self']['meta']);
        }

        $this->cache->save($cacheKey, [
            'data' => $data,
            'uri' => $baseUrl,
        ], 0);

        return $commit;
    }

    /**
     * Normalize a commit SHA for API and caching purposes.
     *
     * @param \Platformsh\Client\Model\Environment $environment
     * @param string|null                          $sha
     *
     * @return string|null
     */
    private function normalizeSha(Environment $environment, $sha = null)
    {
        if ($environment->head_commit === null) {
            return null;
        }
        if ($sha === null) {
            return $environment->head_commit;
        }
        if (strpos($sha, 'HEAD') === 0) {
            $sha = $environment->head_commit . substr($sha, 4);
        }

        return $sha;
    }

    /**
     * Read a file in the environment, using the Git Data API.
     *
     * @param string      $filename
     * @param Environment $environment
     * @param string|null $commitSha
     *
     * @throws \RuntimeException on error.
     *
     * @return string|false
     *   The raw contents of the file, or false if the file is not found.
     */
    public function readFile($filename, Environment $environment, $commitSha = null)
    {
        $commitSha = $this->normalizeSha($environment, $commitSha);
        $cacheKey = implode(':', ['raw', $environment->project, $filename, $commitSha]);
        $data = $this->cache->fetch($cacheKey);
        if (!is_array($data)) {
            // Find the file.
            if (($tree = $this->getTree($environment, dirname($filename), $commitSha))
                && ($blob = $tree->getBlob(basename($filename)))) {
                $raw = $blob->getRawContent();
            } else {
                $raw = false;
            }
            $data = ['raw' => $raw];
            // Skip caching if the file is bigger than 100 KiB.
            if ($raw === false || strlen($raw) <= 102400) {
                $this->cache->save($cacheKey, $data);
            }
        }

        return $data['raw'];
    }

    /**
     * Get a Git Tree object (a repository directory) for an environment.
     *
     * @param Environment $environment
     * @param string      $path
     * @param string|null $commitSha
     *
     * @return Tree|false
     */
    public function getTree(Environment $environment, $path = '.', $commitSha = null)
    {
        $normalizedSha = $this->normalizeSha($environment, $commitSha);
        $cacheKey = implode(':', ['tree', $environment->project, $path, $normalizedSha]);
        $data = $this->cache->fetch($cacheKey);
        if (!is_array($data)) {
            if (!$commit = $this->getCommit($environment, $normalizedSha)) {
                throw new \InvalidArgumentException(sprintf(
                    'Commit not found: %s',
                    $commitSha
                ));
            }
            if (!$rootTree = $commit->getTree()) {
                // This is unlikely to happen.
                throw new \RuntimeException('Failed to get tree for commit: ' . $commit->id);
            }
            $tree = $rootTree->getTree($path);
            $this->cache->save($cacheKey, [
                'tree' => $tree ? $tree->getData() : null,
                'uri' => $tree ? $tree->getUri() : null,
            ]);
        } elseif (empty($data['tree'])) {
            return false;
        } else {
            $tree = new Tree($data['tree'], $data['uri'], $this->api->getHttpClient(), true);
        }

        return $tree;
    }
}
