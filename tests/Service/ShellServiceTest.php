<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Shell;

class ShellServiceTest extends TestCase
{

    /**
     * Test ShellHelper::execute().
     */
    public function testExecute()
    {
        $shell = new Shell();

        // Find a command that will work on all platforms.
        $workingCommand = strpos(PHP_OS, 'WIN') !== false ? 'help' : 'pwd';

        // Test commandExists().
        $this->assertTrue($shell->commandExists($workingCommand));
        $this->assertFalse($shell->commandExists('nonexistent'));

        // With $mustRun disabled.
        $this->assertNotEmpty($shell->execute([$workingCommand]));
        $this->assertFalse($shell->execute(['which', 'nonexistent']));

        // With $mustRun enabled.
        $this->assertNotEmpty($shell->execute([$workingCommand], null, true));
        $this->expectException('Exception');
        $shell->execute(['which', 'nonexistent'], null, true);
    }
}
