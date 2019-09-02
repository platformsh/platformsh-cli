<?php
declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Extends the Symfony Console Table to make it adaptive to the terminal width.
 */
class AdaptiveTable extends Table
{
    /** @var int */
    protected $maxTableWidth;

    /** @var int */
    protected $minColumnWidth;

    // The following 3 properties are copies of the private properties in the
    // parent Table class.
    protected $rowsCopy = [];
    protected $headersCopy = [];
    protected $outputCopy;

    /**
     * AdaptiveTable constructor.
     *
     * @param OutputInterface $output
     * @param int|null        $maxTableWidth
     * @param int|null        $minColumnWidth
     */
    public function __construct(OutputInterface $output, $maxTableWidth = null, $minColumnWidth = 10)
    {
        $this->outputCopy = $output;
        $this->maxTableWidth = $maxTableWidth !== null
            ? $maxTableWidth
            : (new Terminal())->getWidth();
        $this->minColumnWidth = $minColumnWidth;

        parent::__construct($output);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides Table->addRow() so the row content can be accessed.
     */
    public function addRow($row)
    {
        if ($row instanceof TableSeparator) {
            $this->rowsCopy[] = $row;

            return parent::addRow($row);
        }

        if (!is_array($row)) {
            throw new \InvalidArgumentException('A row must be an array or a TableSeparator instance.');
        }

        $this->rowsCopy[] = array_values($row);

        return parent::addRow($row);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides Table->setHeaders() so the header content can be accessed.
     */
    public function setHeaders(array $headers)
    {
        $headers = array_values($headers);
        if (!empty($headers) && !is_array($headers[0])) {
            $headers = array($headers);
        }

        $this->headersCopy = $headers;

        return parent::setHeaders($headers);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides Table->render(), to adapt all the cells to the table width.
     */
    public function render()
    {
        $this->adaptRows();
        parent::render();
    }

    /**
     * Adapt rows based on the terminal width.
     */
    protected function adaptRows()
    {
        // Go through all headers and rows, wrapping their cells until each
        // column meets the max column width.
        $maxColumnWidths = $this->getMaxColumnWidths();
        $this->setRows($this->adaptCells($this->rowsCopy, $maxColumnWidths));
    }

    /**
     * Modify table rows, wrapping their cells' content to the max column width.
     *
     * @param array $rows
     * @param array $maxColumnWidths
     *
     * @return array
     */
    protected function adaptCells(array $rows, array $maxColumnWidths)
    {
        foreach ($rows as $rowNum => &$row) {
            if ($row instanceof TableSeparator) {
                continue;
            }
            foreach ($row as $column => &$cell) {
                $cellWidth = $this->getCellWidth($cell);
                if ($cellWidth <= $maxColumnWidths[$column]) {
                    continue;
                }
                $contents = $cell instanceof TableCell ? $cell->__toString() : $cell;
                $wrapped = $this->wrapCell($contents, $maxColumnWidths[$column]);
                if ($cell instanceof TableCell) {
                    $cell = new TableCell($wrapped, [
                        'colspan' => $cell->getColspan(),
                        'rowspan' => $cell->getRowspan(),
                    ]);
                } elseif ($cell instanceof AdaptiveTableCell) {
                    $cell = $cell->withValue($wrapped);
                } elseif (is_string($cell)) {
                    $cell = $wrapped;
                }
            }
        }

        return $rows;
    }

    /**
     * Word-wrap the contents of a cell, so that they fit inside a max width.
     *
     * @param string $contents
     * @param int    $width
     *
     * @return string
     */
    protected function wrapCell(string $contents, int $width)
    {
        // Account for left-indented cells.
        if (strpos($contents, ' ') === 0) {
            $trimmed = ltrim($contents, ' ');
            $indent = Helper::strlen($contents) - Helper::strlen($trimmed);

            return str_repeat(' ', $indent) . $this->wrapWithDecoration($trimmed, $width - $indent);
        }

        return $this->wrapWithDecoration($contents, $width);
    }

    /**
     * Word-wraps the contents of a cell, accounting for decoration.
     *
     * @param string $formattedText
     * @param int    $maxLength
     *
     * @return string
     */
    public function wrapWithDecoration(string $formattedText, int $maxLength)
    {
        $plainText = Helper::removeDecoration($this->outputCopy->getFormatter(), $formattedText);
        if ($plainText === $formattedText) {
            return wordwrap($plainText, $maxLength, "\n", true);
        }

        // Find all open and closing tags in the formatted text, with their
        // offsets, and build a plain text string out of the rest.
        $tagRegex = '[a-zA-Z][a-zA-Z0-9,_=;-]*+';
        preg_match_all('#</?(?:' . $tagRegex . ')?>#', $formattedText, $matches, PREG_OFFSET_CAPTURE);
        $plainText = '';
        $tagChunks = [];
        $lastTagClose = 0;
        foreach ($matches[0] as $match) {
            list($tagChunk, $tagOffset) = $match;
            if (substr($formattedText, $tagOffset - 1, 1) === '\\') {
                continue;
            }
            $plainText .= substr($formattedText, $lastTagClose, $tagOffset - $lastTagClose);
            $tagChunks[$tagOffset] = $tagChunk;
            $lastTagClose = $tagOffset + strlen($tagChunk);
        }
        $plainText .= substr($formattedText, $lastTagClose);

        // Wrap the plain text, keeping track of the removed characters in each
        // line (caused by trimming).
        $remaining = $plainText;
        $lines = [];
        $removedCharacters = [];
        while (!empty($remaining)) {
            if (strlen($remaining) > $maxLength) {
                $spacePos = strrpos(substr($remaining, 0, $maxLength + 1), ' ');
                if ($spacePos !== false) {
                    $breakPosition = $spacePos + 1;
                } else {
                    $breakPosition = $maxLength;
                    // Adjust for \< which will be converted to < later.
                    $breakPosition += substr_count($remaining, '\\<', 0, $breakPosition);
                }
                $line = substr($remaining, 0, $breakPosition);
                $remaining = substr($remaining, $breakPosition);
            } else {
                $line = $remaining;
                $remaining = '';
            }
            $lineTrimmed = trim($line);
            $removedCharacters[] = strlen($line) - strlen($lineTrimmed);
            $lines[] = $lineTrimmed;
        }

        // Interpolate the tags back into the wrapped text.
        $remainingTagChunks = $tagChunks;
        $lineOffset = 0;
        foreach ($lines as $lineNumber => &$line) {
            $lineLength = strlen($line) + $removedCharacters[$lineNumber];
            foreach ($remainingTagChunks as $tagOffset => $tagChunk) {
                // Prefer putting opening tags at the beginning of a line, not
                // the end.
                if ($tagChunk[1] !== '/' && $tagOffset === $lineOffset + $lineLength) {
                    continue;
                }
                if ($tagOffset >= $lineOffset && $tagOffset <= $lineOffset + $lineLength) {
                    $insertPosition = $tagOffset - $lineOffset;
                    $line = substr($line, 0, $insertPosition) . $tagChunk . substr($line, $insertPosition);
                    $lineLength += strlen($tagChunk);
                    unset($remainingTagChunks[$tagOffset]);
                }
            }
            $lineOffset += $lineLength;
        }

        $wrapped = implode("\n", $lines) . implode($remainingTagChunks);

        // Ensure that tags are closed at the end of each line and re-opened at
        // the beginning of the next one.
        $wrapped = preg_replace_callback('@(<' . $tagRegex . '>)(((?!(?<!\\\)</).)+)@s', function (array $matches) {
            return $matches[1] . str_replace("\n", "</>\n" . $matches[1], $matches[2]);
        }, $wrapped);

        return $wrapped;
    }

    /**
     * @return array
     *   An array of the maximum column widths that fit into the table width,
     *   keyed by the column number.
     */
    protected function getMaxColumnWidths(): array
    {
        // Loop through the table rows and headers, building multidimensional
        // arrays of the 'original' and 'minimum' column widths. In the same
        // loop, build a count of the number of columns.
        $originalColumnWidths = [];
        $minColumnWidths = [];
        $columnCounts = [0];
        foreach (array_merge($this->rowsCopy, $this->headersCopy) as $rowNum => $row) {
            if ($row instanceof TableSeparator) {
                continue;
            }
            $columnCount = 0;
            foreach ($row as $column => $cell) {
                $columnCount += $column instanceof TableCell ? $column->getColspan() - 1 : 1;

                // The column width is the width of the widest cell.
                $cellWidth = $this->getCellWidth($cell);
                if (!isset($originalColumnWidths[$column]) || $originalColumnWidths[$column] < $cellWidth) {
                    $originalColumnWidths[$column] = $cellWidth;
                }

                // Find the minimum width of the cell. The default is configured
                // in minColumnWidth, but this is overridden for non-wrapping
                // cells and very narrow cells. Additionally, table headers are
                // never wrapped.
                $minCellWidth = $this->minColumnWidth;
                if ($cellWidth < $this->minColumnWidth
                    || ($cell instanceof AdaptiveTableCell && !$cell->canWrap())
                    || !isset($this->rowsCopy[$rowNum])) {
                    $minCellWidth = $cellWidth;
                }

                // The minimum column width is the greatest minimum cell width.
                if (!isset($minColumnWidths[$column]) || $minColumnWidths[$column] < $minCellWidth) {
                    $minColumnWidths[$column] = $minCellWidth;
                }
            }
            $columnCounts[] = $columnCount;
        }

        // Find the number of columns in the table. This uses the same process
        // as the parent private method Table->calculateNumberOfColumns().
        $columnCount = max($columnCounts);

        // Find the maximum width for each column's content, to fit into the
        // calculated maximum content width.
        $maxContentWidth = $this->getMaxContentWidth($columnCount);
        $maxColumnWidths = [];
        $totalWidth = array_sum($originalColumnWidths);
        asort($originalColumnWidths, SORT_NUMERIC);
        foreach ($originalColumnWidths as $column => $columnWidth) {
            $columnRatio = ($maxContentWidth / $totalWidth) * $columnWidth;
            $maxColumnWidth = (int) round($columnRatio);

            // Do not change the width of columns which are already narrower
            // than the minimum.
            if (isset($minColumnWidths[$column]) && $maxColumnWidth < $minColumnWidths[$column]) {
                $maxColumnWidth = $minColumnWidths[$column];
            }

            $maxColumnWidths[$column] = $maxColumnWidth;
            $totalWidth -= $columnWidth;
            $maxContentWidth -= $maxColumnWidth;
        }

        return $maxColumnWidths;
    }

    /**
     * Find the maximum content width (excluding decoration) for each table row.
     *
     * @param int $columnCount
     *   The number of columns in the table.
     *
     * @return int
     *   The maximum table width, minus the width taken up by decoration.
     */
    protected function getMaxContentWidth(int $columnCount): int
    {
        $style = $this->getStyle();
        $verticalBorderQuantity = $columnCount + 1;
        $paddingQuantity = $columnCount * 2;

        return $this->maxTableWidth
            - $verticalBorderQuantity * strlen($style->getVerticalBorderChar())
            - $paddingQuantity * strlen($style->getPaddingChar());
    }

    /**
     * Get the default width of a table cell (the length of its longest line).
     *
     * This is inspired by Table->getCellWidth(), but this also accounts for
     * multi-line cells.
     *
     * @param string|TableCell $cell
     *
     * @return float|int
     */
    private function getCellWidth($cell)
    {
        $lineWidths = [0];
        foreach (explode(PHP_EOL, (string) $cell) as $line) {
            $lineWidths[] = Helper::strlenWithoutDecoration($this->outputCopy->getFormatter(), $line);
        }
        $cellWidth = max($lineWidths);
        if ($cell instanceof TableCell && $cell->getColspan() > 1) {
            $cellWidth /= $cell->getColspan();
        }

        return $cellWidth;
    }
}
