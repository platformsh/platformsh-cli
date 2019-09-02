<?php

namespace Platformsh\Cli\Tests\Util;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Util\Csv;

class CsvTest extends TestCase
{
    private $data = [];

    public function setUp()
    {
        // Data from a Wikipedia example.
        // https://en.wikipedia.org/wiki/Comma-separated_values
        $this->data = [
            ['Year', 'Make', 'Model', 'Description', 'Price'],
            ['1997', 'Ford', 'E350', 'ac, abs, moon', '3000.00'],
            ['1999', 'Chevy', 'Venture "Extended Edition"', '', '4900.00'],
            ['1999', 'Chevy', 'Venture "Extended Edition, Very Large"', '', '5000.00'],
            ['1996', 'Jeep', 'Grand Cherokee', "MUST SELL!\nair, moon roof, loaded", '4799.00'],
        ];
    }

    public function testRfc4180()
    {
        $expected = "Year,Make,Model,Description,Price\r\n"
            . "1997,Ford,E350,\"ac, abs, moon\",3000.00\r\n"
            . "1999,Chevy,\"Venture \"\"Extended Edition\"\"\",,4900.00\r\n"
            . "1999,Chevy,\"Venture \"\"Extended Edition, Very Large\"\"\",,5000.00\r\n"
            . "1996,Jeep,Grand Cherokee,\"MUST SELL!\r\nair, moon roof, loaded\",4799.00\r\n";
        $actual = (new Csv())->format($this->data);
        $this->assertEquals($expected, $actual);
    }

    public function testTsv()
    {
        $expected = "Year\tMake\tModel\tDescription\tPrice\n"
            . "1997\tFord\tE350\tac, abs, moon\t3000.00\n"
            . "1999\tChevy\t\"Venture \"\"Extended Edition\"\"\"\t\t4900.00\n"
            . "1999\tChevy\t\"Venture \"\"Extended Edition, Very Large\"\"\"\t\t5000.00\n"
            . "1996\tJeep\tGrand Cherokee\t\"MUST SELL!\nair, moon roof, loaded\"\t4799.00";
        $actual = (new Csv("\t", "\n"))->format($this->data, false);
        $this->assertEquals($expected, $actual);
    }
}
