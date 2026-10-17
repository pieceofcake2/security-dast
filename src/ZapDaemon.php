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
 * request to another port for one to proxy), and the API needs a random key.
 */
final class ZapDaemon
{
    /**
     * @var \Closure(array<string>, string): array{0: int, 1: string, 2: string}
     */
    private Closure $run;

    /**
     * @var \Closure(string): (string|false)
     */
    private Closure $get;

    private ?string $container = null;

    private string $key;

    /**
     * Constructor
     *
     * @param string $dir Directory mounted at /zap/wrk, for the plan and the reports
     * @param array<string> $options Options of ZAP, e.g. `-config key=value`
     * @param string $image Docker image of ZAP
     * @param string $docker Docker command
     * @param string $os Operating system family, `PHP_OS_FAMILY`
     * @param int|null $port Port of the proxy, default: a free one
     * @param (\Closure(array<string>, string): array{0: int, 1: string, 2: string})|null $run Runs a command
     * @param (\Closure(string): (string|false))|null $get Gets a URL of the API
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
        $this->key = bin2hex(random_bytes(16));
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
        [$code, $output, $error] = ($this->run)($this->command(), $this->dir);
        if ($code !== 0) {
            throw new RuntimeException(sprintf('ZAP did not start (exit code %d): %s', $code, trim($error !== '' ? $error : $output)));
        }
        $this->container = trim($output);

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (str_contains((string)($this->get)($this->api('core/view/version')), '"version"')) {
                foreach (Zap::SESSION_COOKIES as $name) {
                    $this->call('httpSessions/action/addDefaultSessionToken', ['sessionToken' => $name]);
                }

                return $this->proxy();
            }
            usleep(500000);
        }
        $this->stop();
        throw new RuntimeException(sprintf('ZAP did not answer on port %d in %d seconds.', $this->port, $timeout));
    }

    /**
     * The command that starts ZAP in the background
     *
     * @return array<string>
     */
    public function command(): array
    {
        $command = [$this->docker, 'run', '-d', '--rm'];
        if ($this->os === 'Linux') {
            array_push($command, '--network', 'host');
            $host = '127.0.0.1';
        } else {
            array_push($command, '-p', sprintf('127.0.0.1:%d:%d', $this->port, $this->port));
            $host = '0.0.0.0';
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
            '-config',
            'api.key=' . $this->key,
            '-config',
            'api.addrs.addr.name=.*',
            '-config',
            'api.addrs.addr.regex=true',
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
     * Runs a plan of the Automation Framework on what ZAP recorded, and waits until it finishes
     *
     * @param array<string, mixed> $plan Plan
     * @param int $timeout Seconds to wait
     * @return void
     * @throws \RuntimeException When the plan fails
     */
    public function runPlan(array $plan, int $timeout = 7200): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'plan.yaml', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $id = $this->call('automation/action/runPlan', ['filePath' => '/zap/wrk/plan.yaml'])['planId'] ?? null;
        if (!is_scalar($id)) {
            throw new RuntimeException('ZAP did not run the plan.');
        }
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $progress = $this->call('automation/view/planProgress', ['planId' => (string)$id]);
            if (($progress['finished'] ?? '') !== '') {
                $errors = array_filter((array)($progress['error'] ?? []), 'is_string');
                if ($errors !== []) {
                    throw new RuntimeException('The plan of ZAP failed: ' . implode(' ', $errors));
                }

                return;
            }
            usleep(2000000);
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
     * Stops ZAP
     *
     * @return void
     */
    public function stop(): void
    {
        if ($this->container !== null) {
            ($this->run)([$this->docker, 'rm', '-f', $this->container], $this->dir);
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
        try {
            $data = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('ZAP did not answer %s: %s', $path, mb_strimwidth((string)$body, 0, 200, '…')), 0, $e);
        }

        return is_array($data) ? $data : [];
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
