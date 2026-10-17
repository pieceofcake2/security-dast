<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast\Test;

use PHPUnit\Framework\TestCase;
use PieceOfCake2\SecurityDast\Login;
use PieceOfCake2\SecurityDast\WebServer;
use PieceOfCake2\SecurityDast\Zap;
use PieceOfCake2\SecurityDast\ZapDaemon;
use RuntimeException;

class ZapTest extends TestCase
{
    public const FIXTURES = __DIR__ . DS . '..' . DS . 'fixtures';

    /**
     * The URL of the site of the fixture reports, and the same from the container
     */
    public const URL = 'http://127.0.0.1:56557';
    public const TARGET = 'http://host.docker.internal:56557';

    /**
     * A ZAP whose Docker writes a fixture report into the mounted directory
     *
     * @param string $language Language of the fixture report
     * @param array<array<string>> $commands Receives the commands
     * @param string $os Operating system family
     * @param \PieceOfCake2\SecurityDast\Login|null $login User to log in as
     * @param string $auth Fixture of the authentication report: `zap-auth.json` or `zap-auth-failed.json`
     * @param array<string> $plans Receives the plans
     * @return \PieceOfCake2\SecurityDast\Zap
     */
    public static function zap(string $language = 'en', array &$commands = [], string $os = 'Darwin', ?Login $login = null, string $auth = 'zap-auth.json', array &$plans = []): Zap
    {
        return new Zap(run: static function (array $command, string $dir) use ($language, &$commands, $auth, &$plans): array {
            $commands[] = $command;
            $plans[] = (string)file_get_contents($dir . DS . 'plan.yaml');
            copy(self::FIXTURES . DS . 'zap-' . $language . '.json', $dir . DS . 'zap.json');
            copy(self::FIXTURES . DS . $auth, $dir . DS . 'auth.json');

            return [0, 'Automation plan succeeded!', ''];
        }, os: $os, login: $login);
    }

    public function testCommand(): void
    {
        $zap = new Zap('ghcr.io/zaproxy/zaproxy:2.17.0', 'podman', options: '-config spider.maxDepth=3', os: 'Darwin');

        $command = $zap->command('/tmp/zap', 'ja');

        $this->assertSame([
            'podman', 'run', '--rm', '-v', '/tmp/zap:/zap/wrk:rw', 'ghcr.io/zaproxy/zaproxy:2.17.0', 'zap.sh', '-cmd', '-autorun', '/zap/wrk/plan.yaml',
            '-config', 'anticsrf.partialMatchingEnabled=false',
            '-config', 'anticsrf.tokens.token(100).name=data[_Token][key]', '-config', 'anticsrf.tokens.token(100).enabled=true',
            '-config', 'scanner.excludeAntiCsrfTokens=true',
            '-config', 'scanner.excludedParameters(0).name=_method', '-config', 'scanner.excludedParameters(0).type=-1',
            '-config', 'scanner.excludedParameters(1).name=data[_Token][fields]', '-config', 'scanner.excludedParameters(1).type=-1',
            '-config', 'scanner.excludedParameters(2).name=data[_Token][unlocked]', '-config', 'scanner.excludedParameters(2).type=-1',
            '-config', 'scanner.excludedParameters(3).name=data[_Token][debug]', '-config', 'scanner.excludedParameters(3).type=-1',
            '-config', 'view.locale=ja_JP',
            '-config', 'spider.maxDepth=3',
        ], $command);
    }

    public function testCommandOnLinux(): void
    {
        $command = (new Zap(os: 'Linux'))->command('/tmp/zap');

        $this->assertSame(['docker', 'run', '--rm', '--network', 'host', '-v', '/tmp/zap:/zap/wrk:rw'], array_slice($command, 0, 7));
        $this->assertNotContains('view.locale=ja_JP', $command);
    }

    public function testPlanOfBaseline(): void
    {
        $plan = (new Zap(minutes: 2))->plan(self::TARGET . '/');

        $context = $plan['env']['contexts'][0];
        $this->assertSame([self::TARGET], $context['urls']);
        $this->assertSame(['\\Q' . self::TARGET . '\\E.*'], $context['includePaths']);
        $this->assertSame(['.*logout.*'], $context['excludePaths']);
        $this->assertArrayNotHasKey('authentication', $context);
        $this->assertSame(['spider', 'passiveScan-wait', 'report'], array_column($plan['jobs'], 'type'));
        $this->assertSame(['context' => 'app', 'maxDuration' => 2], $plan['jobs'][0]['parameters']);
        $this->assertSame('traditional-json', $plan['jobs'][2]['parameters']['template']);
    }

    public function testPlanOfFullScanWithLogin(): void
    {
        $zap = new Zap(ajaxSpider: true, scan: 'full', scanMinutes: 20, login: new Login('/users/login', 'test@example.com', 'secret'), exclude: ['.*/users/logout.*', '.*/admin/.*']);
        $plan = $zap->plan(self::TARGET);

        $context = $plan['env']['contexts'][0];
        $this->assertSame(['.*/users/logout.*', '.*/admin/.*'], $context['excludePaths']);
        $this->assertSame('browser', $context['authentication']['method']);
        $this->assertSame(self::TARGET . '/users/login', $context['authentication']['parameters']['loginPageUrl']);
        $this->assertSame([['name' => 'user', 'credentials' => ['username' => 'test@example.com', 'password' => 'secret']]], $context['users']);
        $this->assertSame(['spider', 'spiderAjax', 'passiveScan-wait', 'activeScan', 'passiveScan-wait', 'report', 'report'], array_column($plan['jobs'], 'type'));
        $this->assertSame(['context' => 'app', 'user' => 'user', 'maxScanDurationInMins' => 20], $plan['jobs'][3]['parameters']);
        $this->assertSame('auth-report-json', $plan['jobs'][6]['parameters']['template']);

        // Without the crawl, for a scenario
        $this->assertSame(['passiveScan-wait', 'activeScan', 'passiveScan-wait', 'report', 'report'], array_column($zap->plan(self::TARGET, false)['jobs'], 'type'));
    }

    public function testScanScenario(): void
    {
        $calls = [];
        $daemon = static function (string $dir, array $options) use (&$calls): ZapDaemon {
            $calls[] = 'options ' . implode(' ', $options);

            return new ZapDaemon($dir, $options, port: 8090, run: static function (array $command, string $cwd) use (&$calls): array {
                $calls[] = implode(' ', array_slice($command, 0, 3));
                if ($command[1] === 'run') {
                    return [0, "container-id\n", ''];
                }

                return [0, '', ''];
            }, get: static function (string $url) use (&$calls, $dir): string {
                if (str_contains($url, 'core/view/version')) {
                    return '{"version":"2.17.0"}';
                }
                if (str_contains($url, 'automation/action/runPlan')) {
                    $calls[] = 'plan ' . implode(',', array_column(json_decode((string)file_get_contents($dir . DS . 'plan.yaml'), true)['jobs'], 'type'));
                    copy(self::FIXTURES . DS . 'zap-en.json', $dir . DS . 'zap.json');

                    return '{"planId":"0"}';
                }

                return '{"finished":"2026-10-17T10:00:00Z","error":[]}';
            });
        };
        $zap = new Zap(os: 'Darwin', scan: 'full');

        $result = $zap->scanScenario(self::URL, static function (string $proxy, string $site) use (&$calls): void {
            $calls[] = 'scenario ' . $proxy . ' ' . $site;
        }, 'ja', $daemon);

        $this->assertSame([
            'options ' . implode(' ', $zap->zapOptions('ja')),
            'docker run -d',
            'scenario http://127.0.0.1:8090 ' . self::TARGET,
            'plan passiveScan-wait,activeScan,passiveScan-wait,report',
            'docker rm -f',
        ], $calls);
        $this->assertCount(17, $result->alerts);
        $this->assertSame(self::URL . '/login', $result->alerts[0]->instances[0]['uri']);
    }

    public function testScanScenarioWithoutReport(): void
    {
        $daemon = static fn(string $dir, array $options): ZapDaemon => new ZapDaemon($dir, $options, port: 8090, run: static fn(array $command, string $cwd): array => [0, "id\n", ''], get: static function (string $url): string {
            if (str_contains($url, 'version')) {
                return '{"version":"2.17.0"}';
            }

            return str_contains($url, 'runPlan') ? '{"planId":"0"}' : '{"finished":"2026-10-17T10:00:00Z","error":[]}';
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ZAP wrote no report.');
        (new Zap(os: 'Darwin'))->scanScenario(self::URL, static function (string $proxy, string $site): void {
        }, 'en', $daemon);
    }

    public function testLoggedIn(): void
    {
        $this->assertTrue(Zap::loggedIn((array)json_decode((string)file_get_contents(self::FIXTURES . DS . 'zap-auth.json'), true)));
        $this->assertFalse(Zap::loggedIn((array)json_decode((string)file_get_contents(self::FIXTURES . DS . 'zap-auth-failed.json'), true)));
        $this->assertFalse(Zap::loggedIn([]));
    }

    public function testLogin(): void
    {
        $plans = [];
        $commands = [];
        $result = self::zap('en', $commands, 'Darwin', new Login('/users/login', 'test@example.com', 'secret'), 'zap-auth.json', $plans)->scan(self::URL);

        $this->assertSame('test@example.com', $result->user);
        // The plan is JSON, which is YAML too
        $this->assertSame(self::TARGET . '/users/login', json_decode($plans[0], true)['env']['contexts'][0]['authentication']['parameters']['loginPageUrl']);
    }

    public function testLoginFailed(): void
    {
        $commands = [];
        $zap = self::zap('en', $commands, 'Darwin', new Login('http://127.0.0.1:56557/users/login', 'test@example.com', 'wrong'), 'zap-auth-failed.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ZAP could not log in as test@example.com on http://127.0.0.1:56557/users/login');
        $zap->scan(self::URL);
    }

    public function testTargetFromContainer(): void
    {
        $zap = new Zap(os: 'Darwin');
        $this->assertSame(self::TARGET, $zap->targetFromContainer(self::URL));
        $this->assertSame('http://host.docker.internal/', $zap->targetFromContainer('http://localhost/'));
        $this->assertSame('https://example.com', $zap->targetFromContainer('https://example.com'));
        $this->assertSame('http://127.0.0.1.example.com', $zap->targetFromContainer('http://127.0.0.1.example.com'));

        $this->assertSame(self::URL, (new Zap(os: 'Linux'))->targetFromContainer(self::URL));
    }

    public function testScan(): void
    {
        $commands = [];
        $result = self::zap('en', $commands)->scan(self::URL, 'en');

        $this->assertContains('/zap/wrk/plan.yaml', $commands[0]);
        $this->assertNull($result->user);
        $this->assertCount(17, $result->alerts);
        $this->assertSame(self::URL, $result->target);
        $this->assertSame('2.17.0', $result->version);
        $this->assertSame(['high' => 0, 'medium' => 4, 'low' => 10, 'info' => 3], $result->counts());
        $this->assertSame(7, $result->urls());
        $this->assertTrue($result->fails('medium'));
        $this->assertFalse($result->fails('high'));

        // The alerts by risk then name, with the URLs as given
        $csrf = $result->alerts[0];
        $this->assertSame('10202', $csrf->id);
        $this->assertSame('Absence of Anti-CSRF Tokens', $csrf->name);
        $this->assertSame('medium', $csrf->severity);
        $this->assertSame('Low', $csrf->confidence);
        $this->assertSame(352, $csrf->cwe);
        $this->assertSame(9, $csrf->wasc);
        $this->assertSame('GET', $csrf->instances[0]['method']);
        $this->assertSame(self::URL . '/login', $csrf->instances[0]['uri']);
        $this->assertSame('<form method="post" action="/login">', $csrf->instances[0]['evidence']);
        $this->assertStringNotContainsString('<p>', $csrf->description);
        $this->assertContains('https://cwe.mitre.org/data/definitions/352.html', $csrf->references);
    }

    public function testIgnore(): void
    {
        $result = self::zap()->scan(self::URL);
        $result->ignore(['10038', '90004-2', '10049']);

        $this->assertSame(['10038-1', '90004-2', '10049-1', '10049-3'], $result->ignored);
        $this->assertCount(13, $result->alerts);
        $this->assertContains('90004-1', array_map(static fn($alert): string => $alert->id, $result->alerts));
    }

    public function testScanInJapanese(): void
    {
        $result = self::zap('ja')->scan(self::URL, 'ja');

        $this->assertSame('Anti-CSRFトークンが使用されていない', $result->alerts[0]->name);
    }

    public function testFailure(): void
    {
        $zap = new Zap(run: static fn(array $command, string $dir): array => [125, '', 'Cannot connect to the Docker daemon'], os: 'Darwin');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ZAP failed (exit code 125): Cannot connect to the Docker daemon');
        $zap->scan(self::URL);
    }

    public function testText(): void
    {
        $this->assertSame("First &lt; line\nSecond <b>\n\nThird", Zap::text('<p>First &amp;lt; line<br/>Second &lt;b&gt;</p><p></p><p>Third</p>'));
    }

    public function testLinks(): void
    {
        $this->assertSame(['https://a.example/x', 'http://b.example/?q=1&r=2'], Zap::links("<p>https://a.example/x</p><p>http://b.example/?q=1&amp;r=2</p>\nhttps://a.example/x"));
    }

    /**
     * Runs ZAP in Docker against the fixture application, with `SECURITY_DAST_TEST_DOCKER=1` (one job of CI) and
     * Docker
     *
     * @return void
     */
    public function testScanWithDocker(): void
    {
        if (empty($_SERVER['SECURITY_DAST_TEST_DOCKER'])) {
            $this->markTestSkipped('Set SECURITY_DAST_TEST_DOCKER=1 to run ZAP in Docker.');
        }
        exec('docker info > /dev/null 2>&1', $output, $code);
        if ($code !== 0) {
            $this->markTestSkipped('Docker is not available.');
        }

        $server = new WebServer(self::FIXTURES . DS . 'app' . DS . 'webroot', self::FIXTURES . DS . 'app');
        try {
            $result = (new Zap())->scan($server->start());
        } finally {
            $server->stop();
        }

        $ids = array_map(static fn($alert): string => $alert->id, $result->alerts);
        $this->assertContains('10202', $ids);
        $this->assertContains('10038-1', $ids);
        $this->assertContains('10037', $ids);

        // As a user: the pages behind the login are crawled
        $server = new WebServer(self::FIXTURES . DS . 'login' . DS . 'webroot', self::FIXTURES . DS . 'login');
        try {
            $result = (new Zap(login: new Login('/users/login', 'test@example.com', 'secret')))->scan($server->start());
        } finally {
            $server->stop();
        }
        $this->assertSame('test@example.com', $result->user);
        $uris = array_merge(...array_map(static fn($alert): array => array_column($alert->instances, 'uri'), $result->alerts));
        $this->assertNotEmpty(preg_grep('#/account#', $uris));

        // A scenario through the proxy of ZAP: the page that it opens is checked
        $server = new WebServer(self::FIXTURES . DS . 'app' . DS . 'webroot', self::FIXTURES . DS . 'app');
        try {
            $result = (new Zap())->scanScenario($server->start(), static function (string $proxy, string $site): void {
                $context = stream_context_create(['http' => ['proxy' => str_replace('http://', 'tcp://', $proxy), 'request_fulluri' => true, 'ignore_errors' => true]]);
                file_get_contents($site . '/login', false, $context);
            });
        } finally {
            $server->stop();
        }
        $uris = array_merge(...array_map(static fn($alert): array => array_column($alert->instances, 'uri'), $result->alerts));
        $this->assertNotEmpty(preg_grep('#/login#', $uris));
    }
}
