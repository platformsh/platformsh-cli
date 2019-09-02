<?php
declare(strict_types=1);

namespace Platformsh\Cli\Exception;

class ProjectNotFoundException extends \RuntimeException
{
    protected $code = 9;
}
