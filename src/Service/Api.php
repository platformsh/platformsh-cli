<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;
use GuzzleHttp\ClientInterface;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Session\KeychainStorage;
use Platformsh\Cli\Util\NestedArrayUtil;
use Platformsh\Client\Connection\Connector;
use Platformsh\Client\Model\ApiResourceBase;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\ProjectAccess;
use Platformsh\Client\PlatformClient;
use Platformsh\Client\Session\Session;
use Platformsh\Client\Session\Storage\File;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Decorates the PlatformClient API client to provide aggressive caching.
 */
class Api
{
    /** @var Config */
    protected $config;

    /** @var \Doctrine\Common\Cache\CacheProvider */
    protected $cache;

    /** @var EventDispatcherInterface */
    public $dispatcher;

    /** @var string */
    protected $sessionId = 'default';

    /** @var string|null */
    protected $apiToken;

    /** @var string */
    protected $apiTokenType = 'exchange';

    /** @var PlatformClient */
    protected static $client;

    /** @var Environment[] */
    protected static $environmentsCache = [];

    /** @var bool */
    protected static $environmentsCacheRefreshed = false;

    /** @var \Platformsh\Client\Model\Account[] */
    protected static $accountsCache = [];

    /** @var \Platformsh\Client\Model\ProjectAccess[] */
    protected static $projectAccessesCache = [];

    /** @var array */
    protected static $notFound = [];

    /** @var \Platformsh\Client\Session\Storage\SessionStorageInterface|null */
    protected $sessionStorage;

    public function __construct(
        ?Config $config = null,
        ?CacheProvider $cache = null
    ) {
        $this->config = $config ?: new Config();
        $this->dispatcher = new EventDispatcher();
        $this->cache = $cache ?: CacheFactory::createCacheProvider($this->config);

        $this->sessionId = $this->config->get('api.session_id') ?: 'default';
        if ($this->sessionId === 'api-token') {
            throw new \InvalidArgumentException('Invalid session ID: ' . $this->sessionId);
        }

        if (!isset($this->apiToken)) {
            // Exchangeable API tokens: a token which is exchanged for a
            // temporary access token.
            if ($this->config->has('api.token_file')) {
                $this->apiToken = $this->loadTokenFromFile($this->config->get('api.token_file'));
                $this->apiTokenType = 'exchange';
                $this->sessionId = 'api-token';
            } elseif ($this->config->has('api.token')) {
                $this->apiToken = $this->config->get('api.token');
                $this->apiTokenType = 'exchange';
                $this->sessionId = 'api-token';
            } elseif ($this->config->has('api.access_token_file') || $this->config->has('api.access_token')) {
                // Permanent, personal access token (deprecated) - an OAuth 2.0
                // bearer token which is used directly in API requests.
                @trigger_error('This type of API token (a permanent access token) is deprecated. Please generate a new API token when possible.', E_USER_DEPRECATED);
                if ($this->config->has('api.access_token_file')) {
                    $this->apiToken = $this->loadTokenFromFile($this->config->get('api.access_token_file'));
                } else {
                    $this->apiToken = $this->config->get('api.access_token');
                }
                $this->apiTokenType = 'access';
            }
        }
    }

    /**
     * Sets up listeners (called by the DI container).
     *
     * @required
     *
     * @param \Platformsh\Cli\Service\AutoLoginListener $autoLoginListener
     * @param \Platformsh\Cli\Service\DrushAliasUpdater $drushAliasUpdater
     */
    public function injectListeners(
        AutoLoginListener $autoLoginListener,
        DrushAliasUpdater $drushAliasUpdater
    ): void
    {
        $this->dispatcher->addListener(
            'login.required',
            [$autoLoginListener, 'onLoginRequired']
        );
        $this->dispatcher->addListener(
            'environments.changed',
            [$drushAliasUpdater, 'onEnvironmentsChanged']
        );
    }

    /**
     * Get the cache object.
     *
     * @return \Doctrine\Common\Cache\CacheProvider
     */
    public function getCache(): CacheProvider
    {
        return $this->cache;
    }

    /**
     * Load an API token from a file.
     *
     * @param string $filename
     *   A filename, either relative to the user config directory, or absolute.
     *
     * @return string
     */
    protected function loadTokenFromFile(string $filename): string
    {
        if (strpos($filename, '/') !== 0 && strpos($filename, '\\') !== 0) {
            $filename = $this->config->getUserConfigDir() . '/' . $filename;
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            throw new \RuntimeException('Failed to read file: ' . $filename);
        }

        return trim($content);
    }

    /**
     * Returns whether the CLI is authenticating using an API token.
     *
     * @return bool
     */
    public function hasApiToken(): bool
    {
        return isset($this->apiToken);
    }

    /**
     * Get an HTTP User Agent string representing this application.
     *
     * @return string
     */
    protected function getUserAgent(): string
    {
        return sprintf(
            '%s/%s (%s; %s; PHP %s)',
            str_replace(' ', '-', $this->config->get('application.name')),
            $this->config->getVersion(),
            php_uname('s'),
            php_uname('r'),
            PHP_VERSION
        );
    }

    /**
     * Get the API client object.
     *
     * @param bool $autoLogin Whether to log in, if the client is not already
     *                        authenticated (default: true).
     * @param bool $reset     Whether to re-initialize the client.
     *
     * @return PlatformClient
     */
    public function getClient(bool $autoLogin = true, bool $reset = false): PlatformClient
    {
        if (!isset(self::$client) || $reset) {
            $connectorOptions = [];
            $connectorOptions['accounts'] = rtrim($this->config->get('api.accounts_api_url'), '/') . '/';
            $connectorOptions['verify'] = !$this->config->get('api.skip_ssl');
            $connectorOptions['debug'] = $this->config->get('api.debug') ? STDERR : false;
            $connectorOptions['client_id'] = $this->config->get('api.oauth2_client_id');
            $connectorOptions['user_agent'] = $this->getUserAgent();
            $connectorOptions['api_token'] = $this->apiToken;
            $connectorOptions['api_token_type'] = $this->apiTokenType;

            // Proxy support with the http_proxy or https_proxy environment
            // variables.
            if (PHP_SAPI === 'cli') {
                $proxies = [];
                foreach (['https', 'http'] as $scheme) {
                    $proxies[$scheme] = str_replace('http://', 'tcp://', getenv($scheme . '_proxy'));
                }
                $proxies = array_filter($proxies);
                if (count($proxies)) {
                    $connectorOptions['proxy'] = count($proxies) == 1 ? reset($proxies) : $proxies;
                }
            }

            // Set up a persistent session to store OAuth2 tokens. By default,
            // this will be stored in a JSON file:
            // $HOME/.platformsh/.session/sess-cli-default/sess-cli-default.json
            $session = new Session('cli-' . $this->sessionId);

            $this->sessionStorage = KeychainStorage::isSupported()
                && $this->config->isExperimentEnabled('use_keychain')
                ? new KeychainStorage($this->config->get('application.name'))
                : new File($this->config->getSessionDir());
            $session->setStorage($this->sessionStorage);

            $connector = new Connector($connectorOptions, $session);

            self::$client = new PlatformClient($connector);

            if ($autoLogin && !$connector->isLoggedIn()) {
                $this->dispatcher->dispatch('login.required');
            }
        }

        return self::$client;
    }

    /**
     * Return the user's projects.
     *
     * @param bool|null $refresh Whether to refresh the list of projects.
     *
     * @return Project[] The user's projects, keyed by project ID.
     */
    public function getProjects(?bool $refresh = null)
    {
        $cacheKey = sprintf('%s:projects', $this->sessionId);

        /** @var Project[] $projects */
        $projects = [];

        $cached = $this->cache->fetch($cacheKey);

        if ($refresh === false && !$cached) {
            return [];
        } elseif ($refresh || !$cached) {
            foreach ($this->getClient()->getProjects() as $project) {
                $projects[$project->id] = $project;
            }

            $cachedProjects = [];
            foreach ($projects as $id => $project) {
                $cachedProjects[$id] = $project->getData();
                $cachedProjects[$id]['_endpoint'] = $project->getUri(true);
            }

            $this->cache->save($cacheKey, $cachedProjects, $this->config->get('api.projects_ttl'));
        } else {
            $guzzleClient = $this->getHttpClient();
            foreach ((array) $cached as $id => $data) {
                $projects[$id] = new Project($data, $data['_endpoint'], $guzzleClient);
            }
        }

        return $projects;
    }

    /**
     * Return the user's project with the given ID.
     *
     * @param string      $id      The project ID.
     * @param string|null $host    The project's hostname.
     * @param bool|null   $refresh Whether to bypass the cache.
     *
     * @return Project|false
     */
    public function getProject(string $id, ?string $host = null, ?bool $refresh = null)
    {
        // Find the project in the user's main project list. This uses a
        // separate cache.
        $projects = $this->getProjects($refresh);
        if (isset($projects[$id])) {
            if ($host === null || stripos(parse_url($projects[$id]->getUri(), PHP_URL_HOST), $host) !== false) {
                return $projects[$id];
            }
        }

        // Find the project directly.
        $cacheKey = sprintf('%s:project:%s:%s', $this->sessionId, $id, $host);
        $cached = $this->cache->fetch($cacheKey);
        if ($refresh || !$cached) {
            $scheme = 'https';
            if ($host !== null && (($pos = strpos($host, '//')) !== false)) {
                $scheme = parse_url($host, PHP_URL_SCHEME);
                $host = substr($host, $pos + 2);
            }
            $project = $this->getClient()
                ->getProject($id, $host, $scheme !== 'http');
            if ($project) {
                $toCache = $project->getData();
                $toCache['_endpoint'] = $project->getUri(true);
                $this->cache->save($cacheKey, $toCache, $this->config->get('api.projects_ttl'));
            }
        } else {
            $guzzleClient = $this->getHttpClient();
            $baseUrl = $cached['_endpoint'];
            unset($cached['_endpoint']);
            $project = new Project($cached, $baseUrl, $guzzleClient);
        }

        return $project;
    }

    /**
     * Return the user's environments.
     *
     * @param Project   $project The project.
     * @param bool|null $refresh Whether to refresh the list.
     * @param bool      $events  Whether to update Drush aliases if the list changes.
     *
     * @return Environment[] The user's environments, keyed by ID.
     */
    public function getEnvironments(Project $project, ?bool $refresh = null, ?bool $events = true): array
    {
        $projectId = $project->id;

        if (!$refresh && isset(self::$environmentsCache[$projectId])) {
            return self::$environmentsCache[$projectId];
        }

        $cacheKey = 'environments:' . $projectId;
        $cached = $this->cache->fetch($cacheKey);

        if ($refresh === false && !$cached) {
            return [];
        } elseif ($refresh || !$cached) {
            $environments = [];
            $toCache = [];
            foreach ($project->getEnvironments() as $environment) {
                $environments[$environment->id] = $environment;
                $toCache[$environment->id] = $environment->getData();
            }

            // Dispatch an event if the list of environments has changed.
            if ($events && (!$cached || array_diff_key($environments, $cached))) {
                $this->dispatcher->dispatch(
                    'environments.changed',
                    new EnvironmentsChangedEvent($project, $environments)
                );
            }

            $this->cache->save($cacheKey, $toCache, $this->config->get('api.environments_ttl'));
            self::$environmentsCacheRefreshed = true;
        } else {
            $environments = [];
            $endpoint = $project->getUri();
            $guzzleClient = $this->getHttpClient();
            foreach ((array) $cached as $id => $data) {
                $environments[$id] = new Environment($data, $endpoint, $guzzleClient, true);
            }
        }

        self::$environmentsCache[$projectId] = $environments;

        return $environments;
    }

    /**
     * Get a single environment.
     *
     * @param string  $id          The environment ID to load.
     * @param Project $project     The project.
     * @param bool|null $refresh   Whether to refresh the list of environments.
     * @param bool $tryMachineName Whether to retry, treating the ID as a
     *                             machine name.
     *
     * @return Environment|false The environment, or false if not found.
     */
    public function getEnvironment(string $id, Project $project, ?bool $refresh = null, ?bool $tryMachineName = false)
    {
        // Statically cache not found environments.
        $cacheKey = $project->id . ':' . $id . ($tryMachineName ? ':mn' : '');
        if (!$refresh && isset(self::$notFound[$cacheKey])) {
            return false;
        }

        $environments = $this->getEnvironments($project, $refresh);

        // Look for the environment by ID.
        if (isset($environments[$id])) {
            return $environments[$id];
        }

        // Retry directly if the environment was not found in the cache.
        if ($refresh === null) {
            if ($environment = $project->getEnvironment($id)) {
                // If the environment was found directly, the cache must be out
                // of date.
                $this->clearEnvironmentsCache($project->id);
                return $environment;
            }
        }

        // Look for the environment by machine name.
        if ($tryMachineName) {
            foreach ($environments as $environment) {
                if ($environment->machine_name === $id) {
                    return $environment;
                }
            }
        }

        self::$notFound[$cacheKey] = true;

        return false;
    }

    /**
     * Get the current user's account info.
     *
     * @param bool $reset
     *
     * @return array
     *   An array containing at least 'username', 'id', 'mail', and
     *   'display_name'.
     */
    public function getMyAccount(bool $reset = false): array
    {
        $cacheKey = sprintf('%s:my-account', $this->sessionId);
        if ($reset || !($info = $this->cache->fetch($cacheKey))) {
            $info = $this->getClient()->getAccountInfo($reset);
            $this->cache->save($cacheKey, $info, $this->config->get('api.users_ttl'));
        }

        return $info;
    }

    /**
     * Get a user's account info.
     *
     * @param ProjectAccess $access
     * @param bool          $reset
     *
     * @return array
     *   An array containing 'email' and 'display_name'.
     */
    public function getAccount(ProjectAccess $access, bool $reset = false): array
    {
        if (isset(self::$accountsCache[$access->id]) && !$reset) {
            return self::$accountsCache[$access->id];
        }

        $cacheKey = 'account:' . $access->id;
        if ($reset || !($details = $this->cache->fetch($cacheKey))) {
            $data = $access->getData();
            // Use embedded user information if possible.
            if (isset($data['_embedded']['users'][0]) && count($data['_embedded']['users']) === 1) {
                $details = $data['_embedded']['users'][0];
                self::$accountsCache[$access->id] = $details;
            } else {
                $details = $access->getAccount()->getProperties();
                $this->cache->save($cacheKey, $details, $this->config->get('api.users_ttl'));
                self::$accountsCache[$access->id] = $details;
            }
        }

        return $details;
    }

    /**
     * Clear the environments cache for a project.
     *
     * Use this after creating/deleting/updating environment(s).
     *
     * @param string $projectId
     */
    public function clearEnvironmentsCache(string $projectId): void
    {
        $this->cache->delete('environments:' . $projectId);
        unset(self::$environmentsCache[$projectId]);
        foreach (array_keys(self::$notFound) as $key) {
            if (strpos($key, $projectId . ':') === 0) {
                unset(self::$notFound[$key]);
            }
        }
    }

    /**
     * Clear the projects cache.
     */
    public function clearProjectsCache(): void
    {
        $this->cache->delete(sprintf('%s:projects', $this->sessionId));
        $this->cache->delete(sprintf('%s:my-account', $this->sessionId));
    }

    /**
     * Sort resources.
     *
     * @param ApiResourceBase[] &$resources
     * @param string        $propertyPath
     *
     * @return ApiResourceBase[]
     */
    public static function sortResources(array &$resources, string $propertyPath): array
    {
        uasort($resources, function (ApiResourceBase $a, ApiResourceBase $b) use ($propertyPath) {
            $valueA = static::getNestedProperty($a, $propertyPath, false);
            $valueB = static::getNestedProperty($b, $propertyPath, false);

            switch (gettype($valueA)) {
                case 'string':
                    return strcasecmp($valueA, $valueB);

                case 'integer':
                case 'double':
                case 'boolean':
                    return $valueA - $valueB;
            }

            return 0;
        });

        return $resources;
    }

    /**
     * Get a nested property of a resource, via a dot-separated string path.
     *
     * @param ApiResourceBase $resource
     * @param string          $propertyPath
     * @param bool            $lazyLoad
     *
     * @throws \InvalidArgumentException if the property is not found.
     *
     * @return mixed
     */
    public static function getNestedProperty(ApiResourceBase $resource, string $propertyPath, bool $lazyLoad = true)
    {
        if (!strpos($propertyPath, '.')) {
            return $resource->getProperty($propertyPath, true, $lazyLoad);
        }

        $parents = explode('.', $propertyPath);
        $propertyName = array_shift($parents);
        $property = $resource->getProperty($propertyName, true, $lazyLoad);
        if (!is_array($property)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid path "%s": the property "%s" is not an array.',
                $propertyPath,
                $propertyName
            ));
        }
        $value = NestedArrayUtil::getNestedArrayValue($property, $parents, $keyExists);
        if (!$keyExists) {
            throw new \InvalidArgumentException('Property not found: ' . $propertyPath);
        }

        return $value;
    }

    /**
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        return $this->getClient(false)->getConnector()->isLoggedIn();
    }

    /**
     * Load project users ("project access" records).
     *
     * @param \Platformsh\Client\Model\Project $project
     * @param bool                             $reset
     *
     * @return ProjectAccess[]
     */
    public function getProjectAccesses(Project $project, bool $reset = false): array
    {
        if ($reset || !isset(self::$projectAccessesCache[$project->id])) {
            self::$projectAccessesCache[$project->id] = $project->getUsers();
        }

        return self::$projectAccessesCache[$project->id];
    }

    /**
     * Load a project user ("project access" record) by email address.
     *
     * @param Project $project
     * @param string  $email
     * @param bool    $reset
     *
     * @return ProjectAccess|false
     */
    public function loadProjectAccessByEmail(Project $project, string $email, bool $reset = false)
    {
        foreach ($this->getProjectAccesses($project, $reset) as $user) {
            $account = $this->getAccount($user);
            if ($account['email'] === $email || strtolower($account['email']) === strtolower($email)) {
                return $user;
            }
        }

        return false;
    }

    /**
     * Returns a project label.
     *
     * @param Project      $project
     * @param string|false $tag
     *
     * @return string
     */
    public function getProjectLabel(Project $project, $tag = 'info'): string
    {
        $title = $project->title;
        $pattern = strlen($title) > 0 ? '%2$s (%3$s)' : '%3$s';
        if ($tag !== false) {
            $pattern = strlen($title) > 0 ? '<%1$s>%2$s</%1$s> (%3$s)' : '<%1$s>%3$s</%1$s>';
        }

        return sprintf($pattern, $tag, $title, $project->id);
    }

    /**
     * Returns an environment label.
     *
     * @param Environment  $environment
     * @param string|false $tag
     *
     * @return string
     */
    public function getEnvironmentLabel(Environment $environment, $tag = 'info')
    {
        $id = $environment->id;
        $title = $environment->title;
        $use_title = strlen($title) > 0 && $title !== $id;
        $pattern = $use_title ? '%2$s (%3$s)' : '%3$s';
        if ($tag !== false) {
            $pattern = $use_title ? '<%1$s>%2$s</%1$s> (%3$s)' : '<%1$s>%3$s</%1$s>';
        }

        return sprintf($pattern, $tag, $title, $id);
    }

    /**
     * Get a resource, matching on the beginning of the ID.
     *
     * @param string            $id
     * @param ApiResourceBase[] $resources
     * @param string            $name
     *
     * @return ApiResourceBase
     *   The resource, if one (and only one) is matched.
     */
    public function matchPartialId(string $id, array $resources, string $name = 'Resource'): ApiResourceBase
    {
        $matched = array_filter($resources, function (ApiResourceBase $resource) use ($id) {
            return strpos($resource->getProperty('id'), $id) === 0;
        });

        if (count($matched) > 1) {
            $matchedIds = array_map(function (ApiResourceBase $resource) {
                return $resource->getProperty('id');
            }, $matched);
            throw new \InvalidArgumentException(sprintf(
                'The partial ID "<error>%s</error>" is ambiguous; it matches the following %s IDs: %s',
                $id,
                strtolower($name),
                "\n  " . implode("\n  ", $matchedIds)
            ));
        } elseif (count($matched) === 0) {
            throw new \InvalidArgumentException(sprintf('%s not found: "<error>%s</error>"', $name, $id));
        }

        return reset($matched);
    }

    /**
     * Returns the OAuth 2 access token.
     *
     * @return string
     */
    public function getAccessToken(): string
    {
        $session = $this->getClient()->getConnector()->getSession();
        $token = (string) $session->get('accessToken');
        $expires = $session->get('expires');
        if (!$token || $expires < time()) {
            // Force a connection to the API to ensure there is an access token.
            $this->getMyAccount(true);
            if (!$token = (string) $session->get('accessToken')) {
                throw new \RuntimeException('No access token found');
            }
        }

        return $token;
    }

    /**
     * Sort URLs, preferring shorter ones with HTTPS.
     *
     * @param string $a
     * @param string $b
     *
     * @return int
    */
    public function urlSort(string $a, string $b): int
    {
        $result = 0;
        foreach ([$a, $b] as $key => $url) {
            if (parse_url($url, PHP_URL_SCHEME) === 'https') {
                $result += $key === 0 ? -2 : 2;
            }
        }
        $result += strlen($a) <= strlen($b) ? -1 : 1;

        return $result;
    }

    /**
     * Get the authenticated HTTP client.
     *
     * @return ClientInterface
     */
    public function getHttpClient(): ClientInterface
    {
        return $this->getClient(false)->getConnector()->getClient();
    }

    /**
     * Delete all keychain keys.
     */
    public function deleteFromKeychain(): void
    {
        if ($this->sessionStorage instanceof KeychainStorage) {
            $this->sessionStorage->deleteAll();
        }
    }

    /**
     * Get the current deployment for an environment.
     *
     * @param Environment $environment
     * @param bool        $refresh
     *
     * @return EnvironmentDeployment
     */
    public function getCurrentDeployment(Environment $environment, bool $refresh = false): EnvironmentDeployment
    {
        $cacheKey = implode(':', ['current-deployment', $environment->project, $environment->id, $environment->head_commit]);
        $data = $this->cache->fetch($cacheKey);
        if ($data === false || $refresh) {
            $deployment = $environment->getCurrentDeployment();
            $data = $deployment->getData();
            $data['_uri'] = $deployment->getUri();
            $this->cache->save($cacheKey, $data);
        } else {
            $deployment = new EnvironmentDeployment($data, $data['_uri'], $this->getHttpClient(), true);
        }

        return $deployment;
    }

    /**
     * Returns whether the current deployment for an environment is already cached.
     *
     * @param Environment $environment
     *
     * @return bool
     */
    public function hasCachedCurrentDeployment(Environment $environment)
    {
        $cacheKey = implode(':', ['current-deployment', $environment->project, $environment->id, $environment->head_commit]);

        return $this->cache->contains($cacheKey);
    }

    /**
     * Get the default environment in a list.
     *
     * @param array $environments An array of environments, keyed by ID.
     *
     * @return string|null
     */
    public function getDefaultEnvironmentId(array $environments): ?string
    {
        // If there is only one environment, use that.
        if (count($environments) <= 1) {
            $environment = reset($environments);

            return $environment ? $environment->id : null;
        }

        // Check if there is only one "main" environment.
        $main = array_filter($environments, function (Environment $environment) {
            return $environment->is_main;
        });
        if (count($main) === 1) {
            $environment = reset($main);

            return $environment ? $environment->id : null;
        }

        // Check if there is a "master" environment.
        if (isset($environments['master'])) {
            return 'master';
        }

        return null;
    }

    /**
     * Get the preferred site URL for an environment and app.
     *
     * @param \Platformsh\Client\Model\Environment                           $environment
     * @param string                                                         $appName
     * @param \Platformsh\Client\Model\Deployment\EnvironmentDeployment|null $deployment
     *
     * @return string|null
     */
    public function getSiteUrl(Environment $environment, string $appName, ?EnvironmentDeployment $deployment = null): ?string
    {
        $deployment = $deployment ?: $this->getCurrentDeployment($environment);
        $routes = Route::fromDeploymentApi($deployment->routes);
        $appUrls = [];
        foreach ($routes as $route) {
            if ($route->type === 'upstream' && $route->getUpstreamName() === $appName) {
                // Use the primary route, if it matches this app.
                if ($route->primary) {
                    return $route->url;
                }

                $appUrls[] = $route->url;
            }
        }
        usort($appUrls, [$this, 'urlSort']);
        $siteUrl = reset($appUrls);
        if ($siteUrl) {
            return $siteUrl;
        }
        if ($environment->hasLink('public-url')) {
            return $environment->getLink('public-url');
        }

        return null;
    }

    /**
     * Get help on how to use API tokens.
     *
     * @param string $tag
     *
     * @return string|null
     */
    public function getApiTokenHelp(string $tag = 'info'): ?string
    {
        if ($this->config->has('service.api_token_help_url')) {
            return "To authenticate non-interactively using an API token, see:\n    <$tag>"
                . $this->config->get('service.api_token_help_url') . "</$tag>";
        }

        return null;
    }
}
