<?php declare(strict_types=1);

namespace Platformsh\Cli\Tests;

class CommandRunner
{
    /**
     * @param string   $commandName
     * @param string[] $args
     * @param array    $env
     * @param bool     $allowFailure
     *
     * @return \Platformsh\Cli\Tests\CommandResult
     */
    public function run(string $commandName, array $args = [], array $env = [], bool $allowFailure = false): CommandResult {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $path = realpath(__DIR__ . '/../bin/platform');
        if (!$path) {
            throw new \RuntimeException('Cannot find executable');
        }
        $args = array_merge([$path, $commandName], $args);
        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $process = proc_open($cmd, $descriptorSpec, $pipes, null, $env + getenv());
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if (!$allowFailure && $exitCode !== 0) {
            throw new \RuntimeException(
                "Command failed with exit code $exitCode: $cmd"
                . "\nOutput: $output"
                . "\nError output: $errorOutput"
            );
        }

        return new CommandResult($exitCode, $output, $errorOutput);
    }
}
