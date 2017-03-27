<?php
namespace Platformsh\Cli\Command\App\Platform;

interface PlatformInterface {
    public function name();

    public function getFields();

    public function appYamlTemplate();
}
