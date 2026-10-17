<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

use Closure;
use JsonException;
use RuntimeException;

/**
 * Runs OWASP ZAP in its Docker image with a plan of its Automation Framework, and reads its JSON report.
 *
 * The baseline scan crawls the site and checks the requests and responses (passive scan): it sends no attacks.
 * The full scan then attacks each page and parameter that it found (active scan), e.g. with scripts for XSS and
 * SQL for SQL injections: run it on a test environment only. With a `Login`, ZAP logs in with a browser and
 * crawls and attacks the pages of the user.
 *
 * ZAP is told the name of the CSRF token of CakePHP 2 (`data[_Token][key]`), so that the forms of
 * `SecurityComponent` are not reported as forms without a token, and that the active scan sends them with a new
 * token.
 *
 * A site on 127.0.0.1 or localhost is reached from the container with `--network host` on Linux, and through
 * `host.docker.internal` on macOS and Windows (Docker Desktop).
 */
final class Zap
{
    public const IMAGE = 'ghcr.io/zaproxy/zaproxy:stable';

    /**
     * The scans: `baseline` (crawl and passive scan) and `full` (with the active scan)
     */
    public const SCANS = ['baseline', 'full'];

    /**
     * The URLs left out of the scan by default: the logout, so that the user stays logged in
     */
    public const EXCLUDE = ['.*logout.*'];

    /**
     * The names of the CSRF tokens of CakePHP 2, added to those that ZAP knows
     */
    public const CSRF_TOKENS = ['data[_Token][key]'];

    /**
     * The fields of the forms of SecurityComponent that the active scan leaves alone: their values are checked by
     * the hash of data[_Token][fields], so an attack on them is only black-holed
     */
    public const LOCKED_FIELDS = ['_method', 'data[_Token][fields]', 'data[_Token][unlocked]', 'data[_Token][debug]'];

    /**
     * The locales of ZAP for the languages of the report
     */
    public const LOCALES = ['ja' => 'ja_JP'];

    /**
     * @var \Closure(array<string>, string): array{0: int, 1: string, 2: string}
     */
    private Closure $run;

    /**
     * Constructor
     *
     * @param string $image Docker image of ZAP
     * @param string $docker Docker command
     * @param int $minutes Minutes to crawl the site
     * @param bool $ajaxSpider Whether to crawl with a browser too, for the pages that JavaScript builds
     * @param string $options More options of ZAP, e.g. `-config key=value`
     * @param (\Closure(array<string>, string): array{0: int, 1: string, 2: string})|null $run Runs a command
     * @param string $os Operating system family, `PHP_OS_FAMILY`
     * @param string $scan `baseline` or `full`
     * @param int $scanMinutes Most minutes of the active scan of the full scan
     * @param \PieceOfCake2\SecurityDast\Login|null $login User to log in as
     * @param array<string> $exclude Regular expressions of the URLs to leave out, of Java
     */
    public function __construct(
        private string $image = self::IMAGE,
        private string $docker = 'docker',
        private int $minutes = 1,
        private bool $ajaxSpider = false,
        private string $options = '',
        ?Closure $run = null,
        private string $os = PHP_OS_FAMILY,
        private string $scan = 'baseline',
        private int $scanMinutes = 10,
        private ?Login $login = null,
        private array $exclude = self::EXCLUDE,
    ) {
        $this->run = $run ?? Closure::fromCallable([Process::class, 'run']);
    }

    /**
     * Scans a site
     *
     * @param string $url URL of the site, e.g. `http://127.0.0.1:8123`
     * @param string $language Language of the alerts, e.g. `ja`
     * @return \PieceOfCake2\SecurityDast\ScanResult
     * @throws \RuntimeException When ZAP fails, or cannot log in
     */
    public function scan(string $url, string $language = 'en'): ScanResult
    {
        $dir = self::workDir();
        try {
            $target = $this->targetFromContainer($url);
            // JSON is YAML too
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'plan.yaml', json_encode($this->plan($target), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            [$code, $output, $error] = ($this->run)($this->command($dir, $language), $dir);
            if (!is_file($dir . DIRECTORY_SEPARATOR . 'zap.json')) {
                throw new RuntimeException(sprintf('ZAP failed (exit code %d): %s', $code, self::tail($output . "\n" . $error)));
            }

            return $this->reports($dir, $url, $target);
        } finally {
            self::removeDir($dir);
        }
    }

    /**
     * Scans what a scenario does on a site, e.g. End-to-End tests: ZAP runs as a proxy while the scenario runs, then
     * checks (and attacks, for the full scan) the requests that it recorded
     *
     * @param string $url URL of the site, e.g. `http://127.0.0.1:8123`
     * @param \Closure(string, string): void $scenario Runs the scenario with the URL of the proxy, and the URL of the
     *  site to use through it
     * @param string $language Language of the alerts
     * @param \Closure(string, array<string>): \PieceOfCake2\SecurityDast\ZapDaemon|null $daemon Creates the daemon
     *  with the directory of the plan and the options of ZAP
     * @return \PieceOfCake2\SecurityDast\ScanResult
     * @throws \RuntimeException When ZAP fails, or cannot log in
     */
    public function scanScenario(string $url, Closure $scenario, string $language = 'en', ?Closure $daemon = null): ScanResult
    {
        $dir = self::workDir();
        $daemon = $daemon !== null
            ? $daemon($dir, $this->zapOptions($language))
            : new ZapDaemon($dir, $this->zapOptions($language), $this->image, $this->docker, $this->os, run: $this->run);
        try {
            $proxy = $daemon->start();
            $target = $this->targetFromContainer($url);
            $scenario($proxy, $target);
            $daemon->runPlan($this->plan($target, false));

            return $this->reports($dir, $url, $target);
        } finally {
            $daemon->stop();
            self::removeDir($dir);
        }
    }

    /**
     * The result of the reports of ZAP in a directory
     *
     * @param string $dir Directory of the reports
     * @param string $url URL of the site as given
     * @param string $target URL of the site from the container
     * @return \PieceOfCake2\SecurityDast\ScanResult
     * @throws \RuntimeException When there is no report, or ZAP could not log in
     */
    private function reports(string $dir, string $url, string $target): ScanResult
    {
        $report = $dir . DIRECTORY_SEPARATOR . 'zap.json';
        if (!is_file($report)) {
            throw new RuntimeException('ZAP wrote no report.');
        }
        $result = self::result(self::json($report), $url, $target);
        if ($this->login !== null) {
            $auth = $dir . DIRECTORY_SEPARATOR . 'auth.json';
            if (!self::loggedIn(is_file($auth) ? self::json($auth) : [])) {
                throw new RuntimeException(sprintf('ZAP could not log in as %s on %s: check the user name, the password, and that the user is in the database of the application.', $this->login->username, $this->login->urlOn($url)));
            }
            $result->user = $this->login->username;
        }

        return $result;
    }

    /**
     * A new directory for the plan and the reports, that ZAP (the user zap of the image) can write
     *
     * @return string
     */
    private static function workDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'security-dast-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        chmod($dir, 0777);

        return $dir;
    }

    /**
     * Removes a directory of `workDir()`
     *
     * @param string $dir Directory
     * @return void
     */
    private static function removeDir(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }

    /**
     * The command of the scan
     *
     * @param string $dir Directory of the plan and the reports, mounted at /zap/wrk
     * @param string $language Language of the alerts
     * @return array<string>
     */
    public function command(string $dir, string $language = 'en'): array
    {
        $command = [$this->docker, 'run', '--rm'];
        if ($this->os === 'Linux') {
            $command[] = '--network';
            $command[] = 'host';
        }
        array_push($command, '-v', $dir . ':/zap/wrk:rw', $this->image, 'zap.sh', '-cmd', '-autorun', '/zap/wrk/plan.yaml', ...$this->zapOptions($language));

        return $command;
    }

    /**
     * The options of ZAP: the CSRF tokens of CakePHP, the locale, and the options given
     *
     * @param string $language Language of the alerts
     * @return array<string>
     */
    public function zapOptions(string $language = 'en'): array
    {
        // Only the whole names: _token of the names of ZAP would match data[_Token][fields] and
        // data[_Token][unlocked] of SecurityComponent too, which are not new in each form, so ZAP would not get a new
        // data[_Token][key] for its attacks, and SecurityComponent would black-hole them
        $options = ['-config', 'anticsrf.partialMatchingEnabled=false'];
        foreach (self::CSRF_TOKENS as $i => $name) {
            // After the tokens that ZAP knows
            array_push($options, '-config', sprintf('anticsrf.tokens.token(%d).name=%s', 100 + $i, $name), '-config', sprintf('anticsrf.tokens.token(%d).enabled=true', 100 + $i));
        }
        array_push($options, '-config', 'scanner.excludeAntiCsrfTokens=true');
        foreach (self::LOCKED_FIELDS as $i => $name) {
            array_push($options, '-config', sprintf('scanner.excludedParameters(%d).name=%s', $i, $name), '-config', sprintf('scanner.excludedParameters(%d).type=-1', $i));
        }
        if (isset(self::LOCALES[$language])) {
            array_push($options, '-config', 'view.locale=' . self::LOCALES[$language]);
        }
        foreach (preg_split('/\s+/', trim($this->options)) ?: [] as $option) {
            if ($option !== '') {
                $options[] = $option;
            }
        }

        return $options;
    }

    /**
     * The plan of the Automation Framework of ZAP
     *
     * @param string $target URL of the site from the container
     * @param bool $crawl Whether to crawl the site, or only check what ZAP recorded
     * @return array<string, mixed>
     */
    public function plan(string $target, bool $crawl = true): array
    {
        $target = rtrim($target, '/');
        $context = [
            'name' => 'app',
            'urls' => [$target],
            'includePaths' => ['\\Q' . $target . '\\E.*'],
            'excludePaths' => array_values($this->exclude),
        ];
        $user = [];
        if ($this->login !== null) {
            $context['authentication'] = [
                'method' => 'browser',
                'parameters' => ['loginPageUrl' => $this->login->urlOn($target), 'loginPageWait' => 5, 'browserId' => 'firefox-headless'],
                'verification' => ['method' => 'autodetect'],
            ];
            $context['sessionManagement'] = ['method' => 'autodetect'];
            $context['users'] = [['name' => 'user', 'credentials' => ['username' => $this->login->username, 'password' => $this->login->password]]];
            $user = ['user' => 'user'];
        }

        $jobs = [];
        if ($crawl) {
            $jobs[] = ['type' => 'spider', 'parameters' => ['context' => 'app'] + $user + ['maxDuration' => $this->minutes]];
        }
        if ($crawl && $this->ajaxSpider) {
            $jobs[] = ['type' => 'spiderAjax', 'parameters' => ['context' => 'app'] + $user + ['maxDuration' => $this->minutes, 'browserId' => 'firefox-headless']];
        }
        $jobs[] = ['type' => 'passiveScan-wait'];
        if ($this->scan === 'full') {
            $jobs[] = ['type' => 'activeScan', 'parameters' => ['context' => 'app'] + $user + ['maxScanDurationInMins' => $this->scanMinutes]];
            // The responses of the attacks
            $jobs[] = ['type' => 'passiveScan-wait'];
        }
        $jobs[] = ['type' => 'report', 'parameters' => ['template' => 'traditional-json', 'reportDir' => '/zap/wrk', 'reportFile' => 'zap']];
        if ($this->login !== null) {
            $jobs[] = ['type' => 'report', 'parameters' => ['template' => 'auth-report-json', 'reportDir' => '/zap/wrk', 'reportFile' => 'auth']];
        }

        return [
            'env' => ['contexts' => [$context], 'parameters' => ['failOnError' => true, 'progressToStdout' => true]],
            'jobs' => $jobs,
        ];
    }

    /**
     * Whether the authentication report of ZAP says that it logged in
     *
     * @param array<string, mixed> $data JSON of the report `auth-report-json`
     * @return bool
     */
    public static function loggedIn(array $data): bool
    {
        foreach (is_array($data['summaryItems'] ?? null) ? $data['summaryItems'] : [] as $item) {
            if (is_array($item) && ($item['key'] ?? null) === 'auth.summary.auth') {
                return ($item['passed'] ?? false) === true;
            }
        }

        return false;
    }

    /**
     * The data of a JSON file
     *
     * @param string $file File
     * @return array<string, mixed>
     * @throws \RuntimeException When it is not JSON
     */
    private static function json(string $file): array
    {
        try {
            $data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('ZAP wrote an invalid report: %s', $e->getMessage()), 0, $e);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * The URL of a site from the container: 127.0.0.1 and localhost are the host
     *
     * @param string $url URL
     * @return string
     */
    public function targetFromContainer(string $url): string
    {
        if ($this->os === 'Linux') {
            return $url;
        }

        return (string)preg_replace('#\A(https?://)(?:127\.0\.0\.1|localhost)(?=[:/]|\z)#i', '$1host.docker.internal', $url);
    }

    /**
     * The result of the JSON report of ZAP, with the URLs of the site as given
     *
     * @param array<string, mixed> $data JSON
     * @param string $url URL of the site as given
     * @param string|null $target URL of the site from the container, default: the same
     * @return \PieceOfCake2\SecurityDast\ScanResult
     */
    public static function result(array $data, string $url, ?string $target = null): ScanResult
    {
        $target ??= $url;
        $base = static fn(string $uri): string => str_starts_with($uri, rtrim($target, '/')) ? rtrim($url, '/') . substr($uri, strlen(rtrim($target, '/'))) : $uri;
        $alerts = [];
        foreach (is_array($data['site'] ?? null) ? $data['site'] : [] as $site) {
            foreach (is_array($site['alerts'] ?? null) ? $site['alerts'] : [] as $alert) {
                if (!is_array($alert)) {
                    continue;
                }
                $instances = [];
                foreach (is_array($alert['instances'] ?? null) ? $alert['instances'] : [] as $instance) {
                    $instances[] = [
                        'method' => (string)($instance['method'] ?? ''),
                        'uri' => $base((string)($instance['uri'] ?? '')),
                        'param' => (string)($instance['param'] ?? ''),
                        'attack' => (string)($instance['attack'] ?? ''),
                        'evidence' => (string)($instance['evidence'] ?? ''),
                        'otherinfo' => self::text((string)($instance['otherinfo'] ?? '')),
                    ];
                }
                $cwe = (int)($alert['cweid'] ?? -1);
                $wasc = (int)($alert['wascid'] ?? -1);
                $alerts[] = new Alert(
                    (string)($alert['alertRef'] ?? $alert['pluginid'] ?? ''),
                    (string)($alert['name'] ?? $alert['alert'] ?? ''),
                    Severity::fromRiskCode($alert['riskcode'] ?? 0),
                    Alert::CONFIDENCES[(int)($alert['confidence'] ?? 2)] ?? '',
                    self::text((string)($alert['desc'] ?? '')),
                    self::text((string)($alert['solution'] ?? '')),
                    self::links((string)($alert['reference'] ?? '')),
                    $cwe > 0 ? $cwe : null,
                    $wasc > 0 ? $wasc : null,
                    $instances,
                );
            }
        }
        usort($alerts, static fn(Alert $a, Alert $b): int => [array_search($a->severity, Severity::ALL, true), $a->name] <=> [array_search($b->severity, Severity::ALL, true), $b->name]);

        return new ScanResult($alerts, $url, is_string($data['@version'] ?? null) ? $data['@version'] : null);
    }

    /**
     * The text of the HTML of ZAP: paragraphs on lines of their own
     *
     * @param string $html HTML
     * @return string
     */
    public static function text(string $html): string
    {
        $text = (string)preg_replace('#<br\s*/?>|</p>\s*#i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace("/[ \t]+\n/", "\n", $text);

        return trim((string)preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * The links of the references of ZAP
     *
     * @param string $html HTML
     * @return array<string>
     */
    public static function links(string $html): array
    {
        preg_match_all('#https?://[^\s<>"\']+#i', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * The last lines of an output
     *
     * @param string $output Output
     * @return string
     */
    private static function tail(string $output): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $output)), static fn(string $line): bool => $line !== '');

        return implode("\n", array_slice($lines, -10));
    }
}
