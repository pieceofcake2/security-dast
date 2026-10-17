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
     * @param array<string, string> $env Environment variables besides those of this process, e.g. a secret that
     *  must not be on the command line
     * @return array{0: int, 1: string, 2: string} Exit code, output and error output
     * @throws \RuntimeException When the command cannot run
     */
    public static function run(array $command, string $dir, array $env = []): array
    {
        $out = tmpfile();
        $err = tmpfile();
        if ($out === false || $err === false) {
            throw new RuntimeException('Cannot create the temporary files of a command.');
        }
        // Files instead of pipes, so that a large output of one does not block the other
        $process = proc_open($command, [1 => $out, 2 => $err], $pipes, $dir, $env === [] ? null : array_merge(getenv(), $env));
        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Cannot run %s.', $command[0]));
        }
        $code = self::wait($process);
        rewind($out);
        rewind($err);
        $output = (string)stream_get_contents($out);
        $error = (string)stream_get_contents($err);
        fclose($out);
        fclose($err);

        return [$code, $output, $error];
    }

    /**
     * Waits until a process ends, a tenth of a second at a time so that a signal is handled meanwhile, and closes it
     *
     * @param resource $process Process of proc_open()
     * @return int Exit code
     */
    public static function wait($process): int
    {
        while (($status = proc_get_status($process))['running']) {
            usleep(100000);
        }
        $code = proc_close($process);

        // The exit code is known to the first proc_get_status() after the end only
        return $status['exitcode'] >= 0 ? $status['exitcode'] : $code;
    }
}
