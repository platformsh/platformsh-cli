<?php
declare(strict_types=1);

namespace Platformsh\Cli\Console;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Exception\ConnectionFailedException;
use Platformsh\Cli\Exception\HttpException;
use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Exception\PermissionDeniedException;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class EventSubscriber implements EventSubscriberInterface
{
    protected $config;

    /**
     * @param \Platformsh\Cli\Service\Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [ConsoleEvents::ERROR => 'onError'];
    }

    /**
     * React to any console exceptions.
     *
     * @param \Symfony\Component\Console\Event\ConsoleErrorEvent $event
     */
    public function onError(ConsoleErrorEvent $event)
    {
        $error = $event->getError();

        // Replace Guzzle connect exceptions with a friendlier message. This
        // also prevents the user from seeing two exceptions (one direct from
        // Guzzle, one from RingPHP).
        if ($error instanceof ConnectException && strpos($error->getMessage(), 'cURL error 6') !== false) {
            $request = $error->getRequest();
            $event->setError(new ConnectionFailedException(
                "Failed to connect to host: " . $request->getUri()->getHost()
                . " \nPlease check your Internet connection.",
                $request
            ));
            $event->stopPropagation();
        }

        // Create a friendlier message for the OAuth2 "Invalid refresh token"
        // error.
        $loginCommand = sprintf('%s login', $this->config->get('application.executable'));
        if ($error instanceof IdentityProviderException) {
            if (strpos($error->getMessage(), 'Invalid refresh token') !== false) {
                $event->setError(new LoginRequiredException(
                    "Invalid refresh token. \nPlease log in again by running: $loginCommand"
                ));
                $event->stopPropagation();
            }
        }

        // Handle Guzzle exceptions, i.e. HTTP 4xx or 5xx errors.
        if (($error instanceof ClientException || $error instanceof ServerException)
            && ($response = $error->getResponse())) {
            $request = $error->getRequest();
            $response->getBody()->seek(0);
            $isOauth2 = stripos(implode(';', $request->getHeader('Authorization')), 'Bearer') !== false;

            if ($response->getStatusCode() === 401 && $isOauth2) {
                $event->setError(new LoginRequiredException(
                    'Unauthorized.',
                    $request,
                    $response,
                    $this->config
                ));
                $event->stopPropagation();
            } elseif ($response->getStatusCode() === 403 && $isOauth2) {
                $event->setError(new PermissionDeniedException(
                    "Permission denied. Check your project or environment permissions.",
                    $request,
                    $response
                ));
                $event->stopPropagation();
            } else {
                $event->setError(new HttpException(null, $request, $response));
                $event->stopPropagation();
            }
        }

        // When an environment is found to be in the wrong state, perhaps our
        // cache is old - we should invalidate it.
        if ($error instanceof EnvironmentStateException) {
            (new Api())->clearEnvironmentsCache($error->getEnvironment()->project);
        }
    }
}
