<?php
declare(strict_types=1);

namespace Platformsh\Cli\Exception;

class ConnectionFailedException extends HttpException
{
    protected $message = 'No Internet connection available.';
    protected $code = 5;
}
