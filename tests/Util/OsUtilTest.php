<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests\Util;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Util\OsUtil;

class OsUtilTest extends TestCase
{
    public function testEscapePosixShellArg()
    {
        $this->assertEquals(
            "'This isn'\\''t an argument!'",
            OsUtil::escapePosixShellArg("This isn't an argument!")
        );
        $this->assertEquals(
            "'Yes it is'",
            OsUtil::escapePosixShellArg("Yes it is")
        );
        $this->assertEquals(
            "'No it isn'\\''t'",
            OsUtil::escapePosixShellArg("No it isn't")
        );
    }
}
