<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Support;

/**
 * Runs an external converter on untrusted input: no shell, stdin closed, a wall-clock
 * limit and bounded output. Callers turn the result into their own exceptions.
 */
final class BoundedProcess
{
    private const OUTPUT_LIMIT = 1_048_576;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment  added to the inherited environment
     * @return array{started: bool, missing: bool, timedOut: bool, exit: int|null, stdout: string, stderr: string}
     */
    public static function run(array $command, int $timeoutSeconds, array $environment = []): array
    {
        $env = $environment === [] ? null : [...getenv(), ...$environment];
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (! is_resource($process)) {
            return ['started' => false, 'missing' => true, 'timedOut' => false, 'exit' => null, 'stdout' => '', 'stderr' => ''];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = [1 => '', 2 => ''];
        $deadline = microtime(true) + $timeoutSeconds;
        $exit = null;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 200_000) > 0) {
                foreach ($read as $stream) {
                    $index = $stream === $pipes[1] ? 1 : 2;
                    $chunk = (string) fread($stream, 65536);
                    if (strlen($output[$index]) < self::OUTPUT_LIMIT) {
                        $output[$index] .= $chunk;
                    }
                }
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                break;
            }
        }
        $output[1] .= (string) stream_get_contents($pipes[1]);
        $output[2] .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);

        return [
            'started' => true,
            // 127 is "command not found" from the exec step: the tool is not installed.
            'missing' => $exit === 127 || ($exit === -1 && $closed === 127),
            'timedOut' => $exit === null,
            'exit' => $exit,
            'stdout' => substr($output[1], 0, self::OUTPUT_LIMIT),
            'stderr' => substr($output[2], 0, self::OUTPUT_LIMIT),
        ];
    }
}
