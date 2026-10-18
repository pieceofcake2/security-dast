<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PieceOfCake2\Report\Environment;
use PieceOfCake2\Report\ReportWriter;
use Throwable;

/**
 * The command `security-dast`: runs the application with PHP's built-in web server (or takes the URL of a running
 * one), scans it with the baseline scan of OWASP ZAP and writes the PDF report.
 *
 * The exit code is 0 when no alert reaches `--fail-on`, 1 when one does, and 2 when the scan or the report fails.
 */
final class Command
{
    public const SUCCESS = 0;
    public const FAILURE = 1;
    public const ERROR = 2;

    /**
     * Constructor
     *
     * @param resource $stdout Output
     * @param resource $stderr Error output
     * @param \PieceOfCake2\SecurityDast\Zap|null $zap ZAP, default: with the settings
     * @param \PieceOfCake2\Report\ReportWriter|null $writer Writer of the PDF
     * @param \PieceOfCake2\Report\Environment|null $environment Environment, default: detected
     * @param (\Closure(string, array<string>): \PieceOfCake2\SecurityDast\ZapDaemon)|null $daemon Creates the ZAP of
     *  the End-to-End tests
     */
    public function __construct(
        private $stdout = STDOUT,
        private $stderr = STDERR,
        private ?Zap $zap = null,
        private ?ReportWriter $writer = null,
        private ?Environment $environment = null,
        private ?Closure $daemon = null,
    ) {
    }

    /**
     * Runs the command
     *
     * @param array<string> $argv Arguments, without the name of the command
     * @param array<string, mixed> $env Environment variables, e.g. `$_SERVER`
     * @return int Exit code
     */
    public function run(array $argv, array $env = []): int
    {
        try {
            $config = self::config($argv, $env);
        } catch (Throwable $e) {
            fwrite($this->stderr, $e->getMessage() . "\n\n" . self::usage());

            return self::ERROR;
        }
        if ($config === null) {
            fwrite($this->stdout, self::usage());

            return self::SUCCESS;
        }

        $server = null;
        $signals = self::handleSignals();
        try {
            $timezone = new DateTimeZone(date_default_timezone_get());
            $startedAt = new DateTimeImmutable('now', $timezone);
            $url = $config->url;
            if ($url === null) {
                $server = new WebServer($config->webroot(), $config->project, ['APP_ENV' => $config->appEnv]);
                $url = $server->start();
            }
            $zap = $this->zap ?? $config->zap();
            if ($config->endToEnd !== null) {
                // The output of the tests goes to the error output, so that the output stays the summary
                $tests = new EndToEndTests($config->endToEnd, $config->project, ['APP_ENV' => $config->appEnv]);
                $exitCode = null;
                $stderr = $this->stderr;
                $result = $zap->scanScenario($url, static function (string $proxy, string $site) use ($tests, &$exitCode, $stderr): void {
                    $exitCode = $tests->run($proxy, $site, $stderr);
                }, $config->language, $this->daemon);
                $result->endToEndExitCode = $exitCode;
            } else {
                $result = $zap->scan($url, $config->language);
            }
            $result->ignore($config->ignore);
            $server?->stop();
            $server = null;
            $finishedAt = new DateTimeImmutable('now', $timezone);

            $translator = ReportBuilder::translator($config->language, $config->localeDir);
            $doc = (new ReportBuilder($translator))->build($result, $config, $this->environment ?? Environment::detect($config->project), $startedAt, $finishedAt);
            ($this->writer ?? new ReportWriter())->write($doc, $config->pdf);
        } catch (Throwable $e) {
            $server?->stop();
            fwrite($this->stderr, 'security-dast: ' . $e->getMessage() . "\n");

            return self::ERROR;
        } finally {
            self::restoreSignals($signals);
        }

        $summary = [];
        foreach ($result->counts() as $severity => $count) {
            if ($count > 0) {
                $summary[] = $count . ' ' . $severity;
            }
        }
        fwrite($this->stdout, sprintf(
            "%d alerts%s on %d URLs of %s%s (ZAP %s)%s. Report: %s\n",
            count($result->alerts),
            $summary === [] ? '' : ' (' . implode(', ', $summary) . ')',
            $result->urls(),
            $result->target,
            $result->user !== null ? ' as ' . $result->user : '',
            $result->version ?? '?',
            $result->ignoredAlerts === [] ? '' : sprintf(', %d ignored alerts', count($result->ignoredAlerts)),
            $config->pdf,
        ));

        // The scenario did not run to its end: the pages after the failure may not be scanned
        if ($result->endToEndExitCode !== null && $result->endToEndExitCode !== 0) {
            fwrite($this->stderr, sprintf("security-dast: the End-to-End tests failed (exit code %d), so the pages after the failure may not be scanned: fix the tests, then scan again.\n", $result->endToEndExitCode));

            return self::ERROR;
        }

        return $result->fails($config->failOn) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Ends the process on Ctrl+C and SIGTERM with an exit, so that its shutdown functions stop ZAP and remove the
     * directory of the scan (with the pcntl extension)
     *
     * @return array<int, mixed> The handlers before, by signal
     */
    private static function handleSignals(): array
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return [];
        }
        $before = [];
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            $before[$signal] = pcntl_signal_get_handler($signal);
            // Without restarting the system calls, so that it also ends while it waits for the output of the tests
            pcntl_signal($signal, static function (int $signal): void {
                // A second Ctrl+C would end the shutdown functions, which stop ZAP, before they finish
                pcntl_signal(SIGINT, SIG_IGN);
                pcntl_signal(SIGTERM, SIG_IGN);
                exit(128 + $signal);
            }, false);
        }

        return $before;
    }

    /**
     * Restores the handlers of `handleSignals()`
     *
     * @param array<int, mixed> $handlers The handlers before, by signal
     * @return void
     */
    private static function restoreSignals(array $handlers): void
    {
        foreach ($handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
    }

    /**
     * The settings of the arguments, the environment variables and the settings file, or null for `--help`
     *
     * @param array<string> $argv Arguments
     * @param array<string, mixed> $env Environment variables
     * @return \PieceOfCake2\SecurityDast\Config|null
     * @throws \InvalidArgumentException When an argument is invalid
     */
    public static function config(array $argv, array $env = []): ?Config
    {
        $value = static function (string $name) use ($env): ?string {
            $value = $env[$name] ?? null;

            return is_scalar($value) && trim((string)$value) !== '' ? (string)$value : null;
        };
        $options = [
            'pdf' => $value('SECURITY_DAST_PDF'),
            'fail-on' => $value('SECURITY_DAST_FAIL_ON'),
            'project' => null,
            'url' => $value('SECURITY_DAST_URL'),
            'webroot' => $value('SECURITY_DAST_WEBROOT'),
            'app-env' => $value('SECURITY_DAST_APP_ENV'),
            'image' => $value('SECURITY_DAST_IMAGE'),
            'docker' => $value('SECURITY_DAST_DOCKER'),
            'minutes' => $value('SECURITY_DAST_MINUTES'),
            'ajax-spider' => $value('SECURITY_DAST_AJAX_SPIDER'),
            'end-to-end' => $value('SECURITY_DAST_END_TO_END'),
            'scan' => $value('SECURITY_DAST_SCAN'),
            'scan-minutes' => $value('SECURITY_DAST_SCAN_MINUTES'),
            'login-url' => $value('SECURITY_DAST_LOGIN_URL'),
            'username' => $value('SECURITY_DAST_USERNAME'),
            'password' => $value('SECURITY_DAST_PASSWORD'),
            'exclude' => $value('SECURITY_DAST_EXCLUDE'),
            'ignore' => $value('SECURITY_DAST_IGNORE'),
            'zap-options' => $value('SECURITY_DAST_ZAP_OPTIONS'),
            'language' => $value('SECURITY_REPORT_LANGUAGE'),
            'locale-dir' => $value('SECURITY_REPORT_LOCALE_DIR'),
            'title' => $value('SECURITY_DAST_TITLE'),
            'project-name' => $value('SECURITY_REPORT_PROJECT'),
            'note' => $value('SECURITY_DAST_NOTE'),
        ];
        $arguments = [];
        foreach ($argv as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                return null;
            }
            if (!preg_match('/\A--([a-z-]+)=(.*)\z/s', $arg, $matches) || (!array_key_exists($matches[1], $options) && $matches[1] !== 'settings')) {
                throw new InvalidArgumentException(sprintf('Unknown option: %s', $arg));
            }
            $arguments[$matches[1]] = $matches[2];
        }

        // The options of the command, then the environment variables, then the settings file
        $file = Settings::options((string)($arguments['project'] ?? '.'), 'dast', $arguments['settings'] ?? $value('SECURITY_SETTINGS'), 'security-dast.pdf');
        foreach ($file as $name => $setting) {
            if (!array_key_exists($name, $options) || $name === 'project') {
                throw new InvalidArgumentException(sprintf('Unknown setting of the dast section: %s', $name));
            }
            $options[$name] ??= is_bool($setting) ? ($setting ? '1' : '0') : $setting;
        }
        unset($arguments['settings']);
        $options = array_merge($options, $arguments);

        $failOn = strtolower((string)($options['fail-on'] ?? Severity::HIGH));
        if (!in_array($failOn, Config::THRESHOLDS, true)) {
            throw new InvalidArgumentException(sprintf('--fail-on must be one of %s.', implode(', ', Config::THRESHOLDS)));
        }
        $minutes = filter_var($options['minutes'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $scanMinutes = filter_var($options['scan-minutes'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($minutes === false || $scanMinutes === false) {
            throw new InvalidArgumentException('--minutes and --scan-minutes must be numbers of 1 or more.');
        }
        $scan = strtolower((string)($options['scan'] ?? 'baseline'));
        if (!in_array($scan, Zap::SCANS, true)) {
            throw new InvalidArgumentException(sprintf('--scan must be one of %s.', implode(', ', Zap::SCANS)));
        }
        $url = $options['url'];
        if ($url !== null && !preg_match('#\Ahttps?://#i', $url)) {
            throw new InvalidArgumentException('--url must be an http or https URL.');
        }
        if ($options['login-url'] !== null && ($options['username'] === null || $options['password'] === null)) {
            throw new InvalidArgumentException('--login-url needs --username and --password (or SECURITY_DAST_PASSWORD).');
        }
        $list = static function (?string $value): array {
            // A list of the settings file with gaps in its keys comes as JSON
            $json = $value !== null && str_starts_with(ltrim($value), '{') ? json_decode($value, true) : null;
            $items = is_array($json) ? array_map(static fn($item): string => is_scalar($item) ? (string)$item : '', $json) : explode(',', (string)$value);

            return array_values(array_filter(array_map('trim', $items), static fn(string $item): bool => $item !== ''));
        };

        // The settings file can give the IDs with their reasons, ['10038' => 'reason'], as JSON
        [$ignore, $ignoreReasons] = self::ignore($options['ignore'], $list);

        return new Config(
            pdf: (string)($options['pdf'] ?? 'tmp/security-dast.pdf'),
            failOn: $failOn,
            project: (string)($options['project'] ?? '.'),
            url: $url,
            webroot: $options['webroot'],
            appEnv: (string)($options['app-env'] ?? 'test'),
            image: (string)($options['image'] ?? Zap::IMAGE),
            docker: (string)($options['docker'] ?? 'docker'),
            minutes: $minutes,
            ajaxSpider: filter_var($options['ajax-spider'] ?? false, FILTER_VALIDATE_BOOLEAN),
            endToEnd: $options['end-to-end'],
            scan: $scan,
            scanMinutes: $scanMinutes,
            loginUrl: $options['login-url'],
            username: $options['username'],
            password: $options['password'],
            exclude: $options['exclude'] === null ? null : $list($options['exclude']),
            ignore: $ignore,
            ignoreReasons: $ignoreReasons,
            zapOptions: (string)($options['zap-options'] ?? ''),
            language: (string)($options['language'] ?? 'en'),
            localeDir: $options['locale-dir'],
            title: $options['title'],
            projectName: $options['project-name'],
            note: $options['note'],
        );
    }

    /**
     * The alert IDs to ignore, and their reasons: a list, or the JSON of the settings file with the reasons
     *
     * @param string|null $value Value of the option
     * @param \Closure(?string): array<string> $list Parses a list
     * @return array{0: array<string>, 1: array<int|string, string>}
     * @throws \InvalidArgumentException When the JSON is not an object of IDs and reasons
     */
    private static function ignore(?string $value, Closure $list): array
    {
        if ($value === null || !str_starts_with(ltrim($value), '{')) {
            return [$list($value), []];
        }
        $data = json_decode($value, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('ignore must be a list of alert IDs, or of IDs and their reasons.');
        }
        // A list with gaps in its keys, e.g. of array_filter(): its values are the IDs
        $values = array_map(static fn($value): string => is_scalar($value) ? trim((string)$value) : '', $data);
        if (array_filter($values, static fn(string $value): bool => !preg_match('/\A\d+(?:-\d+)?\z/', $value)) === []) {
            return [array_values(array_filter($values, static fn(string $value): bool => $value !== '')), []];
        }
        $ids = [];
        $reasons = [];
        foreach ($data as $id => $reason) {
            // The keys of PHP are integers for numeric IDs, e.g. 10038
            $id = trim((string)$id);
            if ($id === '') {
                continue;
            }
            $ids[] = $id;
            if (is_scalar($reason) && trim((string)$reason) !== '') {
                $reasons[$id] = trim((string)$reason);
            }
        }

        return [$ids, $reasons];
    }

    /**
     * The help of the command
     *
     * @return string
     */
    public static function usage(): string
    {
        return <<<'TEXT'
Usage: vendor/bin/security-dast [options]

Runs the application with PHP's built-in web server (or takes the URL of a running one), scans it with OWASP
ZAP (Docker), as a user when a login is given, and writes a PDF report of the alerts by risk.

Options:
  --pdf=PATH             PDF report (SECURITY_DAST_PDF, default: tmp/security-dast.pdf)
  --fail-on=RISK         Lowest risk that fails: high, medium, low, info or none
                         (SECURITY_DAST_FAIL_ON, default: high)
  --project=DIR          Directory of the project (default: the current directory)
  --url=URL              URL of a running application to scan instead (SECURITY_DAST_URL)
  --webroot=DIR          Document root of the built-in web server (SECURITY_DAST_WEBROOT,
                         default: WWW_ROOT of config/define.php, or webroot or app/webroot)
  --app-env=ENV          APP_ENV of the built-in web server (SECURITY_DAST_APP_ENV, default: test)
  --image=IMAGE          Docker image of ZAP (SECURITY_DAST_IMAGE, default: ghcr.io/zaproxy/zaproxy:stable)
  --docker=COMMAND       Docker command (SECURITY_DAST_DOCKER, default: docker)
  --end-to-end=COMMAND   End-to-End tests to scan as the scenario, instead of the crawl, e.g.
                         "vendor/bin/phpunit --testsuite EndToEnd" (pieceofcake2/panther) (SECURITY_DAST_END_TO_END)
  --scan=SCAN            baseline (crawl and check the responses) or full (attack the pages too: on a test
                         environment only) (SECURITY_DAST_SCAN, default: baseline)
  --scan-minutes=N       Most minutes of the attacks of the full scan (SECURITY_DAST_SCAN_MINUTES, default: 10)
  --login-url=PATH       Login page, e.g. /users/login: ZAP logs in with a browser and scans as the user
                         (SECURITY_DAST_LOGIN_URL)
  --username=NAME        User to log in as (SECURITY_DAST_USERNAME)
  --password=PASSWORD    Password of the user (SECURITY_DAST_PASSWORD, better than the option)
  --exclude=LIST         Regular expressions of URLs to leave out, comma separated (SECURITY_DAST_EXCLUDE,
                         default: .*logout.*)
  --minutes=N            Minutes to crawl the site (SECURITY_DAST_MINUTES, default: 1)
  --ajax-spider=1        Crawl with a browser too, for pages built by JavaScript (SECURITY_DAST_AJAX_SPIDER)
  --ignore=LIST          Alert IDs that do not fail the scan, comma separated, e.g. 10038; the report lists them
                         (SECURITY_DAST_IGNORE)
  --zap-options=OPTIONS  More options of ZAP (SECURITY_DAST_ZAP_OPTIONS)
  --language=LANG        en or ja (SECURITY_REPORT_LANGUAGE, default: en)
  --locale-dir=DIR       Directory of <language>.json files (SECURITY_REPORT_LOCALE_DIR)
  --title=TITLE          Title of the report (SECURITY_DAST_TITLE)
  --project-name=NAME    Name of the project (SECURITY_REPORT_PROJECT, default: the name in composer.json)
  --note=TEXT            Note on the cover (SECURITY_DAST_NOTE)
  --settings=FILE        Settings file (SECURITY_SETTINGS, default: config/security.php or app/Config/security.php)
  --help                 Show this help

Exit code: 0 passed, 1 an alert reaches --fail-on, 2 error or failed End-to-End tests.

TEXT;
    }
}
