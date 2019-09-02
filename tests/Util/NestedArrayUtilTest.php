<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests\Util;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Util\NestedArrayUtil;

class NestedArrayUtilTest extends TestCase
{
    protected $testArray = [];

    public function setUp()
    {
        $this->testArray = [
            'a' => [
                '0' => ['x' => 'foo', 'y' => 'bar'],
                '1' => ['x' => 'bar', 'y' => 'foo'],
            ],
            'b' => ['foo' => 'bar'],
            'c' => ['value1', 'value2'],
            'd' => 'foo',
        ];
    }

    public function testGetValue()
    {
        $this->assertEquals($this->testArray['a']['0'], $this->getValue('a.0'));
        $this->assertEquals($this->testArray['a']['0']['x'], $this->getValue('a.0.x'));
        $this->assertEquals($this->testArray['a']['1']['y'], $this->getValue('a.1.y'));
        $this->assertEquals($this->testArray['b']['foo'], $this->getValue('b.foo'));
        $this->assertEquals($this->testArray['c'][1], $this->getValue('c.1'));
        $this->assertEquals($this->testArray['d'], $this->getValue('d'));
        $this->assertEquals(null, $this->getValue('d.foo'));
    }

    public function testSetValue()
    {
        NestedArrayUtil::setNestedArrayValue($this->testArray, ['a', 'foo'], 'bar');
        $this->assertEquals($this->testArray['a']['foo'], 'bar');
        NestedArrayUtil::setNestedArrayValue($this->testArray, ['c', 2, 3], 'test');
        $this->assertEquals($this->testArray['c'][2][3], 'test');
    }

    public function testKeyExists()
    {
        NestedArrayUtil::getNestedArrayValue($this->testArray, ['a', '0'], $keyExists);
        $this->assertTrue($keyExists);
        NestedArrayUtil::getNestedArrayValue($this->testArray, ['a', '2'], $keyExists);
        $this->assertFalse($keyExists);
    }

    /**
     * @param string $property
     *
     * @return mixed
     */
    private function getValue($property)
    {
        return NestedArrayUtil::getNestedArrayValue($this->testArray, explode('.', $property));
    }
}
