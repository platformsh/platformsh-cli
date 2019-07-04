<?php
namespace Platformsh\Cli\Command\App\Platform;

interface PlatformInterface {
    public function type();

    public function getFields();

    public function appYamlTemplate();
}
