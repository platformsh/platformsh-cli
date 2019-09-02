<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Installer\Installer;
use Platformsh\Cli\Installer\VersionResolver;

class InstallerTest extends TestCase
{

    public function setUp() {
        require_once CLI_ROOT . '/dist/installer.php';
    }

    public function testFindInstallableVersionsChecksForSuffix()
    {
        $resolver = new VersionResolver();
        $this->assertEquals(
            [
                ['version' => '1.0.0'],
                ['version' => '1.0.1'],
                ['version' => '1.0.2-beta'],
            ],
            $resolver->findInstallableVersions([
                ['version' => '1.0.0'],
                ['version' => '1.0.1'],
                ['version' => '1.0.2-beta'],
                ['version' => '1.0.3-dev'],
            ], PHP_VERSION, ['beta'])
        );
        $this->assertEquals(
            [
                ['version' => '1.0.0-stable'],
                ['version' => '1.0.1'],
                ['version' => '1.0.2-beta'],
            ],
            $resolver->findInstallableVersions([
                ['version' => '1.0.0-stable'],
                ['version' => '1.0.1'],
                ['version' => '1.0.2-beta'],
                ['version' => '1.0.3-dev'],
            ], PHP_VERSION, ['stable', 'beta'])
        );
    }

    public function testFindInstallableVersionsChecksFoMinPhp()
    {
        $this->assertEmpty((new VersionResolver())->findInstallableVersions([
            [
                'version' => '1.0.0',
                'php' => ['min' => '5.5.9'],
            ]
        ], '5.5.0'));
    }

    public function testFindLatestVersionWithMax()
    {
        $this->assertEquals('3.0.0', (new VersionResolver())->findLatestVersion([
            ['version' => '1.0.0'],
            ['version' => '2.0.0'],
            ['version' => '3.0.0'],
            ['version' => '3.0.1'],
        ], '', '3.0.0')['version']);
    }

    public function testFindLatestVersionWithMin()
    {
        $this->assertEquals('3.0.1', (new VersionResolver())->findLatestVersion([
            ['version' => '1.0.0'],
            ['version' => '3.0.1'],
            ['version' => '2.0.0'],
            ['version' => '3.0.0'],
        ], '2.0')['version']);

        $this->expectException(\RuntimeException::class);
        (new VersionResolver())->findLatestVersion([
            ['version' => '1.0.0'],
            ['version' => '3.0.1'],
            ['version' => '2.0.0'],
            ['version' => '3.0.0'],
        ], 'v3.1');
    }

    public function testGetOption()
    {
        $method = new \ReflectionMethod(Installer::class, 'getOption');
        $method->setAccessible(true);
        $args = ['--min', '1.2.3'];
        $this->assertEquals('1.2.3', $method->invoke(new Installer($args), 'min'));
        $args = ['--max', '2.3.4'];
        $this->assertEquals('', $method->invoke(new Installer($args), 'min'));
        $this->assertEquals('2.3.4', $method->invoke(new Installer($args), 'max'));
        $args = ['--max=2.0.0'];
        $this->assertEquals('2.0.0', $method->invoke(new Installer($args), 'max'));
    }

}
