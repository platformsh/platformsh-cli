<?php

namespace Platformsh\Cli\Tests\Command\App;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\CommandRunner;

/**
 * @group commands
 */
class WelcomeCommandTest extends TestCase
{
    public function testWelcomeOnLocalContainer() {
        chdir('/');

        $result = (new CommandRunner())
            ->run('welcome', [], [
                'PLATFORM_PROJECT' => 'test-project',
                'PLATFORM_BRANCH' => 'test-environment',
                'PLATFORM_ROUTES' => base64_encode(json_encode([])),
                'PLATFORMSH_CLI_SESSION_ID' => 'test' . rand(100, 999),
            ]);

        $this->assertContains(
            'Project ID: test-project',
            $result->getErrorOutput()
        );
        $this->assertContains(
            'Local environment commands',
            $result->getErrorOutput()
        );
    }
}
