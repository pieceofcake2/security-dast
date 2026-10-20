<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast\Test;

use PHPUnit\Framework\TestCase;
use PieceOfCake2\SecurityDast\Zap;
use PieceOfCake2\SecurityDast\ZapDaemon;
use RuntimeException;

class ZapDaemonTest extends TestCase
{
    public function testCommand(): void
    {
        $command = (new ZapDaemon('/tmp/zap', ['-config', 'view.locale=ja_JP'], os: 'Darwin', port: 8090))->command();

        // Without --rm: a container that exited stays until stop(), to tell why
        $this->assertSame(['docker', 'run', '-d', '-p', '127.0.0.1:8090:8090', '-v', '/tmp/zap:/zap/wrk:rw', 'ghcr.io/zaproxy/zaproxy:stable', 'zap.sh', '-daemon', '-host', '0.0.0.0', '-port', '8090'], array_slice($command, 0, 14));
        // The key is in a file of the directory, not on the command line
        $this->assertSame('-configfile', $command[14]);
        $this->assertMatchesRegularExpression('#\A/zap/wrk/key-[0-9a-f]{16}\.properties\z#', $command[15]);
        $this->assertEmpty(preg_grep('/api\.key/', $command));
        $this->assertSame(['view.locale=ja_JP'], array_slice($command, -1));
    }

    public function testCommandOnLinux(): void
    {
        $command = (new ZapDaemon('/tmp/zap', os: 'Linux', port: 8090))->command();

        // The network of the host, ZAP as the user of this process, and the proxy and the API on 127.0.0.1 only
        $this->assertSame(['docker', 'run', '-d', '--network', 'host'], array_slice($command, 0, 5));
        $this->assertSame(Zap::containerUser('Linux'), array_slice($command, 5, 6));
        $this->assertSame(['-host', '127.0.0.1', '-port', '8090'], array_slice($command, 16, 4));
        $this->assertSame(['-dir', '/zap/wrk/home/.ZAP'], array_slice($command, -2));
    }

    public function testStartWithAKeyFileAndEnvironment(): void
    {
        $dir = Zap::workDir();
        $runs = [];
        $daemon = new ZapDaemon($dir, os: 'Darwin', port: 8090, run: static function (array $command, string $cwd, array $env = []) use (&$runs, $dir): array {
            $file = $command[1] === 'run' ? $dir . DS . basename($command[array_search('-configfile', $command, true) + 1]) : null;
            $runs[] = [$command, $env, $file !== null ? (string)file_get_contents($file) : null];

            return [0, "container-id\n", ''];
        }, get: static fn(string $url): string => str_contains($url, 'version') ? '{"version":"2.17.0"}' : '{}', env: [Zap::PASSWORD_ENV => 'secret']);
        try {
            $daemon->start();
            [$command, $env, $key] = $runs[0];

            $this->assertSame(['-e', Zap::PASSWORD_ENV], array_slice($command, 5, 2));
            $this->assertSame([Zap::PASSWORD_ENV => 'secret'], $env);
            $this->assertMatchesRegularExpression('/\Aapi\.key=[0-9a-f]{32}\n\z/', (string)$key);
            // Removed once ZAP has started; only the user could read it
            $this->assertSame(['.', '..', 'home'], scandir($dir));

            $daemon->stop();
            $this->assertSame(['docker', 'rm', '-f', 'container-id'], $runs[1][0]);
        } finally {
            Zap::removeDir($dir);
        }
        $this->assertDirectoryDoesNotExist($dir);
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

    public function testRunPlanWhileZapDoesNotAnswerForAWhile(): void
    {
        $answers = [false, '', 'Internal error', '{"finished":""}', false, '{"finished":"2026-10-20T04:40:00Z","error":[]}'];
        $polls = 0;
        $sleeps = [];
        $daemon = new ZapDaemon(TMP, port: 8090, run: static fn(array $command, string $cwd): array => [0, "id\n", ''], get: static function (string $url) use (&$answers, &$polls): string|false {
            if (str_contains($url, 'version')) {
                return '{"version":"2.17.0"}';
            }
            if (str_contains($url, 'planProgress')) {
                $polls++;

                return array_shift($answers);
            }

            return str_contains($url, 'runPlan') ? '{"planId":"3"}' : '{"Result":"OK"}';
        }, sleep: static function (int $microseconds) use (&$sleeps): void {
            $sleeps[] = $microseconds;
        });
        $daemon->start();
        $daemon->runPlan(['jobs' => []]);

        // Polled again until ZAP answered that the plan finished
        $this->assertSame(6, $polls);
        $this->assertSame(array_fill(0, 5, 2000000), $sleeps);
    }

    public function testRunPlanWhenZapStopsAnswering(): void
    {
        $dir = Zap::workDir();
        $commands = [];
        $daemon = new ZapDaemon($dir, os: 'Linux', port: 8090, run: static function (array $command, string $cwd) use (&$commands): array {
            $commands[] = $command;
            if ($command[1] === 'inspect') {
                return [0, "exited 137 true\n", ''];
            }

            return [0, "container-id\n", ''];
        }, get: static function (string $url) use (&$key): string|false {
            if (str_contains($url, 'version')) {
                $key = (string)preg_replace('/.*apikey=([0-9a-f]+).*/', '$1', $url);

                return '{"version":"2.17.0"}';
            }
            if (str_contains($url, 'planProgress')) {
                return false;
            }

            return str_contains($url, 'runPlan') ? '{"planId":"4"}' : '{"Result":"OK"}';
        }, env: [Zap::PASSWORD_ENV => 'secret'], sleep: static function (int $microseconds): void {
        }, unanswered: 0);
        try {
            $daemon->start();
            mkdir($dir . DS . 'home' . DS . '.ZAP');
            $lines = array_map(static fn(int $i): string => 'line ' . $i, range(1, 30));
            $lines[] = 'java.lang.OutOfMemoryError: Java heap space, key ' . $key . ', password secret';
            file_put_contents($dir . DS . 'home' . DS . '.ZAP' . DS . 'zap.log', implode("\n", $lines) . "\n");

            try {
                $daemon->runPlan(['jobs' => []]);
                $this->fail('No exception');
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
            }
        } finally {
            $daemon->stop();
            Zap::removeDir($dir);
        }

        $this->assertStringStartsWith("ZAP did not answer automation/view/planProgress for 0 seconds.\nThe container of ZAP is not running: exited, exit code 137, OOMKilled.\nThe last lines of zap.log:\nline 12\n", $message);
        // The last 20 lines, without the key of the API and the environment of ZAP
        $this->assertStringEndsWith("line 30\njava.lang.OutOfMemoryError: Java heap space, key ***, password ***", $message);
        $this->assertStringNotContainsString('line 11', $message);
        $this->assertSame(['docker', 'inspect', '--format', '{{.State.Status}} {{.State.ExitCode}} {{.State.OOMKilled}}', 'container-id'], $commands[1]);
        $this->assertSame(['docker', 'rm', '-f', 'container-id'], $commands[2]);
    }

    public function testRunPlanWhenTheContainerIsGone(): void
    {
        $daemon = new ZapDaemon(TMP, os: 'Darwin', port: 8090, run: static function (array $command, string $cwd): array {
            return match ($command[1]) {
                'inspect' => [1, '', 'Error: No such object: id'],
                'logs' => [1, '', 'Error response from daemon: No such container: id'],
                default => [0, "id\n", ''],
            };
        }, get: static function (string $url): string {
            if (str_contains($url, 'version')) {
                return '{"version":"2.17.0"}';
            }

            return str_contains($url, 'runPlan') ? '{"planId":"5"}' : (str_contains($url, 'planProgress') ? 'Internal error' : '{}');
        }, unanswered: 0);
        $daemon->start();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("ZAP did not answer automation/view/planProgress for 0 seconds: Internal error.\nThe container of ZAP is gone.\nThe last lines of the output of the container:\nError response from daemon: No such container: id");
        $daemon->runPlan(['jobs' => []]);
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
