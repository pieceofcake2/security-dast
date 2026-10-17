<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

use RuntimeException;

/**
 * Runs the application with PHP's built-in web server on 127.0.0.1, with the router script of this package, for
 * the time of a scan.
 *
 * The web server gets the environment of this process and the variables given, e.g. `APP_ENV=test`, so that the
 * application loads `config/.env.test` and uses the test database.
 */
final class WebServer
{
    public const ROUTER = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'router.php';

    /**
     * @var resource|null
     */
    private $process = null;

    private string $log = '';

    /**
     * Constructor
     *
     * @param string $webroot Document root
     * @param string $workingDir Directory where the application runs, e.g. the project
     * @param array<string, string> $env Environment variables of the web server
     * @param int|null $port Port, default: a free one
     */
    public function __construct(
        private string $webroot,
        private string $workingDir,
        private array $env = [],
        private ?int $port = null,
    ) {
    }

    /**
     * Starts the web server and waits until it answers
     *
     * @param int $timeout Seconds to wait
     * @return string URL of the application, e.g. `http://127.0.0.1:8123`
     * @throws \RuntimeException When the web server does not start
     */
    public function start(int $timeout = 15): string
    {
        if (!is_file($this->webroot . DIRECTORY_SEPARATOR . 'index.php')) {
            throw new RuntimeException(sprintf('%s has no index.php: give the document root with --webroot.', $this->webroot));
        }
        $this->port ??= self::freePort();
        $this->log = (string)tempnam(sys_get_temp_dir(), 'security-dast-server-');
        $env = array_merge(getenv(), $this->env, ['SECURITY_DAST_WORKING_DIR' => (string)realpath($this->workingDir)]);
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', (string)realpath($this->webroot), (string)realpath(self::ROUTER)];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']], $pipes, $this->workingDir, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PHP\'s built-in web server.');
        }
        $this->process = $process;

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $socket = self::connect($this->port);
            if ($socket !== false) {
                fclose($socket);

                return $this->url();
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(100000);
        }
        $log = (string)file_get_contents($this->log);
        $this->stop();
        throw new RuntimeException(sprintf('PHP\'s built-in web server did not start: %s', trim($log)));
    }

    /**
     * The URL of the application
     *
     * @return string
     */
    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /**
     * Stops the web server
     *
     * @return void
     */
    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        if ($this->log !== '' && is_file($this->log)) {
            unlink($this->log);
        }
    }

    /**
     * A connection to a port of 127.0.0.1, or false when nothing listens on it
     *
     * @param int $port Port
     * @return resource|false
     */
    private static function connect(int $port)
    {
        set_error_handler(static fn(): bool => true);
        try {
            return fsockopen('127.0.0.1', $port, $errno, $error, 0.5);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * A free port of 127.0.0.1
     *
     * @return int
     */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return 8765;
        }
        $name = (string)stream_socket_get_name($socket, false);
        fclose($socket);

        return (int)substr((string)strrchr($name, ':'), 1);
    }
}
