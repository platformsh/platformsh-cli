<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests\BuildFlavor;

/**
 * @group slow
 */
class ComposerTest extends BaseBuildFlavorTest
{

    public function testBuildComposer()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/composer');
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/vendor/psr/log/README.md');
    }

    public function testBuildComposerCustomPhp()
    {
        $this->assertBuildSucceeds('tests/data/apps/composer-php56');
    }

    public function testBuildComposerHhvm()
    {
        $this->assertBuildSucceeds('tests/data/apps/hhvm37');
    }

    public function testBuildComposerMounts()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/composer-mounts', [
            'copy' => true,
            'abslinks' => true,
        ]);
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $shared = $projectRoot . '/' . self::$config->get('local.shared_dir');
        $buildDir = $projectRoot . '/' . self::$config->get('local.build_dir') . '/default';

        $this->assertFileExists($webRoot . '/js');
        $this->assertFileExists($webRoot . '/css');
        $this->assertFileExists($buildDir . '/cache');
        $this->assertEquals($shared . '/assets/js', readlink($webRoot . '/js'));
        $this->assertEquals($shared . '/assets/css', readlink($webRoot . '/css'));
        $this->assertEquals($shared . '/cache', readlink($buildDir . '/cache'));
    }

    /**
     * Test the case where a user has specified "symfony" as the build flavor,
     * for an application which does not contain a composer.json file. The build
     * may not do much, but at least it should not throw an exception.
     */
    public function testBuildFakeSymfony()
    {
        $this->assertBuildSucceeds('tests/data/apps/fake-symfony');
    }

    /**
     * Test the deprecated config file format still works.
     */
    public function testBuildDeprecatedConfig()
    {
        $this->assertBuildSucceeds('tests/data/apps/deprecated-config');
    }
}
