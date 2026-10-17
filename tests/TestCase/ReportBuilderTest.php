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
        $this->assertMatchesRegularExpression('#<h3>1\. Medium</h3>.*<h3>2\. Low</h3>.*<h3>3\. Informational</h3>#s', $html);

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
        $this->assertStringNotContainsString('Content Security Policy (CSP) Header Not Set', $html);
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
}
