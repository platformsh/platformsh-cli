<?php

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\GitDataApi;

class GitDataApiServiceTest extends TestCase
{

    /**
     * Test GitDataApi::parseParents().
     */
    public function testParseParents()
    {
        $gitData = new GitDataApi();
        $reflection = new \ReflectionClass($gitData);
        $method = $reflection->getMethod('parseParents');
        $method->setAccessible(true);

        $expected = [
            'foo1^' => [1],
            'bar^^2' => [1, 2],
            'baz2^3^^' => [3, 1, 1],
            'bal~~^2^1~2' => [1, 1, 2, 1, 1, 1],
        ];

        $actual = [];
        foreach (array_keys($expected) as $spec) {
            $actual[$spec] = $method->invoke($gitData, $spec);
        }

        $this->assertEquals($expected, $actual);
    }
}
