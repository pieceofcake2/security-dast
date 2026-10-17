<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast\Test;

use PHPUnit\Framework\TestCase;
use PieceOfCake2\SecurityDast\EndToEndTests;

class EndToEndTestsTest extends TestCase
{
    public function testEnv(): void
    {
        $tests = new EndToEndTests('vendor/bin/phpunit --testsuite EndToEnd', '.', ['PANTHER_CHROME_ARGUMENTS' => '--window-size=1280,800']);

        $this->assertSame([
            'PANTHER_EXTERNAL_BASE_URI' => 'http://host.docker.internal:8123',
            'PANTHER_CHROME_ARGUMENTS' => '--window-size=1280,800 --proxy-server=http://127.0.0.1:8090 --proxy-bypass-list=<-loopback> --ignore-certificate-errors',
        ], $tests->env('http://127.0.0.1:8090', 'http://host.docker.internal:8123/'));
    }

    public function testRun(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo getenv("PANTHER_EXTERNAL_BASE_URI"), " ", getenv("APP_ENV"); fwrite(STDERR, " failed"); exit(1);');
        $output = fopen('php://memory', 'w+');

        $code = (new EndToEndTests($command, TMP, ['APP_ENV' => 'test']))->run('http://127.0.0.1:8090', 'http://127.0.0.1:8123', $output);

        $this->assertSame(1, $code);
        rewind($output);
        $this->assertSame('http://127.0.0.1:8123 test failed', stream_get_contents($output));
    }
}
