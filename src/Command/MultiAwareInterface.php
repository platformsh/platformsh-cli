<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command;

interface MultiAwareInterface
{
    /**
     * Whether the command can be run multiple times in one process.
     *
     * @return bool
     */
    public function canBeRunMultipleTimes(): bool;

    /**
     * @param bool $runningViaMulti
     */
    public function setRunningViaMulti(bool $runningViaMulti = true): void;
}
