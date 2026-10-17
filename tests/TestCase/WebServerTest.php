<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast\Test;

use PHPUnit\Framework\TestCase;
use PieceOfCake2\SecurityDast\WebServer;
use RuntimeException;

class WebServerTest extends TestCase
{
    /**
     * The status line and the body of a page
     *
     * @param string $url URL
     * @return array{0: string, 1: string}
     */
    private static function get(string $url): array
    {
        $body = (string)file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true]]));
        $headers = function_exists('http_get_last_response_headers') ? (array)http_get_last_response_headers() : $GLOBALS['http_response_header'] ?? [];

        return [(string)($headers[0] ?? ''), $body];
    }

    public function testStart(): void
    {
        $app = ZapTest::FIXTURES . DS . 'app';
        $server = new WebServer($app . DS . 'webroot', $app, ['APP_ENV' => 'test']);
        try {
            $url = $server->start();
            $this->assertMatchesRegularExpression('#\Ahttp://127\.0\.0\.1:\d+\z#', $url);

            // The pages go to index.php, through the router script
            [$status, $body] = self::get($url . '/login');
            $this->assertStringContainsString('200', $status);
            $this->assertStringContainsString('<h1>Login</h1>', $body);
            [$status] = self::get($url . '/error');
            $this->assertStringContainsString('500', $status);
        } finally {
            $server->stop();
        }
    }

    public function testMissingIndex(): void
    {
        $server = new WebServer(ZapTest::FIXTURES, ZapTest::FIXTURES);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no index.php');
        $server->start();
    }

    public function testFreePort(): void
    {
        $this->assertGreaterThan(0, WebServer::freePort());
    }
}
