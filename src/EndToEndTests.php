<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

use RuntimeException;

/**
 * The End-to-End tests of the project with pieceofcake2/panther (or symfony/panther), run as the scenario of a scan: the
 * Chrome of the tests goes through the proxy of ZAP (`PANTHER_CHROME_ARGUMENTS`), and the tests use the
 * application of the scan instead of a web server of their own (`PANTHER_EXTERNAL_BASE_URI`).
 */
final class EndToEndTests
{
    /**
     * @var resource|null The process of the tests while they run
     */
    private $process = null;

    /**
     * Constructor
     *
     * @param string $command Command of the tests, e.g. `vendor/bin/phpunit --testsuite EndToEnd`
     * @param string $workingDir Directory where the command runs, e.g. the project
     * @param array<string, string> $env Environment variables of the command, besides those of this process
     */
    public function __construct(
        private string $command,
        private string $workingDir,
        private array $env = [],
    ) {
    }

    /**
     * The environment variables that send the tests through the proxy, to the site
     *
     * @param string $proxy URL of the proxy
     * @param string $site URL of the site, as the proxy reaches it
     * @return array<string, string>
     */
    public function env(string $proxy, string $site): array
    {
        $arguments = (string)($this->env['PANTHER_CHROME_ARGUMENTS'] ?? getenv('PANTHER_CHROME_ARGUMENTS') ?: '');
        // Chrome does not use a proxy for 127.0.0.1 and localhost unless <-loopback> is given; ZAP signs the pages of
        // HTTPS with a certificate of its own
        $arguments = trim($arguments . ' --proxy-server=' . $proxy . ' --proxy-bypass-list=<-loopback> --ignore-certificate-errors');

        return ['PANTHER_EXTERNAL_BASE_URI' => rtrim($site, '/'), 'PANTHER_CHROME_ARGUMENTS' => $arguments];
    }

    /**
     * Runs the tests, and copies their output
     *
     * @param string $proxy URL of the proxy
     * @param string $site URL of the site, as the proxy reaches it
     * @param resource $output Output of the tests
     * @return int Exit code of the tests
     * @throws \RuntimeException When the command cannot run
     */
    public function run(string $proxy, string $site, $output): int
    {
        $env = array_merge(self::inherited(), $this->env, $this->env($proxy, $site));
        $process = proc_open($this->command . ' 2>&1', [0 => ['file', self::nullDevice(), 'r'], 1 => ['pipe', 'w']], $pipes, $this->workingDir, $env);
        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Cannot run the End-to-End tests: %s', $this->command));
        }
        // Stopped at the end of the process also when it is stopped, e.g. with Ctrl+C
        $this->process = $process;
        register_shutdown_function([$this, 'stop']);
        // A wait of a second at most, so that a signal is handled while the tests run
        stream_set_blocking($pipes[1], false);
        // stream_select() warns when a signal interrupts it
        set_error_handler(static fn(): bool => true);
        try {
            while (!feof($pipes[1])) {
                $read = [$pipes[1]];
                $write = $except = null;
                if (stream_select($read, $write, $except, 1) > 0) {
                    $chunk = fread($pipes[1], 8192);
                    if ($chunk !== false && $chunk !== '') {
                        fwrite($output, $chunk);
                    }
                }
            }
        } finally {
            restore_error_handler();
        }
        fclose($pipes[1]);
        $this->process = null;

        return Process::wait($process);
    }

    /**
     * Stops the tests when they run
     *
     * @return void
     */
    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            // The command runs in a shell: its children first, e.g. PHPUnit
            if (PHP_OS_FAMILY !== 'Windows') {
                exec('pkill -TERM -P ' . $status['pid'] . ' 2>/dev/null');
            }
            proc_terminate($this->process);
        }
        $this->process = null;
    }

    /**
     * The environment variables of this process for a command of the project: without the password of the login,
     * which the tests and the application do not need
     *
     * @return array<string, string>
     */
    public static function inherited(): array
    {
        $env = getenv();
        unset($env['SECURITY_DAST_PASSWORD'], $env[Zap::PASSWORD_ENV]);

        return $env;
    }

    /**
     * The null device of the system
     *
     * @return string
     */
    public static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    /**
     * The command of the tests
     *
     * @return string
     */
    public function command(): string
    {
        return $this->command;
    }
}
