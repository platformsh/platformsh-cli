<?php

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\Csv;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

class TableServiceTest extends TestCase
{
    /**
     * Test a table filtered by allowed column names.
     */
    public function testColumns()
    {
        $output = new BufferedOutput();
        $definition = new InputDefinition();
        (new Table(new ArrayInput([]), new NullOutput()))->configureInput($definition);
        $tableService = new Table(new ArrayInput([
            '--columns' => ['value 2,name'],
            '--format' => 'csv',
        ], $definition), $output);

        $header = ['Name', 'Value 1', 'Value 2', 'Value 3'];
        $rows = [
            ['foo', 1, 2, 3],
            ['bar', 4, 5, 6],
        ];
        $expected = (new Csv(',', "\n"))->format([
            ['Value 2', 'Name'],
            ['2', 'foo'],
            ['5', 'bar'],
        ]);

        $tableService->render($rows, $header);
        $actual = $output->fetch();

        $this->assertEquals($expected, $actual);
    }

    /**
     * Test that columns are validated.
     */
    public function testInvalidColumn()
    {
        $definition = new InputDefinition();
        (new Table(new ArrayInput([]), new NullOutput()))->configureInput($definition);
        $tableService = new Table(new ArrayInput([
            '--columns' => ['value 2,name'],
            '--format' => 'csv',
        ], $definition), new NullOutput());

        $rows = [
            ['foo', 1, 3],
            ['bar', 4, 6],
        ];
        $header = ['Name', 'Value 1', 'Value 3'];

        $this->expectException('InvalidArgumentException');
        $tableService->render($rows, $header);
    }
}
