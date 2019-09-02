<?php

namespace Platformsh\Cli\Tests\Command\Route;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\CommandRunner;

/**
 * @group commands
 */
class RouteGetTest extends TestCase
{
    private $mockRoutes;

    public function setUp() {
        $this->mockRoutes = base64_encode(json_encode([
            'https://example.com' => [
                'primary' => true,
                'type' => 'upstream',
                'upstream' => 'app:http',
                'original_url' => 'https://{default}',
            ],
            'http://example.com' => [
                'type' => 'redirect',
                'to' => 'https://example.com',
                'original_url' => 'http://{default}',
            ],
        ]));
    }

    private function runCommand(array $args): string {
        return (new CommandRunner())
            ->run('route:get', $args, ['PLATFORM_ROUTES' => $this->mockRoutes])
            ->getOutput();
    }

    public function testGetPrimaryRouteUrl() {
        $this->assertEquals(
            'https://example.com',
            rtrim($this->runCommand([
                '--primary',
                '--property', 'url',
            ]), "\n")
        );
    }

    public function testGetRouteByOriginalUrl() {
        $this->assertEquals(
            'false',
            rtrim($this->runCommand([
                'http://{default}',
                '--property', 'primary',
            ]), "\n")
        );
        $this->assertEquals(
            'true',
            rtrim($this->runCommand([
                'https://{default}',
                '--property', 'primary',
            ]), "\n")
        );
    }
}
