<?php
declare(strict_types=1);

namespace Platformsh\Cli\Session;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Session\Storage\SessionStorageInterface;

/**
 * Store sessions in the OS X keychain.
 */
class KeychainStorage implements SessionStorageInterface
{
    private $appName;
    private $appId;

    /**
     * KeychainStorage constructor.
     *
     * @param string $appName The name of the application storing keys.
     * @param string $appId   The ID of the application storing keys.
     */
    public function __construct($appName, $appId = '')
    {
        $this->appName = $appName;
        $this->appId = $appId ?: strtolower(preg_replace('/\W+/', '-', $this->appName));
    }

    /**
     * Check if this storage type is supported.
     *
     * @return bool
     */
    public static function isSupported()
    {
        return OsUtil::isOsX() && self::exec(['command', '-v', 'security']) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function load($sessionId)
    {
        $data = $this->exec(array_merge([
            'security',
            'find-generic-password',
            '-w', // Output the data to stdout.
        ], $this->getKeyIdentifiers($sessionId)));

        if (is_string($data)) {
            return $this->deserialize($data);
        }

        // If data doesn't exist in the keychain yet, load it from an old
        // file for backwards compatibility.
        return $this->loadFromFile($sessionId);
    }

    /**
     * Load the session from an old file for backwards compatibility.
     *
     * @param string $sessionId
     *
     * @return array
     */
    private function loadFromFile($sessionId)
    {
        $id = preg_replace('/[^\w\-]+/', '-', $sessionId);
        $dir = (new Config())->getSessionDir();
        $filename = "$dir/sess-$id/sess-$id.json";
        if (is_readable($filename) && ($contents = file_get_contents($filename))) {
            rename($filename, $filename . '.bak');

            return json_decode($contents, true) ?: [];
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function save($sessionId, array $data)
    {
        $result = $this->exec(array_merge(
            [
                'security',
                'add-generic-password',
                '-U', // Update if the key already exists.
            ],
            $this->getKeyIdentifiers($sessionId),
            [
                // The data ("password") to store. This must be the final
                // argument.
                '-w' . $this->serialize($data),
            ]
        ));
        if ($result === false) {
            throw new \RuntimeException('Failed to save the session to the keychain');
        }
    }

    /**
     * Delete keys for all session IDs.
     */
    public function deleteAll()
    {
        $this->exec([
            'security',
            'delete-generic-password',
            '-a' . $this->getAccountName(),
        ]);
    }

    /**
     * Get arguments identifying the key to the 'security' utility.
     *
     * @param string $sessionId
     *
     * @return array
     */
    private function getKeyIdentifiers($sessionId)
    {
        return [
            // Account name:
            '-a' . $this->getAccountName(),
            // Service name:
            '-s' . 'session-' . $sessionId,
            // Label:
            '-l' . $this->appName . ': ' . $sessionId,
        ];
    }

    /**
     * Get the account name for the keychain.
     *
     * @return string
     */
    private function getAccountName()
    {
        return $this->appId . '--' . getenv('USER');
    }

    /**
     * Execute a command (on OS X) without displaying it or its result.
     *
     * @param string[] $args
     *
     * @return string|false The command's stdout output, or false on failure.
     */
    private static function exec(array $args)
    {
        $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
        $process = proc_open($cmd, [1 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            return false;
        }

        return $output;
    }

    /**
     * Serialize session data.
     *
     * @param array $data
     *
     * @return string
     */
    private function serialize(array $data)
    {
        return base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Deserialize session data.
     *
     * @param string $data
     *
     * @return array
     */
    private function deserialize($data)
    {
        $result = json_decode(base64_decode($data, true), true);

        return is_array($result) ? $result : [];
    }
}
