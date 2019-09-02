<?php

namespace Platformsh\Cli\Tests\Command\App;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\CommandRunner;
use Symfony\Component\Yaml\Parser;

class AppConfigGetTest extends TestCase
{
    public function testGetConfig() {
        $app = base64_encode(json_encode([
            'type' => 'php:7.3',
            'name' => 'app',
            'disk' => 512,
            'mounts' => [],
            'blank' => null,
        ]));
        $env = ['PLATFORM_APPLICATION' => $app];
        $this->assertEquals(
            'app',
            (new Parser)->parse((new CommandRunner())->run('app:config-get', [
                '--property', 'name',
            ], $env)->getOutput())
        );
        $this->assertEquals(
            [],
            (new Parser)->parse((new CommandRunner())->run('app:config-get', [
                '--property', 'mounts',
            ], $env)->getOutput())
        );
        $this->assertEquals(
            '',
            (new Parser)->parse((new CommandRunner())->run('app:config-get', [
                '--property', 'blank',
            ], $env)->getOutput())
        );
    }
}
