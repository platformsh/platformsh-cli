<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\BuildFlavor\NoBuildFlavor;
use Platformsh\Cli\Local\BuildFlavor\Symfony;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Config;

class LocalApplicationTest extends TestCase
{

    private $config;

    public function setUp() {
        $this->config = new Config();
        $this->config->override('service.app_config_file', '_platform.app.yaml');
    }

    public function testBuildFlavorDetectionDrupal()
    {
        $appRoot = 'tests/data/apps/drupal/project';

        $app = new LocalApplication($appRoot, $this->config);

        $this->assertInstanceOf(Drupal::class, $app->getBuildFlavor());
    }

    public function testBuildFlavorDetectionSymfony()
    {
        $appRoot = 'tests/data/apps/symfony';

        $app = new LocalApplication($appRoot, $this->config);

        $this->assertInstanceOf(Symfony::class, $app->getBuildFlavor());
    }

    /**
     * Test the special case of HHVM buildFlavor types being the same as PHP.
     */
    public function testBuildFlavorAliasHhvm()
    {
        $appRoot = 'tests/data/apps/vanilla';

        $app = new LocalApplication($appRoot, $this->config);
        $app->setConfig(['type' => 'hhvm:3.7', 'build' => ['flavor' => 'symfony']]);;
        $buildFlavor = $app->getBuildFlavor();

        $this->assertInstanceOf(Symfony::class, $buildFlavor);
    }

    public function testBuildFlavorDetectionMultiple()
    {
        $fakeRepositoryRoot = 'tests/data/repositories/multiple';

        $applications = LocalApplication::getApplications($fakeRepositoryRoot, $this->config);
        $this->assertCount(6, $applications, 'Detect multiple apps');
    }

    public function testBuildFlavorDetectionNone()
    {
        $fakeAppRoot = 'tests/data/apps/none';

        $app = new LocalApplication($fakeAppRoot, $this->config);
        $this->assertInstanceOf(NoBuildFlavor::class, $app->getBuildFlavor(), 'Config does not indicate a specific build flavor');
    }

    public function testGetAppConfig()
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/simple';

        $app = new LocalApplication($fakeAppRoot, $this->config);
        $config = $app->getConfig();
        $this->assertEquals(['name' => 'simple'], $config);
        $this->assertEquals('simple', $app->getId());
    }

    public function testFindNestedApps()
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/nest';

        $apps = LocalApplication::getApplications($fakeAppRoot, $this->config);
        $this->assertEquals(count($apps), 3);
    }

    public function testGetAppConfigNested()
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/nest/nested';

        $app = new LocalApplication($fakeAppRoot, $this->config);
        $config = $app->getConfig();
        $this->assertEquals(['name' => 'nested1'], $config);
        $this->assertEquals('nested1', $app->getName());
        $this->assertEquals('nested1', $app->getId());
    }

    public function testGetSharedFileMounts()
    {
        $appRoot = 'tests/data/apps/drupal/project';
        $app = new LocalApplication($appRoot, $this->config);
        $this->assertEquals([
            'public/sites/default/files' => 'files',
            'tmp' => 'tmp',
            'private' => 'private',
            'drush-backups' => 'drush-backups',
        ], $app->getSharedFileMounts());

    }
}
