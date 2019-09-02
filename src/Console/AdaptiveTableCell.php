<?php
declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Helper\TableCell;

/**
 * Extends the Symfony Console Table to make it adaptive to the terminal width.
 */
class AdaptiveTableCell extends TableCell
{
    protected $wrap = true;

    public function __construct($value, array $options = [])
    {
        foreach (['wrap'] as $flag) {
            if (isset($options[$flag])) {
                $this->{$flag} = (bool) $options[$flag];
                unset($options[$flag]);
            }
        }

        parent::__construct($value, $options);
    }

    /**
     * @return bool
     */
    public function canWrap()
    {
        return $this->wrap;
    }

    /**
     * Create a new cell object based on this, with a new value.
     *
     * @param string $value
     *
     * @return static
     */
    public function withValue(string $value)
    {
        $options = [
            'colspan' => $this->getColspan(),
            'rowspan' => $this->getRowspan(),
            'wrap' => $this->canWrap(),
        ];

        return new static($value, $options);
    }
}
