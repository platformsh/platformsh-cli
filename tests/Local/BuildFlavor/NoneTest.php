<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests\BuildFlavor;

/**
 * @group slow
 */
class NoneTest extends BaseBuildFlavorTest
{
    public function testBuildNone()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/none');
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.html');
    }
}
