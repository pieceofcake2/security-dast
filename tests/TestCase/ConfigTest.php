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

    public function testWebrootGiven(): void
    {
        $this->assertSame('p' . DS . 'html', (new Config(project: 'p', webroot: 'html'))->webroot());
        $this->assertSame('/var/www/html', (new Config(project: 'p', webroot: '/var/www/html'))->webroot());
    }
}
