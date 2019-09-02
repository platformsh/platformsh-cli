<?php
declare(strict_types=1);

namespace Platformsh\Cli\Exception;

class InvalidConfigException extends \InvalidArgumentException
{
    protected $code = 4;

    /**
     * @param string $message
     * @param string $filename
     * @param string $configKey
     */
    public function __construct($message = '', $filename = '', $configKey = '')
    {
        if ($configKey !== '') {
            $message .= "\nin config key: $configKey";
        }
        if ($filename !== '') {
            $path = realpath($filename) ?: $filename;
            $message .= "\nin file: $path";
        }

        parent::__construct($message);
    }
}
