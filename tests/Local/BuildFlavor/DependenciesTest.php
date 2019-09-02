<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests\BuildFlavor;

/**
 * @group slow
 */
class DependenciesTest extends BaseBuildFlavorTest
{
    protected $sourceDir = 'tests/data/apps/build-deps';

    public function testBuildSucceedsIfDepsNotRequired()
    {
        $this->assertBuildSucceeds($this->sourceDir, ['no-deps' => true, 'no-build-hooks' => true]);
    }

    public function testBuildFailsIfDepsNotInstalled()
    {
        $this->assertBuildSucceeds($this->sourceDir, ['no-deps' => true], false);
    }

    public function testBuildSucceedsIfDepsInstalled()
    {
        $this->assertBuildSucceeds($this->sourceDir);
    }
}
