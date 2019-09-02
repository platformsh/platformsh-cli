<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Input\InputDefinition;

interface InputConfiguringInterface
{
    /**
     * Add options or arguments to a command's input definition.
     *
     * @param \Symfony\Component\Console\Input\InputDefinition $definition
     *
     * @return void
     */
    public function configureInput(InputDefinition $definition): void;
}
