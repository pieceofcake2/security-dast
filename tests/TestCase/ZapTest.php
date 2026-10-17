<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast\Test;

use PHPUnit\Framework\TestCase;
use PieceOfCake2\SecurityDast\WebServer;
use PieceOfCake2\SecurityDast\Zap;
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
     * @return \PieceOfCake2\SecurityDast\Zap
     */
    public static function zap(string $language = 'en', array &$commands = [], string $os = 'Darwin'): Zap
    {
        return new Zap(run: static function (array $command, string $dir) use ($language, &$commands): array {
            $commands[] = $command;
            copy(self::FIXTURES . DS . 'zap-' . $language . '.json', $dir . DS . 'zap.json');

            return [2, 'WARN-NEW: 13', ''];
        }, os: $os);
    }

    public function testCommand(): void
    {
        $dir = TMP . 'zap-command';
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $zap = new Zap('ghcr.io/zaproxy/zaproxy:2.17.0', 'podman', 3, true, '-config spider.maxDepth=3', os: 'Darwin');

        $command = $zap->command($dir, self::TARGET, 'ja');

        $this->assertSame(['podman', 'run', '--rm', '-v', $dir . ':/zap/wrk:rw', 'ghcr.io/zaproxy/zaproxy:2.17.0', 'zap-baseline.py', '-t', self::TARGET, '-J', 'zap.json', '-I', '-m', '3', '-j', '-z'], array_slice($command, 0, -1));
        $options = (string)end($command);
        $this->assertStringContainsString('-config anticsrf.tokens.token(100).name=data[_Token][key] -config anticsrf.tokens.token(100).enabled=true', $options);
        $this->assertStringContainsString('-config view.locale=ja_JP', $options);
        $this->assertStringEndsWith('-config spider.maxDepth=3', $options);
    }

    public function testCommandOnLinux(): void
    {
        $command = (new Zap(os: 'Linux'))->command('/tmp/zap', self::URL);

        $this->assertSame(['docker', 'run', '--rm', '--network', 'host', '-v', '/tmp/zap:/zap/wrk:rw'], array_slice($command, 0, 7));
        $this->assertStringNotContainsString('view.locale', (string)end($command));
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

        $this->assertSame(self::TARGET, $commands[0][array_search('-t', $commands[0], true) + 1]);
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
     * Runs ZAP in Docker against the fixture application, when Docker is available (as on GitHub Actions)
     *
     * @return void
     */
    public function testScanWithDocker(): void
    {
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
    }
}
