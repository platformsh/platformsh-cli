<?php
declare(strict_types=1);

namespace Platformsh\Cli\Exception;

class DependencyMissingException extends \RuntimeException
{
    protected $code = 7;
}
