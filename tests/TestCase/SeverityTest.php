<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast\Test;

use PHPUnit\Framework\TestCase;
use PieceOfCake2\SecurityDast\Severity;

class SeverityTest extends TestCase
{
    public function testFromRiskCode(): void
    {
        $this->assertSame('high', Severity::fromRiskCode('3'));
        $this->assertSame('medium', Severity::fromRiskCode(2));
        $this->assertSame('low', Severity::fromRiskCode('1'));
        $this->assertSame('info', Severity::fromRiskCode('0'));
        $this->assertSame('info', Severity::fromRiskCode(null));
    }

    public function testReaches(): void
    {
        $this->assertTrue(Severity::reaches('high', 'medium'));
        $this->assertTrue(Severity::reaches('medium', 'medium'));
        $this->assertFalse(Severity::reaches('low', 'medium'));
        $this->assertTrue(Severity::reaches('info', 'info'));
        $this->assertFalse(Severity::reaches('high', 'none'));
    }
}
