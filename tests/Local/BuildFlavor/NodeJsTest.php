<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests\BuildFlavor;

/**
 * @group slow
 */
class NodeJsTest extends BaseBuildFlavorTest
{
    public function testBuildNodeJs()
    {
        $this->assertBuildSucceeds('tests/data/apps/nodejs');
    }

    public function testBuildNodeJsCopy()
    {
        $this->assertBuildSucceeds('tests/data/apps/nodejs', ['copy' => true]);
    }
}
