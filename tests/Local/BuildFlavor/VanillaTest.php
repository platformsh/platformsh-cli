<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests\BuildFlavor;

use Platformsh\Cli\Service\Filesystem;

/**
 * @group slow
 */
class VanillaTest extends BaseBuildFlavorTest
{
    public function testBuildVanilla()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/vanilla');
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.html');
    }

    /**
     * Test building without symlinks.
     */
    public function testBuildNoSymlinks()
    {
        $sourceDir = 'tests/data/apps/vanilla';
        $projectRoot = $this->assertBuildSucceeds($sourceDir, ['copy' => true]);
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.html');
        $this->assertTrue(is_dir($webRoot), 'Web root is an actual directory');
    }

    /**
     * Test building with a custom web root.
     */
    public function testBuildCustomWebRoot()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/vanilla-webroot');
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.html');
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/vanilla-webroot', ['copy' => true]);
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.html');
    }

    /**
     * Test with a custom source and destination.
     */
    public function testBuildCustomSourceDestination()
    {
        // Copy the 'vanilla' app to a temporary directory.
        $sourceDir = $this->createTempSubDir();
        $fsHelper = new Filesystem();
        $fsHelper->copyAll('tests/data/apps/vanilla', $sourceDir);

        $destSubDir = $this->createTempSubDir();
        $destination = $destSubDir . '/custom-destination';

        // Test with symlinking.
        $this->builder->build(['abslinks' => true], $sourceDir, $destination);
        $this->assertFileExists($destination . '/index.html');

        // Test with copying.
        $this->builder->build(['copy' => true, 'abslinks' => true], $sourceDir, $destination);
        $this->assertFileExists($destination . '/index.html');

        // Remove the temporary files.
        exec('rm -R ' . escapeshellarg($destSubDir) . ' ' . escapeshellarg($sourceDir));
    }

    /**
     * Test with a custom destination.
     */
    public function testBuildCustomDestination()
    {
        $projectRoot = $this->createDummyProject('tests/data/apps/vanilla');

        $destination = $projectRoot . '/web';

        $this->builder->build($this->buildSettings, $projectRoot, $destination);
        $this->assertFileExists($destination . '/index.html');
    }
}
