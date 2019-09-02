<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Console\AdaptiveTable;
use Platformsh\Cli\Util\Csv;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Display a table in a format chosen by the user.
 *
 * Usage:
 * <code>
 *     // In a command's configure() method, add the --format option:
 *     Table::addFormatOption($this->getDefinition());
 *
 *     // In a command's execute() method, build and display the table:
 *     $table = new Table($input, $output);
 *     $header = ['Column 1', 'Column 2', 'Column 3'];
 *     $rows = [
 *         ['Cell 1', 'Cell 2', 'Cell 3'],
 *         ['Cell 4', 'Cell 5', 'Cell 6'],
 *     ];
 *     $table->render($rows, $header);
 * </code>
 */
class Table implements InputConfiguringInterface
{
    protected $output;
    protected $input;

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;
    }

    /**
     * Add the --format and --columns options to a command's input definition.
     *
     * @param InputDefinition $definition
     */
    public function configureInput(InputDefinition $definition): void
    {
        $description = 'The output format ("table", "csv", or "tsv")';
        $option = new InputOption('format', null, InputOption::VALUE_REQUIRED, $description, 'table');
        $definition->addOption($option);
        $description = 'Columns to display (comma-separated list, or multiple values)';
        $option = new InputOption('columns', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, $description);
        $definition->addOption($option);
        $description = 'Do not output the table header';
        $option = new InputOption('no-header', null, InputOption::VALUE_NONE, $description);
        $definition->addOption($option);
    }

    /**
     * Render an single-dimensional array of values, with their property names.
     *
     * @param string[] $values
     * @param string[] $propertyNames
     */
    public function renderSimple(array $values, array $propertyNames)
    {
        $data = [];
        foreach ($propertyNames as $key => $label) {
            $data[] = [$label, $values[$key]];
        }
        $this->render($data, ['Property', 'Value']);
    }

    /**
     * Render a table of data to output.
     *
     * @param array $rows
     *   The table rows.
     * @param string[] $header
     *   The table header (optional).
     * @param string[] $defaultColumns
     *   Default columns to display (optional). Columns are identified by
     *   their name in $header, or alternatively by their key in $rows.
     */
    public function render(array $rows, array $header = [], $defaultColumns = [])
    {
        $format = $this->getFormat();

        $columnsToDisplay = $this->columnsToDisplay() ?: $defaultColumns;
        $rows = $this->filterColumns($rows, $header, $columnsToDisplay);
        $header = $this->filterColumns([0 => $header], $header, $columnsToDisplay)[0];

        if ($this->input->hasOption('no-header') && $this->input->getOption('no-header')) {
            $header = [];
        }

        switch ($format) {
            case 'csv':
                $this->renderCsv($rows, $header);
                break;

            case 'tsv':
                $this->renderCsv($rows, $header, "\t");
                break;

            case null:
            case 'table':
                $this->renderTable($rows, $header);
                break;

            default:
                throw new InvalidArgumentException(sprintf('Invalid format: %s', $format));
        }
    }

    /**
     * Find whether the user wants machine-readable output.
     *
     * @return bool
     *   True if the user has specified a machine-readable format via the
     *   --format option (e.g. 'csv' or 'tsv'), false otherwise.
     */
    public function formatIsMachineReadable()
    {
        return in_array($this->getFormat(), ['csv', 'tsv']);
    }

    /**
     * Get the columns specified by the user.
     *
     * @return array
     */
    protected function columnsToDisplay()
    {
        if (!$this->input->hasOption('columns')) {
            return [];
        }
        $columns = $this->input->getOption('columns');
        if (count($columns) === 1) {
            $columns = preg_split('/\s*,\s*/', $columns[0]);
        }

        return $columns;
    }

    /**
     * Filter rows by column names.
     *
     * @param array    $rows
     * @param array    $header
     * @param string[] $columnsToDisplay
     *
     * @return array
     */
    private function filterColumns(array $rows, array $header, array $columnsToDisplay)
    {
        if (empty($columnsToDisplay)) {
            return $rows;
        }

        // The available columns are all the (lower-cased) values and string
        // keys in $header.
        $availableColumns = [];
        foreach ($header as $key => $column) {
            $columnName = is_string($key) ? $key : $column;
            $availableColumns[strtolower($columnName)] = $key;
        }

        // Validate the column names.
        foreach ($columnsToDisplay as &$columnName) {
            $columnNameLowered = strtolower($columnName);
            if (!isset($availableColumns[$columnNameLowered])) {
                throw new InvalidArgumentException(
                    sprintf('Column not found: %s (available columns: %s)', $columnName, implode(', ', array_keys($availableColumns)))
                );
            }
            $columnName = $columnNameLowered;
        }

        // Filter the rows for keys matching those in $availableColumns. If a
        // key doesn't exist in a row, then the cell will be an empty string.
        $newRows = [];
        foreach ($rows as $row) {
            $newRow = [];
            foreach ($columnsToDisplay as $columnNameLowered) {
                $key = $availableColumns[$columnNameLowered];
                $newRow[] = array_key_exists($key, $row) ? $row[$key] : '';
            }
            $newRows[] = $newRow;
        }

        return $newRows;
    }

    /**
     * Get the user-specified format.
     *
     * @return string|null
     */
    protected function getFormat()
    {
        if ($this->input->hasOption('format') && $this->input->getOption('format')) {
            return strtolower($this->input->getOption('format'));
        }

        return null;
    }

    /**
     * Render CSV output.
     *
     * @param array  $rows
     * @param array  $header
     * @param string $delimiter
     */
    protected function renderCsv(array $rows, array $header, $delimiter = ',')
    {
        if (!empty($header)) {
            array_unshift($rows, $header);
        }
        // RFC 4180 (the closest thing to a CSV standard) asks for CRLF line
        // breaks, but these do not play nicely with POSIX shells whose
        // default internal field separator (IFS) does not account for CR. So
        // the line break character is forced as LF.
        $this->output->write((new Csv($delimiter, "\n"))->format($rows));
    }

    /**
     * Render a Symfony Console table.
     *
     * @param array $rows
     * @param array $header
     */
    protected function renderTable(array $rows, array $header)
    {
        $table = new AdaptiveTable($this->output);
        $table->setHeaders($header);
        $table->setRows($rows);
        $table->render();
    }
}
