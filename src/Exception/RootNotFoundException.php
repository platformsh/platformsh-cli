<?php
declare(strict_types=1);

namespace Platformsh\Cli\Exception;

use Platformsh\Cli\Service\Config;
use Symfony\Component\Console\Exception\ExceptionInterface;

class RootNotFoundException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        $message = 'Project root not found. This can only be run from inside a project directory.',
        $code = 2
    )
    {
        // If this is a Git repository that looks like an un-configured project,
        // then suggest the "project:set-remote" command.
        if (is_dir('.git')) {
            $config = new Config();
            if (is_dir($config->get('service.project_config_dir'))) {
                $executable = $config->get('application.executable');
                $message .= "\n\nTo set the project for this Git repository, run:\n  $executable project:set-remote [id]";
            }
        }

        parent::__construct($message, $code);
    }
}
