<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

use RuntimeException;

/**
 * Runs a command and returns its exit code and outputs.
 */
final class Process
{
    /**
     * Runs a command
     *
     * @param array<string> $command Command and arguments
     * @param string $dir Working directory
     * @return array{0: int, 1: string, 2: string} Exit code, output and error output
     * @throws \RuntimeException When the command cannot run
     */
    public static function run(array $command, string $dir): array
    {
        $out = tmpfile();
        $err = tmpfile();
        if ($out === false || $err === false) {
            throw new RuntimeException('Cannot create the temporary files of a command.');
        }
        // Files instead of pipes, so that a large output of one does not block the other
        $process = proc_open($command, [1 => $out, 2 => $err], $pipes, $dir);
        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Cannot run %s.', $command[0]));
        }
        $code = proc_close($process);
        rewind($out);
        rewind($err);
        $output = (string)stream_get_contents($out);
        $error = (string)stream_get_contents($err);
        fclose($out);
        fclose($err);

        return [$code, $output, $error];
    }
}
