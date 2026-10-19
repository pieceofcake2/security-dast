<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast\Test;

use Closure;
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
        return new Zap(run: static function (array $command, string $dir, array $env = []) use ($language, &$commands, $auth, &$plans): array {
            $commands[] = $command;
            $plans[] = (string)file_get_contents($dir . DS . 'plan.yaml');
            // The report of the site of the plan, as ZAP writes it
            $target = (string)(json_decode(end($plans), true)['env']['contexts'][0]['urls'][0] ?? self::TARGET);
            file_put_contents($dir . DS . 'zap.json', str_replace(self::TARGET, $target, (string)file_get_contents(self::FIXTURES . DS . 'zap-' . $language . '.json')));
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

    public function testCommandWithAName(): void
    {
        $commands = [];
        $zap = new Zap(run: static function (array $command, string $dir, array $env = []) use (&$commands): array {
            $commands[] = $command;

            return [0, '', ''];
        }, os: 'Darwin');

        // The name of the container, which the end of the process removes when it still runs
        $this->assertSame(['docker', 'run', '--rm', '--name', 'security-dast-1'], array_slice($zap->command('/tmp/zap', 'en', 'security-dast-1'), 0, 5));
        $zap->removeContainer();
        $this->assertSame([], $commands);
    }

    public function testCommandOnLinux(): void
    {
        $command = (new Zap(os: 'Linux'))->command('/tmp/zap');

        $this->assertSame(['docker', 'run', '--rm', '--network', 'host'], array_slice($command, 0, 5));
        // As the user of this process, with its home in the private directory
        $this->assertSame('--user', $command[5]);
        $this->assertMatchesRegularExpression('/\A\d+:\d+\z/', $command[6]);
        $this->assertSame(['-e', 'HOME=/zap/wrk/home', '-e', 'JAVA_TOOL_OPTIONS=-Duser.home=/zap/wrk/home', '-v', '/tmp/zap:/zap/wrk:rw'], array_slice($command, 7, 6));
        $this->assertSame(['-dir', '/zap/wrk/home/.ZAP'], array_slice($command, 18, 2));
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
        $this->assertSame(['spider', 'passiveScan-wait', 'report', 'report'], array_column($plan['jobs'], 'type'));
        $this->assertSame(['context' => 'app', 'maxDuration' => 2], $plan['jobs'][0]['parameters']);
        $this->assertSame('traditional-json', $plan['jobs'][2]['parameters']['template']);
        // The passing rules of the modern report: the rules that ran and raised no alert
        $this->assertSame(['template' => 'modern', 'reportDir' => '/zap/wrk', 'reportFile' => 'rules'], $plan['jobs'][3]['parameters']);
        $this->assertSame(['passingrules'], $plan['jobs'][3]['sections']);
    }

    public function testPlanOfFullScanWithLogin(): void
    {
        $zap = new Zap(ajaxSpider: true, scan: 'full', scanMinutes: 20, login: new Login('/users/login', 'test@example.com', 'secret'), exclude: ['.*/users/logout.*', '.*/admin/.*']);
        $plan = $zap->plan(self::TARGET);

        $context = $plan['env']['contexts'][0];
        $this->assertSame(['.*/users/logout.*', '.*/admin/.*'], $context['excludePaths']);
        $this->assertSame('browser', $context['authentication']['method']);
        $this->assertSame(self::TARGET . '/users/login', $context['authentication']['parameters']['loginPageUrl']);
        // The password from the environment of ZAP, not in the plan
        $this->assertSame([['name' => 'user', 'credentials' => ['username' => 'test@example.com', 'password' => '${SECURITY_DAST_ZAP_PASSWORD}']]], $context['users']);
        $this->assertSame(['spider', 'spiderAjax', 'passiveScan-wait', 'activeScan', 'passiveScan-wait', 'report', 'report', 'report', 'report'], array_column($plan['jobs'], 'type'));
        $this->assertSame(['context' => 'app', 'user' => 'user', 'maxScanDurationInMins' => 20], $plan['jobs'][3]['parameters']);
        // Every rule as in the Default Policy, and Path Traversal without its check on the file name of the URL
        $policy = $plan['jobs'][3]['policyDefinition'];
        $this->assertSame('Medium', $policy['defaultThreshold']);
        $this->assertSame([['id' => 6, 'name' => 'Path Traversal', 'threshold' => 'High']], $policy['rules']);
        $this->assertSame('modern', $plan['jobs'][6]['parameters']['template']);
        // The statistics of the rules that the active scan skipped
        $this->assertSame(['template' => 'traditional-json-plus', 'reportDir' => '/zap/wrk', 'reportFile' => 'stats'], $plan['jobs'][7]['parameters']);
        $this->assertSame(['statistics'], $plan['jobs'][7]['sections']);
        $this->assertSame('auth-report-json', $plan['jobs'][8]['parameters']['template']);

        // Without the crawl, for a scenario
        $this->assertSame(['passiveScan-wait', 'activeScan', 'passiveScan-wait', 'report', 'report', 'report', 'report'], array_column($zap->plan(self::TARGET, false)['jobs'], 'type'));
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
            'plan passiveScan-wait,activeScan,passiveScan-wait,report,report,report',
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

    /**
     * The rules of the passing rules report, with those that the active scan skipped, and its time limit
     *
     * @return void
     */
    public function testScanWithTheRules(): void
    {
        $run = static function (string $time): Closure {
            return static function (array $command, string $dir, array $env = []) use ($time): array {
                copy(self::FIXTURES . DS . 'zap-en.json', $dir . DS . 'zap.json');
                copy(self::FIXTURES . DS . 'zap-rules.html', $dir . DS . 'rules.html');
                file_put_contents($dir . DS . 'stats.json', '{"site": [], "statistics": {"stats.ascan.90019.skipped": 1, "stats.ascan.time": ' . $time . '}}');

                return [0, '', ''];
            };
        };

        $result = (new Zap(run: $run('600000'), os: 'Darwin', scan: 'full', scanMinutes: 10))->scan(self::URL);
        $this->assertCount(5, (array)$result->rules);
        $this->assertSame([false, true], array_column(array_values(array_filter((array)$result->rules, static fn(array $rule): bool => $rule['type'] === 'active')), 'skipped'));
        $this->assertTrue($result->activeLimitReached);

        $this->assertFalse((new Zap(run: $run('599000'), os: 'Darwin', scan: 'full', scanMinutes: 10))->scan(self::URL)->activeLimitReached);
        // The baseline scan has no active scan
        $this->assertFalse((new Zap(run: $run('600000'), os: 'Darwin'))->scan(self::URL)->activeLimitReached);
        // Without the report of the rules, e.g. an older plan: unknown
        $this->assertNull(self::zap()->scan(self::URL)->rules);
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
        // The password is given in the environment of the container, by its name only
        $this->assertStringNotContainsString('secret', $plans[0]);
        $this->assertContains(Zap::PASSWORD_ENV, $commands[0]);
        $this->assertEmpty(preg_grep('/secret/', $commands[0]));
    }

    public function testAbsoluteLoginUrl(): void
    {
        $zap = new Zap(os: 'Darwin', login: new Login('http://127.0.0.1:56557/users/login', 'test@example.com', 'secret'));

        $this->assertSame(self::TARGET . '/users/login', $zap->plan(self::TARGET)['env']['contexts'][0]['authentication']['parameters']['loginPageUrl']);
    }

    public function testWorkDir(): void
    {
        $dir = Zap::workDir();
        file_put_contents($dir . DS . 'plan.yaml', '{}');
        mkdir($dir . DS . 'home' . DS . '.ZAP');
        file_put_contents($dir . DS . 'home' . DS . '.ZAP' . DS . 'config.xml', '<config/>');

        // Only the user, as whom ZAP runs, and removed with the home of ZAP
        $this->assertSame('700', substr(sprintf('%o', fileperms($dir)), -3));
        $this->assertSame([], Zap::containerUser('Darwin'));
        Zap::removeDir($dir);
        $this->assertDirectoryDoesNotExist($dir);
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
        $this->assertSame('http://host.docker.internal:8080/', $zap->targetFromContainer('http://[::1]:8080/'));

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

    public function testOnlyTheSiteOfTheScan(): void
    {
        // A site that the browser of a scenario requested through the proxy, e.g. a CDN
        $data = (array)json_decode((string)file_get_contents(self::FIXTURES . DS . 'zap-en.json'), true);
        $other = $data['site'][0];
        $other['@name'] = 'https://cdn.example.com';
        $other['@host'] = 'cdn.example.com';
        $other['@port'] = '443';
        $other['@ssl'] = 'true';
        foreach ($other['alerts'] as $i => $alert) {
            foreach ($alert['instances'] as $j => $instance) {
                $other['alerts'][$i]['instances'][$j]['uri'] = 'https://cdn.example.com/lib.js';
            }
        }
        $data['site'][] = $other;

        $result = Zap::result($data, self::URL, self::TARGET);

        $this->assertCount(17, $result->alerts);
        $this->assertSame(7, $result->urls());
        $uris = array_merge(...array_map(static fn($alert): array => array_column($alert->instances, 'uri'), $result->alerts));
        $this->assertSame([], preg_grep('#cdn\.example\.com#', $uris));
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

    /**
     * The passing rules of the modern report of ZAP: the active rules have a strength, the passive ones `-`; an active
     * rule that ZAP skipped has its statistic
     *
     * @return void
     */
    public function testRules(): void
    {
        $html = (string)file_get_contents(self::FIXTURES . DS . 'zap-rules.html');
        $rules = Zap::rules($html, ['stats.ascan.40012.skipped' => 0, 'stats.ascan.90019.skipped' => 1]);

        $this->assertSame(['id' => '10003', 'name' => 'Vulnerable JS Library', 'type' => 'passive', 'skipped' => false], $rules[0]);
        $this->assertContains(['id' => '10011', 'name' => 'Cookie Without Secure Flag', 'type' => 'passive', 'skipped' => false], $rules);
        $this->assertContains(['id' => '40012', 'name' => 'Cross Site Scripting (Reflected)', 'type' => 'active', 'skipped' => false], $rules);
        $this->assertContains(['id' => '90019', 'name' => 'Server Side Code Injection', 'type' => 'active', 'skipped' => true], $rules);
        // The passive rules, then the active ones, by ID
        $types = array_column($rules, 'type');
        $this->assertSame($types, array_merge(array_filter($types, static fn(string $type): bool => $type === 'passive'), array_values(array_filter($types, static fn(string $type): bool => $type === 'active'))));
        $this->assertContains(['id' => '10099', 'name' => 'Cookie <Name> & "Quoted"', 'type' => 'passive', 'skipped' => false], $rules);

        $this->assertSame([], Zap::rules('<html></html>'));
    }

    /**
     * The passing rules and the statistics of a full scan of ZAP 2.17.0 (the Samples workflow)
     *
     * @return void
     */
    public function testRulesOfARecordedFullScan(): void
    {
        $statistics = Zap::statistics((array)json_decode((string)file_get_contents(self::FIXTURES . DS . 'zap-stats-full.json'), true));
        $rules = Zap::rules((string)file_get_contents(self::FIXTURES . DS . 'zap-rules-full.html'), $statistics);

        $this->assertCount(108, $rules);
        $active = array_values(array_filter($rules, static fn(array $rule): bool => $rule['type'] === 'active'));
        $this->assertCount(51, $active);
        $this->assertContains(['id' => '10011', 'name' => 'Cookie Without Secure Flag', 'type' => 'passive', 'skipped' => false], $rules);
        $this->assertSame(['id' => '0', 'name' => 'Directory Browsing', 'type' => 'active', 'skipped' => false], $active[0]);
        // Log4Shell is listed among the completed rules, but ZAP skipped it
        $this->assertSame([['id' => '40043', 'name' => 'Log4Shell', 'type' => 'active', 'skipped' => true]], array_values(array_filter($rules, static fn(array $rule): bool => $rule['skipped'])));
        // The rule of the alert of the scan (XSS) is not a passing rule
        $this->assertNotContains('40012', array_column($active, 'id'));
        $this->assertLessThan(5 * 60000, $statistics['stats.ascan.time']);
    }

    public function testStatistics(): void
    {
        $data = ['statistics' => ['stats.ascan.time' => 61000, 'stats.ascan.90019.skipped' => 1], 'site' => [['statistics' => ['stats.code.200' => 4, 'stats.ascan.90019.skipped' => 1]], ['@name' => 'x']]];

        $this->assertSame(['stats.ascan.time' => 61000, 'stats.ascan.90019.skipped' => 1, 'stats.code.200' => 4], Zap::statistics($data));
        $this->assertSame([], Zap::statistics([]));
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
        // The passive rules that ran and raised no alert: not those of the alerts
        $this->assertNotNull($result->rules);
        $this->assertGreaterThan(20, count($result->rules));
        $this->assertSame(['passive'], array_values(array_unique(array_column($result->rules, 'type'))));
        $this->assertNotContains('10202', array_column($result->rules, 'id'));
        $this->assertNotContains('10037', array_column($result->rules, 'id'));
        $this->assertContains('10038-1', $ids);
        $this->assertContains('10037', $ids);

        // The full scan: the active rules too, and the statistics of the rules that it skipped
        $server = new WebServer(self::FIXTURES . DS . 'app' . DS . 'webroot', self::FIXTURES . DS . 'app');
        try {
            $result = (new Zap(scan: 'full', scanMinutes: 2))->scan($server->start());
        } finally {
            $server->stop();
        }
        $active = array_values(array_filter((array)$result->rules, static fn(array $rule): bool => $rule['type'] === 'active'));
        $this->assertGreaterThan(10, count($active));
        $this->assertNotContains('', array_column($active, 'name'));

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
            $other = '';
            $result = (new Zap())->scanScenario($server->start(), static function (string $proxy, string $site) use (&$other): void {
                $context = stream_context_create(['http' => ['proxy' => str_replace('http://', 'tcp://', $proxy), 'request_fulluri' => true, 'ignore_errors' => true]]);
                file_get_contents($site . '/login', false, $context);
                // A request to another site through the proxy, as a browser makes to a CDN
                $other = (string)file_get_contents('http://example.com/', false, $context);
            });
        } finally {
            $server->stop();
        }
        $uris = array_merge(...array_map(static fn($alert): array => array_column($alert->instances, 'uri'), $result->alerts));
        $this->assertNotEmpty(preg_grep('#/login#', $uris));
        // The other site was reached through the proxy, and only the site of the scan is reported
        $this->assertStringContainsString('Example Domain', $other);
        $this->assertSame([], preg_grep('#\Ahttps?://example\.com#', $uris));
    }
}
