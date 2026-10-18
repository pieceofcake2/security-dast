<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PieceOfCake2\Report\Environment;
use PieceOfCake2\Report\PdfPrinter;
use PieceOfCake2\Report\ReportWriter;
use PieceOfCake2\SecurityDast\Command;
use PieceOfCake2\SecurityDast\Login;
use PieceOfCake2\SecurityDast\Zap;
use PieceOfCake2\SecurityDast\ZapDaemon;

class CommandTest extends TestCase
{
    public function testConfig(): void
    {
        $config = Command::config(['--pdf=out/dast.pdf', '--fail-on=MEDIUM', '--url=https://staging.example.com', '--minutes=2', '--ajax-spider=1', '--language=ja', '--ignore=10038, 10037'], ['SECURITY_DAST_TITLE' => 'Scan', 'SECURITY_DAST_PDF' => 'env.pdf', 'SECURITY_DAST_APP_ENV' => 'acceptance']);

        $this->assertNotNull($config);
        $this->assertSame('out/dast.pdf', $config->pdf);
        $this->assertSame('medium', $config->failOn);
        $this->assertSame('https://staging.example.com', $config->url);
        $this->assertSame(2, $config->minutes);
        $this->assertTrue($config->ajaxSpider);
        $this->assertSame('acceptance', $config->appEnv);
        $this->assertSame('ja', $config->language);
        $this->assertSame('Scan', $config->title);
        $this->assertSame(['10038', '10037'], $config->ignore);
    }

    public function testDefaults(): void
    {
        $config = Command::config(['--project=' . ZapTest::FIXTURES . DS . 'app']);

        $this->assertNotNull($config);
        $this->assertSame('tmp/security-dast.pdf', $config->pdf);
        $this->assertSame('high', $config->failOn);
        $this->assertNull($config->url);
        $this->assertSame('test', $config->appEnv);
        $this->assertSame(Zap::IMAGE, $config->image);
        $this->assertSame(1, $config->minutes);
        $this->assertFalse($config->ajaxSpider);
    }

    public function testLogin(): void
    {
        $config = Command::config(['--scan=FULL', '--scan-minutes=20', '--login-url=/users/login', '--username=test@example.com', '--exclude=.*/users/logout.*, .*/admin/.*'], ['SECURITY_DAST_PASSWORD' => 'secret']);

        $this->assertNotNull($config);
        $this->assertSame('full', $config->scan);
        $this->assertSame(20, $config->scanMinutes);
        $this->assertSame(['.*/users/logout.*', '.*/admin/.*'], $config->exclude);
        $login = $config->login();
        $this->assertNotNull($login);
        $this->assertSame('secret', $login->password);
        $this->assertSame('http://127.0.0.1:8080/users/login', $login->urlOn('http://127.0.0.1:8080/'));
        $this->assertNull(Command::config([])?->login());
        $this->assertSame('https://example.com/login', (new Login('https://example.com/login', 'a', 'b'))->urlOn('http://127.0.0.1:8080'));
    }

    public function testSettingsFile(): void
    {
        $project = ZapTest::FIXTURES . DS . 'project';
        $config = Command::config(['--project=' . $project, '--minutes=5'], ['SECURITY_DAST_FAIL_ON' => 'low']);

        $this->assertNotNull($config);
        // The option and the environment variable win over the file
        $this->assertSame(5, $config->minutes);
        $this->assertSame('low', $config->failOn);
        $this->assertTrue($config->ajaxSpider);
        $this->assertSame('acceptance', $config->appEnv);
        $this->assertSame('ja', $config->language);
        $this->assertSame('Acme', $config->projectName);
        $this->assertSame('tmp/security/security-dast.pdf', $config->pdf);
    }

    public function testIgnoreWithReasons(): void
    {
        $file = TMP . 'security-ignore.php';
        file_put_contents($file, "<?php\nreturn ['dast' => ['ignore' => ['10038' => 'The load balancer adds CSP', '10020-1' => 'Framed on purpose']]];\n");

        $config = Command::config(['--settings=' . $file]);

        $this->assertNotNull($config);
        $this->assertSame(['10038', '10020-1'], $config->ignore);
        $this->assertSame(['10038' => 'The load balancer adds CSP', '10020-1' => 'Framed on purpose'], $config->ignoreReasons);

        // A list, and the option, have no reasons
        $config = Command::config(['--settings=' . $file, '--ignore=10049']);
        $this->assertNotNull($config);
        $this->assertSame(['10049'], $config->ignore);
        $this->assertSame([], $config->ignoreReasons);
    }

    public function testListsOfTheSettingsFile(): void
    {
        $file = TMP . 'security-lists.php';

        // An empty list is no exclusion, not the text []
        file_put_contents($file, "<?php\nreturn ['dast' => ['exclude' => [], 'ignore' => []]];\n");
        $config = Command::config(['--settings=' . $file]);
        $this->assertNotNull($config);
        $this->assertSame([], $config->exclude);
        $this->assertSame([], $config->ignore);

        // A list with gaps in its keys, e.g. of array_filter(), is a list
        file_put_contents($file, "<?php\nreturn ['dast' => ['exclude' => array_filter([null, '.*admin.*']), 'ignore' => array_filter([null, '10038'])]];\n");
        $config = Command::config(['--settings=' . $file]);
        $this->assertNotNull($config);
        $this->assertSame(['.*admin.*'], $config->exclude);
        $this->assertSame(['10038'], $config->ignore);
        $this->assertSame([], $config->ignoreReasons);
    }

    public function testIgnoreOfInvalidJson(): void
    {
        // An empty ID is left out, and a value that is not an object of IDs is an error
        $config = Command::config([], ['SECURITY_DAST_IGNORE' => '{"": "x", "10038": ""}']);
        $this->assertNotNull($config);
        $this->assertSame(['10038'], $config->ignore);
        $this->assertSame([], $config->ignoreReasons);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ignore must be a list of alert IDs, or of IDs and their reasons.');
        Command::config([], ['SECURITY_DAST_IGNORE' => '{10038']);
    }

    public function testInvalidOptions(): void
    {
        $this->assertNull(Command::config(['--help']));

        foreach (['--fail-on=critical', '--minutes=0', '--scan-minutes=x', '--scan=quick', '--url=127.0.0.1:8080', '--login-url=/login', '--verbose'] as $option) {
            try {
                Command::config([$option]);
                $this->fail('No exception for ' . $option);
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    /**
     * Runs the command with the fixture report of ZAP
     *
     * @param array<string> $argv Arguments
     * @param string $output Receives the output
     * @return int
     */
    private static function runCommand(array $argv, string &$output = ''): int
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $command = new Command($stdout, $stderr, ZapTest::zap(), new ReportWriter(PdfPrinter::fromEnv($_SERVER)), new Environment());
        $code = $command->run(array_merge(['--project=' . ZapTest::FIXTURES . DS . 'app'], $argv));
        rewind($stdout);
        rewind($stderr);
        $output = stream_get_contents($stdout) . stream_get_contents($stderr);

        return $code;
    }

    public function testRunWritesThePdf(): void
    {
        if (PdfPrinter::findChrome() === null && empty($_SERVER['REPORT_CHROME_BINARY'])) {
            $this->markTestSkipped('Chrome is not installed.');
        }
        $pdf = TMP . 'dast' . DS . 'report.pdf';
        $output = '';

        // The application of the fixture runs with the built-in web server for the scan
        $code = self::runCommand(['--pdf=' . $pdf, '--fail-on=medium'], $output);

        $this->assertSame(Command::FAILURE, $code, $output);
        $this->assertStringContainsString('17 alerts (4 medium, 10 low, 3 info) on 7 URLs of http://127.0.0.1:', $output);
        $this->assertStringContainsString('(ZAP 2.17.0). Report: ' . $pdf, $output);
        $this->assertStringStartsWith('%PDF-', (string)file_get_contents($pdf));

        $this->assertSame(Command::SUCCESS, self::runCommand(['--pdf=' . $pdf, '--url=http://127.0.0.1:8080']));
    }

    public function testRunEndToEndTests(): void
    {
        if (PdfPrinter::findChrome() === null && empty($_SERVER['REPORT_CHROME_BINARY'])) {
            $this->markTestSkipped('Chrome is not installed.');
        }
        $env = TMP . 'end-to-end.txt';
        $daemon = static fn(string $dir, array $options): ZapDaemon => new ZapDaemon($dir, $options, port: 8090, run: static fn(array $command, string $cwd): array => [0, "id\n", ''], get: static function (string $url) use ($dir): string {
            if (str_contains($url, 'runPlan')) {
                copy(ZapTest::FIXTURES . DS . 'zap-en.json', $dir . DS . 'zap.json');

                return '{"planId":"0"}';
            }

            return str_contains($url, 'version') ? '{"version":"2.17.0"}' : '{"finished":"2026-10-17T10:00:00Z","error":[]}';
        });
        // The tests write their environment and fail
        $tests = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('file_put_contents(' . var_export($env, true) . ', getenv("PANTHER_EXTERNAL_BASE_URI") . " " . getenv("PANTHER_CHROME_ARGUMENTS") . " " . getenv("APP_ENV")); exit(1);');
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $command = new Command($stdout, $stderr, new Zap(os: 'Darwin'), new ReportWriter(PdfPrinter::fromEnv($_SERVER)), new Environment(), $daemon);

        $code = $command->run(['--project=' . ZapTest::FIXTURES . DS . 'app', '--pdf=' . TMP . 'dast' . DS . 'end-to-end.pdf', '--end-to-end=' . $tests]);

        // The report is written, but the failed tests do not pass: the pages after the failure may not be scanned
        rewind($stderr);
        $this->assertSame(Command::ERROR, $code);
        $this->assertStringContainsString('the End-to-End tests failed (exit code 1)', (string)stream_get_contents($stderr));
        $this->assertFileExists(TMP . 'dast' . DS . 'end-to-end.pdf');
        $this->assertMatchesRegularExpression('#\Ahttp://host\.docker\.internal:\d+ --proxy-server=http://127\.0\.0\.1:8090 --proxy-bypass-list=<-loopback> --ignore-certificate-errors test\z#', (string)file_get_contents($env));
    }

    public function testRunFailsWithoutIndex(): void
    {
        $output = '';
        $code = self::runCommand(['--webroot=' . ZapTest::FIXTURES, '--pdf=' . TMP . 'x.pdf'], $output);

        $this->assertSame(Command::ERROR, $code);
        $this->assertStringContainsString('security-dast: ', $output);
        $this->assertStringContainsString('has no index.php', $output);
    }

    public function testUsage(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $this->assertSame(Command::SUCCESS, (new Command($stdout))->run(['--help']));
        rewind($stdout);
        $this->assertStringContainsString('Usage: vendor/bin/security-dast', (string)stream_get_contents($stdout));
    }
}
