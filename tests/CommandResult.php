<?php declare(strict_types=1);

namespace Platformsh\Cli\Tests;

class CommandResult
{
    private $exitCode;
    private $stdout;
    private $stderr;

    public function __construct(int $exitCode, string $stdout, string $stdErr) {
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stdErr;
    }

    public function isSuccess(): bool {
        return $this->exitCode === 0;
    }

    public function getOutput(): string {
        return $this->stdout;
    }

    public function getErrorOutput(): string {
        return $this->stderr;
    }
}
