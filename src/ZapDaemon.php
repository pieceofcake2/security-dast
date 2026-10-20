<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

use Closure;
use JsonException;
use RuntimeException;

/**
 * ZAP running as a proxy in its Docker image, for the time of a scan of a scenario: the browser of the End-to-End tests
 * goes through it, so that ZAP records their requests, and the plan of the scan then runs on what it recorded.
 *
 * The proxy and the API listen on 127.0.0.1 only, on the same port in and out of the container (ZAP takes a
 * request to another port for one to proxy), and the API needs a random key. The key is given in a file of the
 * private directory of the scan, removed once ZAP has started, so that it is not on the command line, which the other
 * users of the host can see.
 */
final class ZapDaemon
{
    /**
     * Seconds that the progress of a plan may go unanswered, e.g. while the active scan keeps ZAP busy
     */
    public const UNANSWERED = 300;

    /**
     * @var \Closure(array<string>, string, array<string, string>): array{0: int, 1: string, 2: string}
     */
    private Closure $run;

    /**
     * @var \Closure(string): (string|false)
     */
    private Closure $get;

    /**
     * @var \Closure(int): void
     */
    private Closure $sleep;

    private ?string $container = null;

    private string $key;

    /**
     * The name of the file of the key in the directory
     */
    private string $keyFile;

    /**
     * Constructor
     *
     * @param string $dir Directory mounted at /zap/wrk, for the plan and the reports
     * @param array<string> $options Options of ZAP, e.g. `-config key=value`
     * @param string $image Docker image of ZAP
     * @param string $docker Docker command
     * @param string $os Operating system family, `PHP_OS_FAMILY`
     * @param int|null $port Port of the proxy, default: a free one
     * @param (\Closure(array<string>, string, array<string, string>): array{0: int, 1: string, 2: string})|null $run Runs a
     *  command, with environment variables
     * @param (\Closure(string): (string|false))|null $get Gets a URL of the API
     * @param array<string, string> $env Environment variables of ZAP, e.g. the password of the login
     * @param (\Closure(int): void)|null $sleep Waits for microseconds
     * @param int $unanswered Seconds that the progress of a plan may go unanswered
     */
    public function __construct(
        private string $dir,
        private array $options = [],
        private string $image = Zap::IMAGE,
        private string $docker = 'docker',
        private string $os = PHP_OS_FAMILY,
        private ?int $port = null,
        ?Closure $run = null,
        ?Closure $get = null,
        private array $env = [],
        ?Closure $sleep = null,
        private int $unanswered = self::UNANSWERED,
    ) {
        $this->run = $run ?? Closure::fromCallable([Process::class, 'run']);
        $this->get = $get ?? static function (string $url): string|false {
            set_error_handler(static fn(): bool => true);
            try {
                return file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]));
            } finally {
                restore_error_handler();
            }
        };
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->key = bin2hex(random_bytes(16));
        $this->keyFile = 'key-' . bin2hex(random_bytes(8)) . '.properties';
    }

    /**
     * Starts ZAP and waits until its API answers
     *
     * @param int $timeout Seconds to wait
     * @return string URL of the proxy, e.g. `http://127.0.0.1:8090`
     * @throws \RuntimeException When ZAP does not start
     */
    public function start(int $timeout = 180): string
    {
        $this->port ??= WebServer::freePort();
        $keyFile = $this->dir . DIRECTORY_SEPARATOR . $this->keyFile;
        file_put_contents($keyFile, 'api.key=' . $this->key . "\n");
        // Only the user, as whom ZAP runs (Zap::containerUser())
        chmod($keyFile, 0600);
        try {
            [$code, $output, $error] = ($this->run)($this->command(), $this->dir, $this->env);
            if ($code !== 0) {
                throw new RuntimeException(sprintf('ZAP did not start (exit code %d): %s', $code, trim($error !== '' ? $error : $output)));
            }
            $this->container = trim($output);
            // Stopped at the end of the process also when it is stopped, e.g. with Ctrl+C
            register_shutdown_function([$this, 'stop']);

            $answered = $this->waitForApi($timeout);
        } finally {
            if (is_file($keyFile)) {
                unlink($keyFile);
            }
        }
        if ($answered) {
            foreach (Zap::SESSION_COOKIES as $name) {
                $this->call('httpSessions/action/addDefaultSessionToken', ['sessionToken' => $name]);
            }

            return $this->proxy();
        }
        $this->stop();
        throw new RuntimeException(sprintf('ZAP did not answer on port %d in %d seconds.', $this->port, $timeout));
    }

    /**
     * Waits until the API of ZAP answers
     *
     * @param int $timeout Seconds to wait
     * @return bool Whether it answered
     */
    private function waitForApi(int $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (str_contains((string)($this->get)($this->api('core/view/version')), '"version"')) {
                return true;
            }
            ($this->sleep)(500000);
        }

        return false;
    }

    /**
     * The command that starts ZAP in the background
     *
     * @return array<string>
     */
    public function command(): array
    {
        // Without --rm, so that a container that exited still tells why (diagnosis()); stop() removes it
        $command = [$this->docker, 'run', '-d'];
        if ($this->os === 'Linux') {
            array_push($command, '--network', 'host');
            $host = '127.0.0.1';
        } else {
            array_push($command, '-p', sprintf('127.0.0.1:%d:%d', $this->port, $this->port));
            $host = '0.0.0.0';
        }
        array_push($command, ...Zap::containerUser($this->os));
        // The names only: the values come from the environment of the command
        foreach (array_keys($this->env) as $name) {
            array_push($command, '-e', $name);
        }
        array_push(
            $command,
            '-v',
            $this->dir . ':/zap/wrk:rw',
            $this->image,
            'zap.sh',
            '-daemon',
            '-host',
            $host,
            '-port',
            (string)$this->port,
            '-configfile',
            '/zap/wrk/' . $this->keyFile,
            '-config',
            'api.addrs.addr.name=.*',
            '-config',
            'api.addrs.addr.regex=true',
            ...Zap::zapHome($this->os),
            ...$this->options,
        );

        return $command;
    }

    /**
     * The URL of the proxy
     *
     * @return string
     */
    public function proxy(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /**
     * Runs a plan of the Automation Framework on what ZAP recorded, and waits until it finishes. ZAP may leave the
     * progress unanswered for a while (`$unanswered`), e.g. when the active scan keeps it busy.
     *
     * @param array<string, mixed> $plan Plan
     * @param int $timeout Seconds to wait
     * @return void
     * @throws \RuntimeException When the plan fails, or ZAP stops answering
     */
    public function runPlan(array $plan, int $timeout = 7200): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'plan.yaml', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $id = $this->call('automation/action/runPlan', ['filePath' => '/zap/wrk/plan.yaml'])['planId'] ?? null;
        if (!is_scalar($id)) {
            throw new RuntimeException('ZAP did not run the plan.');
        }
        $deadline = microtime(true) + $timeout;
        $answered = microtime(true);
        while (microtime(true) < $deadline) {
            $body = ($this->get)($this->api('automation/view/planProgress', ['planId' => (string)$id]));
            $progress = self::decode($body);
            if ($progress === null) {
                if (microtime(true) - $answered >= $this->unanswered) {
                    throw new RuntimeException(sprintf('ZAP did not answer automation/view/planProgress for %d seconds%s.%s', $this->unanswered, self::excerpt($body), $this->diagnosis()));
                }
                ($this->sleep)(2000000);
                continue;
            }
            $answered = microtime(true);
            if (($progress['finished'] ?? '') !== '') {
                $errors = array_filter((array)($progress['error'] ?? []), 'is_string');
                if ($errors !== []) {
                    throw new RuntimeException('The plan of ZAP failed: ' . implode(' ', $errors));
                }

                return;
            }
            ($this->sleep)(2000000);
        }
        throw new RuntimeException(sprintf('The plan of ZAP did not finish in %d seconds.', $timeout));
    }

    /**
     * Makes the last session of a site the one of the requests of ZAP, e.g. of its attacks: ZAP gets the new CSRF
     * token of an attack from the page that had it, and the token must be of the session of the attack, where
     * SecurityComponent keeps its tokens. The page may have been the first of the scenario, without a session cookie
     * yet.
     *
     * @param string $site URL of the site
     * @return void
     */
    public function useLastSession(string $site): void
    {
        $host = (string)parse_url($site, PHP_URL_HOST);
        $port = parse_url($site, PHP_URL_PORT);
        $host .= ':' . ($port ?? (str_starts_with($site, 'https:') ? 443 : 80));
        $sessions = $this->call('httpSessions/view/sessions', ['site' => $host])['sessions'] ?? [];
        $last = is_array($sessions) && $sessions !== [] ? end($sessions) : null;
        $name = is_array($last) ? ($last['session'][0] ?? null) : null;
        if (is_string($name)) {
            $this->call('httpSessions/action/setActiveSession', ['site' => $host, 'session' => $name]);
        }
    }

    /**
     * Whether the container of ZAP still runs, and the last lines of its log, for a ZAP that stopped answering: the
     * log is `zap.log` of the home of ZAP in the directory (on Linux, `Zap::zapHome()`), or the output of the container
     *
     * @return string
     */
    private function diagnosis(): string
    {
        if ($this->container === null) {
            return '';
        }
        $tmp = sys_get_temp_dir();
        [$code, $output] = ($this->run)([$this->docker, 'inspect', '--format', '{{.State.Status}} {{.State.ExitCode}} {{.State.OOMKilled}}', $this->container], $tmp, []);
        [$status, $exitCode, $oomKilled] = explode(' ', trim($output)) + ['', '', ''];
        if ($code !== 0 || $status === '') {
            $text = "\nThe container of ZAP is gone.";
        } elseif ($status === 'running') {
            $text = "\nThe container of ZAP is still running.";
        } else {
            $text = sprintf("\nThe container of ZAP is not running: %s, exit code %s%s.", $status, $exitCode, $oomKilled === 'true' ? ', OOMKilled' : '');
        }

        $file = $this->dir . DIRECTORY_SEPARATOR . 'home' . DIRECTORY_SEPARATOR . '.ZAP' . DIRECTORY_SEPARATOR . 'zap.log';
        if (is_file($file)) {
            $name = 'zap.log';
            // The end only: the log of a long scan is large
            $log = (string)file_get_contents($file, false, null, max(0, (int)filesize($file) - 65536));
        } else {
            $name = 'the output of the container';
            [, $output, $error] = ($this->run)([$this->docker, 'logs', '--tail', '100', $this->container], $tmp, []);
            $log = $output . "\n" . $error;
        }
        $lines = array_filter(array_map('rtrim', explode("\n", $log)), static fn(string $line): bool => $line !== '');
        if ($lines === []) {
            return $text;
        }
        $secrets = array_filter([$this->key, ...array_values($this->env)], static fn(string $secret): bool => $secret !== '');

        return $text . sprintf("\nThe last lines of %s:\n", $name) . str_replace($secrets, '***', implode("\n", array_slice($lines, -20)));
    }

    /**
     * Stops ZAP
     *
     * @return void
     */
    public function stop(): void
    {
        if ($this->container !== null) {
            // Not in the directory, which may be removed already at the end of the process
            ($this->run)([$this->docker, 'rm', '-f', $this->container], sys_get_temp_dir(), []);
        }
        $this->container = null;
    }

    /**
     * The answer of an API call
     *
     * @param string $path Path of the call, e.g. `core/view/version`
     * @param array<string, string> $params Parameters
     * @return array<string, mixed>
     * @throws \RuntimeException When ZAP does not answer with JSON
     */
    private function call(string $path, array $params = []): array
    {
        $body = ($this->get)($this->api($path, $params));
        $data = self::decode($body);
        if ($data === null) {
            throw new RuntimeException(sprintf('ZAP did not answer %s%s.', $path, self::excerpt($body)));
        }

        return $data;
    }

    /**
     * The data of an answer of the API
     *
     * @param string|false $body Body of the answer, false without an answer
     * @return array<string, mixed>|null Null when it is not JSON
     */
    private static function decode(string|false $body): ?array
    {
        try {
            $data = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : [];
    }

    /**
     * The beginning of an answer that is not JSON, for a message
     *
     * @param string|false $body Body of the answer
     * @return string `: ` and the beginning, or nothing without a body
     */
    private static function excerpt(string|false $body): string
    {
        $body = trim((string)$body);

        return $body !== '' ? ': ' . mb_strimwidth($body, 0, 200, '…') : '';
    }

    /**
     * The URL of an API call
     *
     * @param string $path Path of the call
     * @param array<string, string> $params Parameters
     * @return string
     */
    private function api(string $path, array $params = []): string
    {
        return $this->proxy() . '/JSON/' . $path . '/?' . http_build_query(['apikey' => $this->key] + $params);
    }
}
