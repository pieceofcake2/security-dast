<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

use Closure;
use JsonException;
use RuntimeException;

/**
 * Runs the baseline scan of OWASP ZAP (`zap-baseline.py`) in its Docker image, and reads its JSON report.
 *
 * The baseline scan crawls the site and checks the requests and responses (passive scan): it sends no attacks,
 * so it can run on any environment. ZAP is told the name of the CSRF token of CakePHP 2 (`data[_Token][key]`),
 * so that the forms of `SecurityComponent` are not reported as forms without a token.
 *
 * A site on 127.0.0.1 or localhost is reached from the container with `--network host` on Linux, and through
 * `host.docker.internal` on macOS and Windows (Docker Desktop).
 */
final class Zap
{
    public const IMAGE = 'ghcr.io/zaproxy/zaproxy:stable';

    /**
     * The names of the CSRF tokens of CakePHP 2, added to those that ZAP knows
     */
    public const CSRF_TOKENS = ['data[_Token][key]'];

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
     * @param string $options More options of ZAP (`-z`)
     * @param (\Closure(array<string>, string): array{0: int, 1: string, 2: string})|null $run Runs a command
     * @param string $os Operating system family, `PHP_OS_FAMILY`
     */
    public function __construct(
        private string $image = self::IMAGE,
        private string $docker = 'docker',
        private int $minutes = 1,
        private bool $ajaxSpider = false,
        private string $options = '',
        ?Closure $run = null,
        private string $os = PHP_OS_FAMILY,
    ) {
        $this->run = $run ?? Closure::fromCallable([Process::class, 'run']);
    }

    /**
     * Scans a site
     *
     * @param string $url URL of the site, e.g. `http://127.0.0.1:8123`
     * @param string $language Language of the alerts, e.g. `ja`
     * @return \PieceOfCake2\SecurityDast\ScanResult
     * @throws \RuntimeException When ZAP fails
     */
    public function scan(string $url, string $language = 'en'): ScanResult
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'security-dast-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        // ZAP runs as the user zap of the image
        chmod($dir, 0777);
        try {
            $target = $this->targetFromContainer($url);
            $command = $this->command($dir, $target, $language);
            [$code, $output, $error] = ($this->run)($command, $dir);
            $report = $dir . DIRECTORY_SEPARATOR . 'zap.json';
            if (!is_file($report)) {
                throw new RuntimeException(sprintf('ZAP failed (exit code %d): %s', $code, self::tail($output . "\n" . $error)));
            }
            try {
                $data = json_decode((string)file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException(sprintf('ZAP wrote an invalid report: %s', $e->getMessage()), 0, $e);
            }

            return self::result(is_array($data) ? $data : [], $url, $target);
        } finally {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    /**
     * The command of the scan
     *
     * @param string $dir Directory of the reports, mounted at /zap/wrk
     * @param string $target URL of the site from the container
     * @param string $language Language of the alerts
     * @return array<string>
     */
    public function command(string $dir, string $target, string $language = 'en'): array
    {
        $command = [$this->docker, 'run', '--rm'];
        if ($this->os === 'Linux') {
            $command[] = '--network';
            $command[] = 'host';
        }
        array_push($command, '-v', $dir . ':/zap/wrk:rw', $this->image, 'zap-baseline.py', '-t', $target, '-J', 'zap.json', '-I', '-m', (string)$this->minutes);
        if ($this->ajaxSpider) {
            $command[] = '-j';
        }

        $options = [];
        foreach (self::CSRF_TOKENS as $i => $name) {
            // After the tokens that ZAP knows
            $options[] = sprintf('-config anticsrf.tokens.token(%d).name=%s -config anticsrf.tokens.token(%d).enabled=true', 100 + $i, $name, 100 + $i);
        }
        if (isset(self::LOCALES[$language])) {
            $options[] = '-config view.locale=' . self::LOCALES[$language];
        }
        if ($this->options !== '') {
            $options[] = $this->options;
        }
        array_push($command, '-z', implode(' ', $options));

        return $command;
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
