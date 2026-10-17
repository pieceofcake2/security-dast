<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast\Test;

use PHPUnit\Framework\TestCase;
use PieceOfCake2\SecurityDast\ZapDaemon;
use RuntimeException;

class ZapDaemonTest extends TestCase
{
    public function testCommand(): void
    {
        $command = (new ZapDaemon('/tmp/zap', ['-config', 'view.locale=ja_JP'], os: 'Darwin', port: 8090))->command();

        $this->assertSame(['docker', 'run', '-d', '--rm', '-p', '127.0.0.1:8090:8090', '-v', '/tmp/zap:/zap/wrk:rw', 'ghcr.io/zaproxy/zaproxy:stable', 'zap.sh', '-daemon', '-host', '0.0.0.0', '-port', '8090'], array_slice($command, 0, 15));
        $this->assertMatchesRegularExpression('/\Aapi\.key=[0-9a-f]{32}\z/', $command[16]);
        $this->assertSame(['view.locale=ja_JP'], array_slice($command, -1));
    }

    public function testCommandOnLinux(): void
    {
        $command = (new ZapDaemon('/tmp/zap', os: 'Linux', port: 8090))->command();

        // The network of the host, and the proxy and the API on 127.0.0.1 only
        $this->assertSame(['docker', 'run', '-d', '--rm', '--network', 'host'], array_slice($command, 0, 6));
        $this->assertSame(['-host', '127.0.0.1', '-port', '8090'], array_slice($command, 11, 4));
    }

    public function testRunPlanFailed(): void
    {
        $dir = TMP . 'zap-daemon';
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $daemon = new ZapDaemon($dir, port: 8090, run: static fn(array $command, string $cwd): array => [0, "id\n", ''], get: static function (string $url): string {
            if (str_contains($url, 'core/view/version')) {
                return '{"version":"2.17.0"}';
            }

            return str_contains($url, 'runPlan') ? '{"planId":"1"}' : '{"finished":"2026-10-17T10:00:00Z","error":["Job spider failed"]}';
        });
        $this->assertSame('http://127.0.0.1:8090', $daemon->start());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The plan of ZAP failed: Job spider failed');
        $daemon->runPlan(['jobs' => []]);
    }

    public function testNoAnswer(): void
    {
        $commands = [];
        $daemon = new ZapDaemon(TMP, port: 8090, run: static function (array $command, string $cwd) use (&$commands): array {
            $commands[] = implode(' ', array_slice($command, 0, 3));

            return [0, "id\n", ''];
        }, get: static fn(string $url) => false);

        try {
            $daemon->start(0);
            $this->fail('No exception');
        } catch (RuntimeException $e) {
            $this->assertSame('ZAP did not answer on port 8090 in 0 seconds.', $e->getMessage());
        }
        // The container is removed
        $this->assertSame(['docker run -d', 'docker rm -f'], $commands);
    }

    public function testRunPlanWithoutId(): void
    {
        $daemon = new ZapDaemon(TMP, port: 8090, run: static fn(array $command, string $cwd): array => [0, "id\n", ''], get: static function (string $url): string {
            if (str_contains($url, 'version')) {
                return '{"version":"2.17.0"}';
            }

            return str_contains($url, 'httpSessions') ? '{"Result":"OK"}' : 'Internal error';
        });
        $daemon->start();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ZAP did not answer automation/action/runPlan: Internal error');
        $daemon->runPlan(['jobs' => []]);
    }

    public function testRunPlanTimeout(): void
    {
        $daemon = new ZapDaemon(TMP, port: 8090, run: static fn(array $command, string $cwd): array => [0, "id\n", ''], get: static function (string $url): string {
            if (str_contains($url, 'version')) {
                return '{"version":"2.17.0"}';
            }

            return str_contains($url, 'runPlan') ? '{"planId":"2"}' : '{"finished":""}';
        });
        $daemon->start();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The plan of ZAP did not finish in 0 seconds.');
        $daemon->runPlan(['jobs' => []], 0);
    }

    public function testUseLastSession(): void
    {
        $urls = [];
        $daemon = new ZapDaemon(TMP, port: 8090, run: static fn(array $command, string $cwd): array => [0, "id\n", ''], get: static function (string $url) use (&$urls): string {
            $urls[] = urldecode((string)preg_replace('/apikey=[0-9a-f]+&?/', '', substr($url, strlen('http://127.0.0.1:8090/JSON/'))));
            if (str_contains($url, 'version')) {
                return '{"version":"2.17.0"}';
            }

            return str_contains($url, 'view/sessions') ? '{"sessions":[{"session":["Session 0",{}]},{"session":["Session 1",{}]}]}' : '{"Result":"OK"}';
        });
        $daemon->start();
        $daemon->useLastSession('http://host.docker.internal:8123/');

        $this->assertSame([
            'core/view/version/?',
            'httpSessions/action/addDefaultSessionToken/?sessionToken=CAKEPHP',
            'httpSessions/view/sessions/?site=host.docker.internal:8123',
            'httpSessions/action/setActiveSession/?site=host.docker.internal:8123&session=Session 1',
        ], $urls);
    }

    public function testStartFailed(): void
    {
        $daemon = new ZapDaemon(TMP, port: 8090, run: static fn(array $command, string $cwd): array => [125, '', 'Cannot connect to the Docker daemon']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ZAP did not start (exit code 125): Cannot connect to the Docker daemon');
        $daemon->start();
    }
}
