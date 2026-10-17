<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast\Test;

use PHPUnit\Framework\TestCase;
use PieceOfCake2\SecurityDast\Config;

class ConfigTest extends TestCase
{
    public function testWebrootOfDefine(): void
    {
        $project = ZapTest::FIXTURES . DS . 'project';

        $this->assertSame(realpath($project . DS . 'public'), realpath((new Config(project: $project))->webroot()));
    }

    public function testWebrootOfLayouts(): void
    {
        $this->assertSame(ZapTest::FIXTURES . DS . 'app' . DS . 'webroot', (new Config(project: ZapTest::FIXTURES . DS . 'app'))->webroot());
        $this->assertSame(ZapTest::FIXTURES . DS . 'legacy' . DS . 'app/webroot', (new Config(project: ZapTest::FIXTURES . DS . 'legacy'))->webroot());
    }

    public function testZap(): void
    {
        $config = new Config(minutes: 2, ajaxSpider: true, scan: 'full', scanMinutes: 15, loginUrl: '/users/login', username: 'test@example.com', password: 'secret', exclude: ['.*/admin/.*'], zapOptions: '-config spider.maxDepth=3');
        $zap = $config->zap();

        $plan = $zap->plan('http://host.docker.internal:8123');
        $this->assertSame(['.*/admin/.*'], $plan['env']['contexts'][0]['excludePaths']);
        $this->assertSame('http://host.docker.internal:8123/users/login', $plan['env']['contexts'][0]['authentication']['parameters']['loginPageUrl']);
        $this->assertSame(['spider', 'spiderAjax', 'passiveScan-wait', 'activeScan', 'passiveScan-wait', 'report', 'report'], array_column($plan['jobs'], 'type'));
        $this->assertSame(2, $plan['jobs'][0]['parameters']['maxDuration']);
        $this->assertSame(15, $plan['jobs'][3]['parameters']['maxScanDurationInMins']);
        $this->assertSame(['-config', 'spider.maxDepth=3'], array_slice($zap->zapOptions(), -2));

        // Without a login page, no login, and the logout is left out
        $plan = (new Config())->zap()->plan('http://127.0.0.1:8123');
        $this->assertArrayNotHasKey('authentication', $plan['env']['contexts'][0]);
        $this->assertSame(['.*logout.*'], $plan['env']['contexts'][0]['excludePaths']);
    }

    public function testWebrootGiven(): void
    {
        $this->assertSame('p' . DS . 'html', (new Config(project: 'p', webroot: 'html'))->webroot());
        $this->assertSame('/var/www/html', (new Config(project: 'p', webroot: '/var/www/html'))->webroot());
    }
}
