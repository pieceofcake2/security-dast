<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast\Test;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PieceOfCake2\Report\Environment;
use PieceOfCake2\Report\HtmlRenderer;
use PieceOfCake2\SecurityDast\Alert;
use PieceOfCake2\SecurityDast\Config;
use PieceOfCake2\SecurityDast\ReportBuilder;
use PieceOfCake2\SecurityDast\ScanResult;
use PieceOfCake2\SecurityDast\Zap;

class ReportBuilderTest extends TestCase
{
    /**
     * The HTML of the report of a result
     *
     * @param \PieceOfCake2\SecurityDast\ScanResult $result Result
     * @param \PieceOfCake2\SecurityDast\Config $config Settings
     * @return string
     */
    private static function html(ScanResult $result, Config $config): string
    {
        $doc = (new ReportBuilder(ReportBuilder::translator($config->language)))->build($result, $config, new Environment(), new DateTimeImmutable('2026-10-17 10:00:00'), new DateTimeImmutable('2026-10-17 10:01:15'));

        return (new HtmlRenderer())->render($doc);
    }

    /**
     * The result of the fixture report
     *
     * @param string $language Language
     * @return \PieceOfCake2\SecurityDast\ScanResult
     */
    private static function result(string $language = 'en'): ScanResult
    {
        $data = json_decode((string)file_get_contents(ZapTest::FIXTURES . DS . 'zap-' . $language . '.json'), true);

        return Zap::result($data, ZapTest::URL, ZapTest::TARGET);
    }

    public function testReport(): void
    {
        $html = self::html(self::result(), new Config(project: ZapTest::FIXTURES . DS . 'app', failOn: 'medium'));

        $this->assertStringContainsString('<h1>Dynamic Application Security Testing Report</h1>', $html);
        $this->assertStringContainsString('<div class="project">acme/app</div>', $html);
        $this->assertStringContainsString('<div class="verdict failed">', $html);
        $this->assertStringContainsString('<b class="t-medium">4</b><span>Medium</span>', $html);
        $this->assertStringContainsString('<b class="t-info">3</b><span>Informational</span>', $html);
        $this->assertStringContainsString('PHP&#039;s built-in web server (APP_ENV=test)', $html);
        $this->assertStringContainsString('OWASP ZAP baseline scan: crawl (1 min) and check the responses', $html);
        $this->assertMatchesRegularExpression('#<th>Login</th>\s*<td>\s*None \(as a visitor\)#', $html);
        $this->assertStringContainsString('it sends no attacks', $html);
        $this->assertStringContainsString('OWASP ZAP 2.17.0', $html);
        // Every risk, also without alerts
        $this->assertMatchesRegularExpression('#<h3>1\. High</h3>\s*<p class="empty">None</p>#', $html);
        $this->assertMatchesRegularExpression('#<h3>2\. Medium</h3>.*<h3>3\. Low</h3>.*<h3>4\. Informational</h3>#s', $html);
        // Without the list of the rules of ZAP, no checks
        $this->assertStringNotContainsString('<section class="checks">', $html);

        // The page of an alert: the solution, the requests and the links
        $this->assertStringContainsString('Absence of Anti-CSRF Tokens', $html);
        $this->assertStringContainsString('<span class="method post">POST</span><span class="uri"><span class="origin">http://127.0.0.1:56557</span><span class="path">/login</span></span>', $html);
        $this->assertStringContainsString('<th>Evidence</th><td>&lt;form method=&quot;post&quot; action=&quot;/login&quot;&gt;</td>', $html);
        $this->assertStringContainsString('<a href="https://www.zaproxy.org/docs/alerts/10202/">', $html);
        $this->assertStringContainsString('<h4>Solution</h4>', $html);
        $this->assertStringContainsString('.instance .method', $html);
    }

    public function testIgnored(): void
    {
        $result = self::result();
        $result->ignore(['10038']);
        $html = self::html($result, new Config());

        $this->assertMatchesRegularExpression('#<th>Ignored alerts</th>\s*<td>\s*10038-1\s*</td>#', $html);
        $this->assertMatchesRegularExpression('#<th>Excluded URLs</th>\s*<td>\s*\.\*logout\.\*\s*</td>#', $html);
        // Not a page of its own, but a row of the ignored alerts, which do not fail the scan
        $this->assertDoesNotMatchRegularExpression('#<h3>[\d-]+　Content Security Policy#u', $html);
        $this->assertMatchesRegularExpression('#<h3>\d+\. Ignored alerts</h3>#', $html);
        $this->assertMatchesRegularExpression('#<tr><td class="mono">10038-1</td><td>Content Security Policy \(CSP\) Header Not Set</td><td>Medium</td><td class="num">\d+</td><td>-</td></tr>#', $html);
        $this->assertCount(1, $result->ignoredAlerts);

        // With its reason from the settings file
        $html = self::html($result, new Config(ignore: ['10038'], ignoreReasons: ['10038' => 'The load balancer adds CSP']));
        $this->assertMatchesRegularExpression('#<td class="num">\d+</td><td>The load balancer adds CSP</td></tr>#', $html);
    }

    public function testReportInJapanese(): void
    {
        $html = self::html(self::result('ja'), new Config(project: ZapTest::FIXTURES . DS . 'app', url: 'https://staging.example.com', language: 'ja', ajaxSpider: true, minutes: 2));

        $this->assertStringContainsString('<div class="verdict passed">', $html);
        $this->assertStringContainsString('Anti-CSRFトークンが使用されていない', $html);
        $this->assertStringContainsString('起動済みのアプリケーション', $html);
        $this->assertStringContainsString('クロール（2 分）とレスポンスの確認、AJAX スパイダー付き', $html);
        $this->assertStringContainsString('<th>根拠</th>', $html);
    }

    public function testFullScanWithLogin(): void
    {
        $result = self::result();
        $result->user = 'test@example.com';
        $html = self::html($result, new Config(scan: 'full', scanMinutes: 15, loginUrl: '/users/login', username: 'test@example.com', password: 'secret'));

        $this->assertStringContainsString('OWASP ZAP full scan: crawl (1 min), check the responses and attack (up to 15 min)', $html);
        $this->assertStringContainsString('test@example.com on http://127.0.0.1:56557/users/login', $html);
        $this->assertStringContainsString('The full scan attacks the pages', $html);
        $this->assertStringNotContainsString('secret', $html);
    }

    public function testEndToEndTests(): void
    {
        $result = self::result();
        $result->endToEndExitCode = 1;
        $html = self::html($result, new Config(scan: 'full', endToEnd: 'vendor/bin/phpunit --testsuite EndToEnd'));

        $this->assertStringContainsString('OWASP ZAP: check the requests and responses of the End-to-End tests, and attack them', $html);
        $this->assertMatchesRegularExpression('#<th>End-to-End tests</th>\s*<td>\s*vendor/bin/phpunit --testsuite EndToEnd\s*<br><span class="warn">The tests failed \(exit code 1\)#', $html);

        // Passed tests: no warning
        $passed = self::result();
        $passed->endToEndExitCode = 0;
        $this->assertStringNotContainsString('class="warn"', self::html($passed, new Config(endToEnd: 'phpunit')));
    }

    public function testNoAlert(): void
    {
        $html = self::html(new ScanResult([], ZapTest::URL, '2.17.0'), new Config(projectName: 'Acme', note: 'Staging'));

        $this->assertStringContainsString('<div class="verdict passed">', $html);
        $this->assertStringContainsString('No alert was raised on the crawled pages.', $html);
        $this->assertStringContainsString('Staging', $html);
    }

    public function testManyRequests(): void
    {
        $instances = array_fill(0, ReportBuilder::MAX_INSTANCES + 3, ['method' => 'GET', 'uri' => ZapTest::URL . '/a', 'param' => '', 'attack' => '', 'evidence' => '', 'otherinfo' => '']);
        $html = self::html(new ScanResult([new Alert('10021', 'X-Content-Type-Options Header Missing', 'low', 'Medium', instances: $instances)], ZapTest::URL), new Config());

        $this->assertStringContainsString('and 3 more requests', $html);
        $this->assertSame(ReportBuilder::MAX_INSTANCES, substr_count($html, '<div class="instance">'));
    }

    /**
     * The scan rules that ran and raised no alert, and those that did not run
     *
     * @return void
     */
    public function testChecks(): void
    {
        $result = self::result();
        $result->rules = Zap::rules((string)file_get_contents(ZapTest::FIXTURES . DS . 'zap-rules.html'), ['stats.ascan.90019.skipped' => 1]);
        $html = self::html($result, new Config(scan: 'full', scanMinutes: 5));

        $this->assertStringContainsString('<h2>Checks</h2>', $html);
        $passed = self::section($html, 'Passed checks');
        $this->assertStringContainsString('<thead><tr><th>Rule ID</th><th>Scan rule</th><th>Scan</th></tr></thead>', $passed);
        $this->assertMatchesRegularExpression('#<td class="mono">10003</td>\s*<td>Vulnerable JS Library</td>\s*<td>Passive</td>#', $passed);
        $this->assertMatchesRegularExpression('#<td class="mono">40012</td>\s*<td>Cross Site Scripting \(Reflected\)</td>\s*<td>Active</td>#', $passed);
        $this->assertStringContainsString('<td>Cookie &lt;Name&gt; &amp; &quot;Quoted&quot;</td>', $passed);
        $this->assertStringNotContainsString('90019', $passed);
        $notRun = self::section($html, 'Not run');
        $this->assertMatchesRegularExpression('#<td class="mono">90019</td>\s*<td>Server Side Code Injection</td>\s*<td>Active</td>\s*<td>Skipped by ZAP: [^<]+</td>#', $notRun);
        $this->assertStringNotContainsString('time limit (5 min)', $notRun);

        $result->activeLimitReached = true;
        $this->assertStringContainsString('The active scan reached its time limit (5 min)', self::section(self::html($result, new Config(scan: 'full', scanMinutes: 5)), 'Not run'));
    }

    public function testChecksOfTheBaselineScan(): void
    {
        $result = self::result('ja');
        $result->rules = [['id' => '10011', 'name' => 'Secure フラグのない Cookie', 'type' => 'passive', 'skipped' => false]];
        $html = self::html($result, new Config(language: 'ja'));

        $this->assertStringContainsString('<h2>確認した項目</h2>', $html);
        $this->assertMatchesRegularExpression('#<td class="mono">10011</td>\s*<td>Secure フラグのない Cookie</td>\s*<td>パッシブ</td>#', self::section($html, '確認した項目（問題なし）'));
        // The active rules, which the baseline scan does not run
        $this->assertMatchesRegularExpression('#<td class="mono">-</td>\s*<td>アクティブスキャン（攻撃）のルール</td>\s*<td>アクティブ</td>\s*<td>ベースラインスキャンは攻撃しません。[^<]*</td>#', self::section($html, '実行しなかった項目'));
        $this->assertMatchesRegularExpression('#<h3>1\. 高</h3>\s*<p class="empty">なし</p>#', $html);
    }

    /**
     * The table of checks of a heading
     *
     * @param string $html HTML
     * @param string $title Heading
     * @return string
     */
    private static function section(string $html, string $title): string
    {
        $start = strpos($html, '<h3>' . $title . '</h3>');
        self::assertNotFalse($start, $title);
        $end = strpos($html, '</div>', $start);

        return substr($html, $start, $end - $start);
    }
}
