<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use GuzzleHttp\Exception\GuzzleException;
use function GuzzleHttp\json_decode;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Client\Model\Integration;
use Platformsh\Client\Model\Project;
use Platformsh\ConsoleForm\Field\ArrayField;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\EmailAddressField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Field\UrlField;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationService
{
    /** @var \Platformsh\ConsoleForm\Form */
    private $form;

    private $api;
    private $config;
    private $formatter;
    private $stdErr;
    private $table;

    /** @var array */
    private $bitbucketAccessTokens = [];

    public function __construct(
        Api $api,
        Config $config,
        OutputInterface $output,
        PropertyFormatter $formatter,
        Table $table
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->formatter = $formatter;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->table = $table;
    }

    /**
     * @return Form
     */
    public function getForm()
    {
        if (!isset($this->form)) {
            $this->form = Form::fromArray($this->getFields());
        }

        return $this->form;
    }

    /**
     * Performs extra logic on values after the form is complete.
     *
     * @param array            $values
     * @param Integration|null $integration
     *
     * @return array
     */
    public function postProcessValues(array $values, Integration $integration = null)
    {
        // Find the integration type.
        $type = isset($values['type'])
            ? $values['type']
            : ($integration !== null ? $integration->type : null);

        // Process Bitbucket Server values.
        if ($type === 'bitbucket_server') {
            // Translate base_url into url.
            if (isset($values['base_url'])) {
                $values['url'] = $values['base_url'];
                unset($values['base_url']);
            }
            // Split bitbucket_server "repository" into project/repository.
            if (isset($values['repository']) && strpos($values['repository'], '/', 1) !== false) {
                list($values['project'], $values['repository']) = explode('/', $values['repository'], 2);
            }
        }

        return $values;
    }

    /**
     * @return Field[]
     */
    private function getFields()
    {
        return [
            'type' => new OptionsField('Integration type', [
                'optionName' => 'type',
                'description' => 'The integration type',
                'questionLine' => '',
                'options' => [
                    'bitbucket',
                    'bitbucket_server',
                    'github',
                    'gitlab',
                    'hipchat',
                    'webhook',
                    'health.email',
                    'health.pagerduty',
                    'health.slack',
                ],
            ]),
            'base_url' => new UrlField('Base URL', [
                'conditions' => ['type' => [
                    'gitlab',
                    'bitbucket_server',
                ]],
                'description' => 'The base URL of the server installation',
            ]),
            'username' => new Field('Username', [
                'conditions' =>  ['type' => [
                    'bitbucket_server',
                ]],
                'description' => 'The Bitbucket Server username',
            ]),
            'token' => new Field('Token', [
                'conditions' => ['type' => [
                    'github',
                    'gitlab',
                    'hipchat',
                    'health.slack',
                    'bitbucket_server',
                ]],
                'description' => 'An access token for the integration',
            ]),
            'key' => new Field('OAuth consumer key', [
                'optionName' => 'key',
                'conditions' => ['type' => [
                    'bitbucket',
                ]],
                'description' => 'A Bitbucket OAuth consumer key',
                'valueKeys' => ['app_credentials', 'key'],
            ]),
            'secret' => new Field('OAuth consumer secret', [
                'optionName' => 'secret',
                'conditions' => ['type' => [
                    'bitbucket',
                ]],
                'description' => 'A Bitbucket OAuth consumer secret',
                'valueKeys' => ['app_credentials', 'secret'],
            ]),
            'project' => new Field('Project', [
                'optionName' => 'server-project',
                'conditions' => ['type' => [
                    'gitlab',
                ]],
                'description' => 'The project (e.g. \'namespace/repo\')',
                'validator' => function ($string) {
                    return strpos($string, '/', 1) !== false;
                },
            ]),
            'repository' => new Field('Repository', [
                'conditions' => ['type' => [
                    'bitbucket',
                    'bitbucket_server',
                    'github',
                ]],
                'description' => 'The repository to track (e.g. \'foo/bar\')',
                'questionLine' => 'The repository (e.g. \'foo/bar\')',
                'validator' => function ($string) {
                    return substr_count($string, '/', 1) === 1;
                },
                'normalizer' => function ($string) {
                    if (preg_match('#^https?://#', $string)) {
                        return parse_url($string, PHP_URL_PATH);
                    }

                    return $string;
                },
            ]),
            'build_merge_requests' => new BooleanField('Build merge requests', [
                'conditions' => ['type' => [
                    'gitlab',
                ]],
                'description' => 'GitLab: build merge requests as environments',
                'questionLine' => 'Build every merge request as an environment',
            ]),
            'build_pull_requests' => new BooleanField('Build pull requests', [
                'conditions' => ['type' => [
                    'bitbucket',
                    'bitbucket_server',
                    'github',
                ]],
                'description' => 'Build every pull request as an environment',
            ]),
            'build_pull_requests_post_merge' => new BooleanField('Build pull requests post-merge', [
              'conditions' => [
                'type' => [
                  'github',
                ],
                'build_pull_requests' => true,
              ],
              'default' => false,
              'description' => 'Build pull requests based on their post-merge state',
            ]),
            'merge_requests_clone_parent_data' => new BooleanField('Clone data for merge requests', [
                'optionName' => 'merge-requests-clone-parent-data',
                'conditions' => [
                    'type' => [
                        'gitlab',
                    ],
                    'build_merge_requests' => true,
                ],
                'description' => 'GitLab: clone data for merge requests',
                'questionLine' => "Clone the parent environment's data for merge requests",
            ]),
            'pull_requests_clone_parent_data' => new BooleanField('Clone data for pull requests', [
                'optionName' => 'pull-requests-clone-parent-data',
                'conditions' => [
                    'type' => [
                        'github',
                        'bitbucket_server',
                    ],
                    'build_pull_requests' => true,
                ],
                'description' => "Clone the parent environment's data for pull requests",
            ]),
            'resync_pull_requests' => new BooleanField('Re-sync pull requests', [
                'optionName' => 'resync-pull-requests',
                'conditions' => [
                    'type' => [
                        'bitbucket',
                    ],
                    'build_pull_requests' => true,
                ],
                'default' => false,
                'description' => "Re-sync pull request environment data on every build",
            ]),
            'fetch_branches' => new BooleanField('Fetch branches', [
                'conditions' => ['type' => [
                    'bitbucket',
                    'bitbucket_server',
                    'github',
                    'gitlab',
                ]],
                'description' => 'Fetch all branches from the remote (as inactive environments)',
            ]),
            'prune_branches' => new BooleanField('Prune branches', [
                'conditions' => [
                    'type' => [
                        'bitbucket',
                        'bitbucket_server',
                        'github',
                        'gitlab',
                    ],
                    'fetch_branches' => true,
                ],
                'description' => 'Delete branches that do not exist on the remote',
            ]),
            'room' => new Field('HipChat room ID', [
                'conditions' => ['type' => [
                    'hipchat',
                ]],
                'validator' => 'is_numeric',
                'optionName' => 'room',
                'questionLine' => 'What is the HipChat room ID (numeric)?',
            ]),
            'url' => new UrlField('URL', [
                'conditions' => ['type' => [
                    'webhook',
                ]],
                'description' => 'Generic webhook: a URL to receive JSON data',
                'questionLine' => 'What is the webhook URL (to which JSON data will be posted)?',
            ]),
            'events' => new ArrayField('Events to report', [
                'conditions' => ['type' => [
                    'hipchat',
                    'webhook',
                ]],
                'default' => ['*'],
                'description' => 'A list of events to report, e.g. environment.push',
                'optionName' => 'events',
            ]),
            'states' => new ArrayField('States to report', [
                'conditions' => ['type' => [
                    'hipchat',
                    'webhook',
                ]],
                'default' => ['complete'],
                'description' => 'A list of states to report, e.g. pending, in_progress, complete',
                'optionName' => 'states',
            ]),
            'environments' => new ArrayField('Included environments', [
                'optionName' => 'environments',
                'conditions' => ['type' => [
                    'webhook',
                    'hipchat',
                ]],
                'default' => ['*'],
                'description' => 'The environment IDs to include',
            ]),
            'excluded_environments' => new ArrayField('Excluded environments', [
                'conditions' => ['type' => [
                    'webhook',
                    'hipchat',
                ]],
                'default' => [],
                'description' => 'The environment IDs to exclude',
                'required' => false,
            ]),
            'from_address' => new EmailAddressField('From address', [
                'conditions' => ['type' => [
                    'health.email',
                ]],
                'description' => 'The From address for alert emails',
                'default' => $this->config->getWithDefault('service.default_from_address', null),
            ]),
            'recipients' => new ArrayField('Recipients', [
                'conditions' => ['type' => [
                    'health.email',
                ]],
                'description' => 'The recipient email address(es)',
                'validator' => function ($emails) {
                    $invalid = array_filter($emails, function ($email) {
                        return !filter_var($email, FILTER_VALIDATE_EMAIL);
                    });
                    if (count($invalid)) {
                        return sprintf('Invalid email address(es): %s', implode(', ', $invalid));
                    }

                    return true;
                },
            ]),
            'channel' => new Field('Channel', [
                'conditions' => ['type' => [
                    'health.slack',
                ]],
                'description' => 'The Slack channel',
            ]),
            'routing_key' => new Field('Routing key', [
                'conditions' => ['type' => [
                    'health.pagerduty',
                ]],
                'description' => 'The PagerDuty routing key',
            ]),
        ];
    }

    /**
     * @param Integration $integration
     * @param Project     $project
     */
    public function ensureHooks(Integration $integration, Project $project)
    {
        if ($integration->type === 'github') {
            $hooksApiUrl = sprintf('https://api.github.com/repos/%s/hooks', $integration->getProperty('repository'));
            $requestOptions = [
                'auth' => false,
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                    'Authorization' => 'token ' . $integration->getProperty('token'),
                ],
            ];
            $payload = [
                'name' => 'web',
                'config' => [
                    'url' => $integration->getLink('#hook'),
                    'content_type' => 'json',
                ],
                'events' => ['*'],
                'active' => true,
            ];
            $repoName = $integration->getProperty('repository');
        } elseif ($integration->type === 'gitlab') {
            $baseUrl = rtrim($integration->getProperty('base_url'), '/');
            $hooksApiUrl = sprintf('%s/api/v4/projects/%s/hooks', $baseUrl, rawurlencode($integration->getProperty('project')));
            $requestOptions = [
                'auth' => false,
                'headers' => [
                    'Accept' => 'application/json',
                    'Private-Token' => $integration->getProperty('token'),
                ],
            ];
            $payload = [
                'url' => $integration->getLink('#hook'),
                'push_events' => true,
                'merge_requests_events' => true,
            ];
            $repoName = $baseUrl . '/' . $integration->getProperty('project');
        } elseif ($integration->type === 'bitbucket' && $integration->hasProperty('app_credentials')) {
            $appCredentials = $integration->getProperty('app_credentials');
            $result = $this->validateBitbucketCredentials($appCredentials);
            if ($result !== true) {
                $this->stdErr->writeln($result);

                return;
            }
            $accessToken = $this->getBitbucketAccessToken($appCredentials);
            $hooksApiUrl = sprintf('https://api.bitbucket.org/2.0/repositories/%s/hooks', rawurlencode($integration->getProperty('repository')));
            $requestOptions = [
                'auth' => false,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ];
            $payload = [
                'description' => sprintf(
                    '%s: %s',
                    $this->config->get('service.name'),
                    $project->id
                ),
                'url' => $integration->getLink('#hook'),
                'active' => true,
                'events' => [
                    'pullrequest:created',
                    'pullrequest:updated',
                    'pullrequest:rejected',
                    'pullrequest:fulfilled',
                    'repo:updated',
                    'repo:push',
                ],
            ];
            $repoName = 'https://bitbucket.org/' . $integration->getProperty('repository');
        } else {
            return;
        }

        $client = $this->api->getHttpClient();

        $this->stdErr->writeln(sprintf(
            'Checking webhook configuration on the repository: <info>%s</info>',
            $repoName
        ));

        try {
            $hooks = json_decode($client->request('get', $hooksApiUrl, $requestOptions)->getBody()->getContents(), true);
            $hook = $this->findWebHook($integration, $hooks);
            if (!$hook) {
                $this->stdErr->writeln('  Creating new webhook');
                $client->request('post', $hooksApiUrl, ['json' => $payload] + $requestOptions);
                $this->stdErr->writeln('  Webhook created successfully');
            }
            elseif ($this->hookNeedsUpdate($integration, $hook, $payload)) {
                // The GitLab and Bitbucket APIs require PUT for editing project
                // hooks. The GitHub API requires PATCH.
                $method = $integration->type === 'github' ? 'patch' : 'put';

                // A Bitbucket hook has a 'uuid', others have an 'id'.
                $id = $integration->type === 'bitbucket' ? $hook['uuid'] : $hook['id'];

                $hookApiUrl = $hooksApiUrl . '/' . rawurlencode($id);

                $this->stdErr->writeln('  Updating webhook');
                $client->request($method, $hookApiUrl, ['json' => $payload] + $requestOptions);
                $this->stdErr->writeln('  Webhook updated successfully');
            }
            else {
                $this->stdErr->writeln('  Valid configuration found');
            }
        } catch (GuzzleException $e) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('  <comment>Failed to read or write webhooks:</comment>');
            $this->stdErr->writeln('  ' . $e->getMessage());
            $this->stdErr->writeln(sprintf(
                "\n  Please ensure a webhook exists manually.\n  Hook URL: %s",
                $integration->getLink('#hook')
            ));
        }
    }

    /**
     * Check if a hook needs updating.
     *
     * @param \Platformsh\Client\Model\Integration $integration
     * @param array                                $hook
     * @param array                                $payload
     *
     * @return bool
     */
    private function hookNeedsUpdate(Integration $integration, array $hook, array $payload)
    {
        if ($integration->type === 'bitbucket') {
            foreach ($payload as $item => $value) {
                if (!isset($hook[$item])) {
                    return true;
                }
                if ($item === 'events') {
                    sort($value);
                    sort($hook[$item]);
                    if ($value !== $hook[$item]) {
                        return true;
                    }
                }
                if ($hook[$item] !== $value) {
                    return true;
                }
            }

            return false;
        }

        return $this->arraysDiffer($hook, $payload);
    }

    /**
     * Checks if $array2 has any values missing or different from $array1.
     *
     * Runs recursively for multidimensional arrays.
     *
     * @param array $array1
     * @param array $array2
     *
     * @return bool
     */
    private function arraysDiffer(array $array1, array $array2)
    {
        foreach ($array2 as $property => $value) {
            if (!array_key_exists($property, $array1)) {
                return true;
            }
            if (is_array($value)) {
                if (!is_array($array1[$property])) {
                    return true;
                }
                if ($array1[$property] != $value && $this->arraysDiffer($array1[$property], $value)) {
                    return true;
                }
                continue;
            }
            if ($array1[$property] != $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find if a valid webhook exists in a service's hooks list.
     *
     * @param \Platformsh\Client\Model\Integration $integration
     * @param array                                $jsonResult
     *
     * @return array|false
     */
    private function findWebHook(Integration $integration, array $jsonResult)
    {
        $type = $integration->type;
        $hookUrl = $integration->getLink('#hook');
        if ($integration->type === 'bitbucket') {
            $hooks = $jsonResult['values'];
        } else {
            $hooks = $jsonResult;
        }
        foreach ($hooks as $hook) {
            if ($type === 'github' && $hook['config']['url'] === $hookUrl) {
                return $hook;
            }
            if ($type === 'gitlab' && $hook['url'] === $hookUrl) {
                return $hook;
            }
            if ($type === 'bitbucket' && $hook['url'] === $hookUrl) {
                return $hook;
            }
        }

        return false;
    }

    /**
     * @param \Platformsh\Client\Model\Integration $integration
     */
    public function displayIntegration(Integration $integration)
    {
        $info = [];
        foreach ($integration->getProperties() as $property => $value) {
            $info[$property] = $this->formatter->format($value, $property);
        }
        if ($integration->hasLink('#hook')) {
            $info['hook_url'] = $this->formatter->format($integration->getLink('#hook'));
        }

        $this->table->renderSimple(array_values($info), array_keys($info));
    }

    /**
     * Obtain an OAuth2 token for Bitbucket from the given app credentials.
     *
     * @param array $credentials
     *
     * @return string
     */
    protected function getBitbucketAccessToken(array $credentials)
    {
        if (isset($this->bitbucketAccessTokens[$credentials['key']])) {
            return $this->bitbucketAccessTokens[$credentials['key']];
        }
        try {
            $result = $this->api
                ->getHttpClient()
                ->request('post', 'https://bitbucket.org/site/oauth2/access_token', [
                    'auth' => [$credentials['key'], $credentials['secret']],
                    'body' => [
                        'grant_type' => 'client_credentials',
                    ],
                ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to obtain access token from Bitbucket', 0, $e);
        }

        $data = json_decode($result->getBody()->__toString(), TRUE);
        if (!isset($data['access_token'])) {
            throw new \RuntimeException('Access token not found in Bitbucket response');
        }

        $this->bitbucketAccessTokens[$credentials['key']] = $data['access_token'];

        return $data['access_token'];
    }

    /**
     * Validate Bitbucket credentials.
     *
     * @param array $credentials
     *
     * @return string|TRUE
     */
    public function validateBitbucketCredentials(array $credentials)
    {
        try {
            $this->getBitbucketAccessToken($credentials);
        } catch (\Exception $e) {
            $message = '<error>Invalid Bitbucket credentials</error>';
            if ($e instanceof BadResponseException && $e->getResponse() && $e->getResponse()->getStatusCode() === 400) {
                $message .= "\n" . 'Ensure that the OAuth consumer key and secret are valid.'
                    . "\n" . 'Additionally, ensure that the OAuth consumer has a callback URL set (even just to <comment>http://localhost</comment>).';
            }

            return $message;
        }

        return TRUE;
    }

    /**
     * Lists validation errors found in an integration.
     *
     * @param array                                             $errors
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function listValidationErrors(array $errors, OutputInterface $output)
    {
        if (count($errors) === 1) {
            $this->stdErr->writeln('The following error was found:');
        } else {
            $this->stdErr->writeln(sprintf(
                'The following %d errors were found:',
                count($errors)
            ));
        }

        foreach ($errors as $key => $error) {
            if (is_string($key) && strlen($key)) {
                $output->writeln("$key: $error");
            } else {
                $output->writeln($error);
            }
        }
    }
}
