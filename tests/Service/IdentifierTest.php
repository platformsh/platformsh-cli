<?php

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Identifier;

class IdentifierTest extends TestCase
{

    public function testIdentify()
    {
        $identifier = new Identifier();

        $url = 'https://master-4jkbdba6zde2i.eu-2.platformsh.site';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'master',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://master-4jkbdba6zde2i--foo.eu-2.platformsh.site';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'master',
            'host' => null,
            'appId' => 'foo',
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://www---master-4jkbdba6zde2i.eu-2.platformsh.site';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'master',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://eu-2.platform.sh/projects/4jkbdba6zde2i';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => null,
            'host' => 'eu-2.platform.sh',
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://eu-2.platform.sh/projects/4jkbdba6zde2i/environments/bar';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'bar',
            'host' => 'eu-2.platform.sh',
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://console.platform.sh/foo/4jkbdba6zde2i';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => null,
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://console.platform.sh/foo/4jkbdba6zde2i/bar';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'bar',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));
    }

    public function testIdentifyWithEnvironmentIdOf0()
    {
        $identifier = new Identifier();

        $url = 'https://eu-2.platform.sh/projects/4jkbdba6zde2i/environments/0';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => '0',
            'host' => 'eu-2.platform.sh',
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://console.platform.sh/foo/4jkbdba6zde2i/0';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => '0',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));
    }
}
