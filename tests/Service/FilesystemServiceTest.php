<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Filesystem;

class FilesystemServiceTest extends TestCase
{
    use HasTempDirTrait;

    /** @var Filesystem */
    protected $fs;

    /**
     * @{inheritdoc}
     */
    public function setUp()
    {
        $this->fs = new Filesystem();
        $this->tempDirSetUp();
    }

    /**
     * Test our own self::tempDir().
     */
    public function testTempDir()
    {
        $tempDir = $this->tempDir();
        $this->assertTrue(is_dir($tempDir));
        $tempDir = $this->tempDir(true);
        $this->assertFileExists($tempDir . '/test-file');
        $this->assertFileExists($tempDir . '/test-dir/test-file');
        $this->assertFileExists($tempDir . '/test-nesting/1/2/3/test-file');
    }

    /**
     * Test FilesystemHelper::getHomeDirectory().
     */
    public function testGetHomeDirectory()
    {
        $homeDir = $this->fs->getHomeDirectory();
        $this->assertNotEmpty($homeDir, 'Home directory returned');
        $this->assertNotEmpty(realpath($homeDir), 'Home directory exists');
    }

    /**
     * Test FilesystemHelper::remove() on directories.
     */
    public function testRemoveDir()
    {
        // Create a test directory containing some files in several levels.
        $testDir = $this->tempDir(true);

        // Check that the directory can be removed.
        $this->assertTrue($this->fs->remove($testDir));
        $this->assertFileNotExists($testDir);
    }

    /**
     * Test FilesystemHelper::copyAll().
     */
    public function testCopyAll()
    {
        $source = $this->tempDir(true);
        $destination = $this->tempDir();
        touch($source . '/.donotcopy');

        // Copy files.
        $this->fs->copyAll($source, $destination, ['.*']);

        // Check that they have been copied.
        $this->assertFileExists($destination . '/test-file');
        $this->assertFileExists($destination . '/test-dir/test-file');
        $this->assertFileNotExists($destination . '/.donotcopy');
    }

    /**
     * Test FilesystemHelper::symlinkDir().
     */
    public function testSymlinkDir()
    {
        $testTarget = $this->tempDir();
        $testLink = $this->tempDir() . '/link';
        $this->fs->symlink($testTarget, $testLink);
        $this->assertTrue(is_link($testLink));
        touch($testTarget . '/test-file');
        $this->assertFileExists($testLink . '/test-file');
    }

    /**
     * Test FilesystemHelper::makePathAbsolute().
     */
    public function testMakePathAbsolute()
    {
        $testDir = $this->tempDir();
        chdir($testDir);

        $path = $this->fs->makePathAbsolute('test.txt');
        $this->assertEquals($testDir . '/' . 'test.txt', $path);

        $childDir = $testDir . '/test';
        mkdir($childDir);
        chdir($childDir);

        $path = $this->fs->makePathAbsolute('../test.txt');
        $this->assertEquals($testDir . '/' . 'test.txt', $path);

        $path = $this->fs->makePathAbsolute('..');
        $this->assertEquals($testDir, $path);

        $this->expectException(\InvalidArgumentException::class);
        $this->fs->makePathAbsolute('nonexistent/test.txt');
    }

    /**
     * Test FilesystemHelper::symlinkAll().
     */
    public function testSymlinkAll()
    {
        $testSource = $this->tempDir(true);
        $testDestination = $this->tempDir();

        // Test plain symlinking.
        $this->fs->symlinkAll($testSource, $testDestination);
        $this->assertFileExists($testDestination . '/test-file');
        $this->assertFileExists($testDestination . '/test-dir/test-file');
        $this->assertFileExists($testDestination . '/test-nesting/1/2/3/test-file');

        // Test with skipping an existing file.
        $testDestination = $this->tempDir();
        touch($testDestination . '/test-file');
        $this->fs->symlinkAll($testSource, $testDestination);
        $this->assertFileExists($testDestination . '/test-file');
        $this->assertFileExists($testDestination . '/test-dir/test-file');
        $this->assertFileExists($testDestination . '/test-nesting/1/2/3/test-file');

        // Test with relative links. This has no effect on Windows.
        $testDestination = $this->tempDir();
        $this->fs->setRelativeLinks(true);
        $this->fs->symlinkAll($testSource, $testDestination);
        $this->fs->setRelativeLinks(false);
        $this->assertFileExists($testDestination . '/test-file');
        $this->assertFileExists($testDestination . '/test-dir/test-file');
        $this->assertFileExists($testDestination . '/test-nesting/1/2/3/test-file');

        // Test with a blacklist.
        $testDestination = $this->tempDir();
        touch($testSource . '/test-file2');
        $this->fs->symlinkAll($testSource, $testDestination, true, false, ['test-file']);
        $this->assertFileNotExists($testDestination . '/test-file');
        $this->assertFileExists($testDestination . '/test-dir/test-file');
        $this->assertFileExists($testDestination . '/test-nesting/1/2/3/test-file');
    }

    public function testCanWrite()
    {
        $testDir = $this->createTempSubDir();
        touch($testDir . '/test-file');
        $this->assertTrue($this->fs->canWrite($testDir . '/test-file'));
        chmod($testDir . '/test-file', 0500);
        $this->assertFalse($this->fs->canWrite($testDir . '/test-file'));
        mkdir($testDir . '/test-dir', 0700);
        $this->assertTrue($this->fs->canWrite($testDir . '/test-dir'));
        $this->assertTrue($this->fs->canWrite($testDir . '/test-dir/1'));
        $this->assertTrue($this->fs->canWrite($testDir . '/test-dir/1/2/3'));
        mkdir($testDir . '/test-ro-dir', 0500);
        $this->assertFalse(is_writable($testDir . '/test-ro-dir'));
        $this->assertFalse($this->fs->canWrite($testDir . '/test-ro-dir'));
        $this->assertFalse($this->fs->canWrite($testDir . '/test-ro-dir/1'));
    }

    /**
     * Create a test directory with a unique name.
     *
     * @param bool $fill Fill the directory with some files.
     *
     * @return string
     */
    protected function tempDir($fill = false)
    {
        $testDir = $this->createTempSubDir();
        if ($fill) {
            touch($testDir . '/test-file');
            mkdir($testDir . '/test-dir');
            touch($testDir . '/test-dir/test-file');
            mkdir($testDir . '/test-nesting/1/2/3', 0755, true);
            touch($testDir . '/test-nesting/1/2/3/test-file');
        }

        return $testDir;
    }

}
