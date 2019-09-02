<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Console\AdaptiveTable;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\BufferedOutput;

class AdaptiveTableTest extends TestCase
{
    /**
     * Test that a wide table is adapted to a maximum width.
     */
    public function testAdaptedRowsFitMaxTableWidth()
    {
        $maxTableWidth = 60;
        $buffer = new BufferedOutput();
        $table = new AdaptiveTable($buffer, $maxTableWidth);
        $table->setHeaders([
            ['Row', 'Lorem', 'ipsum', 'dolor', 'sit'],
        ]);
        $table->setRows([
            ['#1', 'amet', 'consectetur', 'adipiscing elit', 'Quisque pulvinar'],
            ['#2', 'tellus sit amet', 'sollicitudin', 'tincidunt', 'risus'],
            ['#3', 'risus', 'sem', 'mattis', 'ex'],
            ['#4', 'quis', 'luctus metus', 'lorem cursus', 'ligula'],
        ]);
        $table->render();

        // Test that the table fits into the maximum width.
        $lineWidths = [];
        foreach (explode(PHP_EOL, $buffer->fetch()) as $line) {
            $lineWidths[] = strlen($line);
        }
        $this->assertLessThanOrEqual($maxTableWidth, max($lineWidths));
    }

    /**
     * Tests that a string can be wrapped with decoration at various lengths.
     *
     * @param string $input
     * @param int[]  $maxLengths
     */
    private function assertWrappedWithDecoration($input, array $maxLengths = [5, 8, 13, 21, 34, 55, 89])
    {
        $o = new BufferedOutput();
        $f = $o->getFormatter();
        $table = new AdaptiveTable($o);
        $plainText = Helper::removeDecoration($f, $input);
        foreach ($maxLengths as $maxLength) {
            $plainWrapped = wordwrap($plainText, $maxLength, "\n", true);
            $tableWrapped = $table->wrapWithDecoration($input, $maxLength);
            $this->assertEquals($plainWrapped, Helper::removeDecoration($f, $tableWrapped));
        }
    }

    public function testWrapWithDecorationPlain()
    {
        $this->assertWrappedWithDecoration(
            'This is a test of raw text which should be wrapped as normal.'
        );
    }

    public function testWrapWithDecorationSimple()
    {
        $this->assertWrappedWithDecoration(
            'The quick brown <error>fox</error> <options=underscore>jumps</> over the lazy <info>dog</info>.'
        );
    }

    public function testWrapWithDecorationComplex()
    {
        $this->assertWrappedWithDecoration(
            'Lorem ipsum <info>dolor</info> sit <info>amet,</info> consectetur <error>adipiscing elit,</error> sed do eiusmod tempor <options=reverse>incididunt ut labore et dolore magna aliqua.</> Ut enim ad minim veniam, quis nostrud <options=underscore>exercitation</> ullamco laboris nisi ut aliquip ex ea commodo <options=reverse>consequat. Duis</> aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat (cupidatat) non <info>proident</info>, sunt in culpa qui officia deserunt mollit anim id est laborum.'
        );
    }

    public function testWrapWithDecorationIncludingEscapedTags()
    {
        $this->assertWrappedWithDecoration(
            'The quick brown fox <options=underscore>jumps</> over the lazy \\<script type="text/javascript">dog\\</script>.'
        );
    }
}
