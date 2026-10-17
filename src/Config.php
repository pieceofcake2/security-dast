<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

/**
 * The settings of a run: the options of `security-dast`, the environment variables, or the settings file.
 */
final class Config
{
    public const THRESHOLDS = [Severity::HIGH, Severity::MEDIUM, Severity::LOW, Severity::INFO, 'none'];

    /**
     * The document roots of the layouts of CakePHP 2, when `config/define.php` does not give one
     */
    public const WEBROOTS = ['webroot', 'app/webroot'];

    /**
     * Constructor
     *
     * @param string $pdf Path of the PDF report
     * @param string $failOn Lowest risk that fails, or `none`
     * @param string $project Directory of the project
     * @param string|null $url URL of a running application to scan, default: the project, run with PHP's
     *  built-in web server
     * @param string|null $webroot Document root of the built-in web server, default: `WWW_ROOT` of the
     *  application
     * @param string $appEnv `APP_ENV` of the built-in web server
     * @param string $image Docker image of ZAP
     * @param string $docker Docker command
     * @param int $minutes Minutes to crawl the site
     * @param bool $ajaxSpider Whether to crawl with a browser too
     * @param string|null $endToEnd Command of the End-to-End tests to scan as the scenario, instead of the crawl
     * @param string $scan `baseline` (crawl and passive scan) or `full` (with the active scan)
     * @param int $scanMinutes Most minutes of the active scan
     * @param string|null $loginUrl Login page, a path of the application or a URL
     * @param string|null $username User to log in as
     * @param string|null $password Password of the user
     * @param array<string>|null $exclude Regular expressions of the URLs to leave out, default: the logout
     * @param array<string> $ignore Alert IDs to leave out of the report, e.g. `10038` (with `10038-1`, ...)
     * @param string $zapOptions More options of ZAP
     * @param string $language Language of the report
     * @param string|null $localeDir Directory of more messages
     * @param string|null $title Title
     * @param string|null $projectName Name of the project
     * @param string|null $note Note on the cover
     */
    public function __construct(
        public string $pdf = 'tmp/security-dast.pdf',
        public string $failOn = Severity::HIGH,
        public string $project = '.',
        public ?string $url = null,
        public ?string $webroot = null,
        public string $appEnv = 'test',
        public string $image = Zap::IMAGE,
        public string $docker = 'docker',
        public int $minutes = 1,
        public bool $ajaxSpider = false,
        public ?string $endToEnd = null,
        public string $scan = 'baseline',
        public int $scanMinutes = 10,
        public ?string $loginUrl = null,
        public ?string $username = null,
        public ?string $password = null,
        public ?array $exclude = null,
        public array $ignore = [],
        public string $zapOptions = '',
        public string $language = 'en',
        public ?string $localeDir = null,
        public ?string $title = null,
        public ?string $projectName = null,
        public ?string $note = null,
    ) {
    }

    /**
     * The document root: the one given, `WWW_ROOT` of `config/define.php`, or the first of `WEBROOTS` that exists
     *
     * @return string
     */
    public function webroot(): string
    {
        if ($this->webroot !== null) {
            return self::absolute($this->project, $this->webroot);
        }
        $define = $this->project . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'define.php';
        if (is_file($define)) {
            $script = 'if (!defined("DS")) { define("DS", DIRECTORY_SEPARATOR); } require $argv[1]; echo defined("WWW_ROOT") ? WWW_ROOT : "";';
            [$code, $output] = Process::run([PHP_BINARY, '-r', $script, (string)realpath($define)], $this->project);
            $output = trim($output);
            if ($code === 0 && $output !== '' && is_dir($output)) {
                return rtrim($output, '/\\');
            }
        }
        foreach (self::WEBROOTS as $webroot) {
            if (is_dir($this->project . DIRECTORY_SEPARATOR . $webroot)) {
                return $this->project . DIRECTORY_SEPARATOR . $webroot;
            }
        }

        return $this->project . DIRECTORY_SEPARATOR . self::WEBROOTS[0];
    }

    /**
     * The engine of the settings
     *
     * @return \PieceOfCake2\SecurityDast\Zap
     */
    public function zap(): Zap
    {
        return new Zap(
            $this->image,
            $this->docker,
            $this->minutes,
            $this->ajaxSpider,
            $this->zapOptions,
            scan: $this->scan,
            scanMinutes: $this->scanMinutes,
            login: $this->login(),
            exclude: $this->exclude ?? Zap::EXCLUDE,
        );
    }

    /**
     * The user to log in as, when the login page is given
     *
     * @return \PieceOfCake2\SecurityDast\Login|null
     */
    public function login(): ?Login
    {
        if ($this->loginUrl === null) {
            return null;
        }

        return new Login($this->loginUrl, (string)$this->username, (string)$this->password);
    }

    /**
     * A path relative to the project, or absolute
     *
     * @param string $project Directory of the project
     * @param string $path Path
     * @return string
     */
    private static function absolute(string $project, string $path): string
    {
        return preg_match('#\A(?:/|[A-Za-z]:[/\\\\])#', $path) ? $path : $project . DIRECTORY_SEPARATOR . $path;
    }
}
