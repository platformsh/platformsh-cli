<?php

namespace Platformsh\Cli\Tests\Command\Environment;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\CommandRunner;

/**
 * @group commands
 */
class EnvironmentRelationshipsTest extends TestCase
{
    private $mockRelationships;

    public function setUp() {
        $this->mockRelationships = base64_encode(json_encode([
            'database' => [
                0 => [
                    'host' => 'database.internal',
                    'username' => 'main',
                    'password' => '123',
                    'scheme' => 'mysql',
                    'path' => 'main',
                    'port' => 3306,
                    'rel' => 'mysql',
                    'service' => 'database',
                    'query' => ['is_master' => true],
                ]
            ],
        ]));
        parent::__construct();
    }

    private function runCommand(array $args) {
        $result = (new CommandRunner())
            ->run('relationships', array_merge(['-v'], $args), ['PLATFORM_RELATIONSHIPS' => $this->mockRelationships]);

        return $result->getOutput();
    }

    public function testGetRelationshipHost() {
        $this->assertEquals(
            'database.internal',
            $this->runCommand(['-P', 'database.0.host'])
        );
    }

    public function testGetRelationshipUrl() {
        $this->assertEquals(
            'mysql://main:123@database.internal:3306/main?is_master=1',
            $this->runCommand(['-P', 'database.0.url'])
        );
    }
}
