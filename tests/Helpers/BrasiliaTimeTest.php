<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Helpers;

use OpenNfse\Helpers\BrasiliaTime;
use PHPUnit\Framework\TestCase;

final class BrasiliaTimeTest extends TestCase
{
    private const UTC = 0;
    private const SAO_PAULO = -10800;
    private const TOKYO = 32400;

    public function testServerOffsetFromTimezone(): void
    {
        $this->assertSame(0, BrasiliaTime::serverOffsetSeconds('UTC'));
        $this->assertSame(-10800, BrasiliaTime::serverOffsetSeconds('America/Sao_Paulo'));
        $this->assertSame(32400, BrasiliaTime::serverOffsetSeconds('Asia/Tokyo'));
        $this->assertSame(0, BrasiliaTime::serverOffsetSeconds('Fuso/Invalido'));
    }

    public function testShiftToServer(): void
    {
        $this->assertSame(10800, BrasiliaTime::shiftToServerSeconds(self::UTC));
        $this->assertSame(0, BrasiliaTime::shiftToServerSeconds(self::SAO_PAULO));
        $this->assertSame(43200, BrasiliaTime::shiftToServerSeconds(self::TOKYO));
    }

    public function testToServerTime(): void
    {
        $this->assertSame('2026-10-01 13:00:00', BrasiliaTime::toServerTime('2026-10-01 10:00:00', self::UTC));
        $this->assertSame('2026-10-01 10:00:00', BrasiliaTime::toServerTime('2026-10-01 10:00:00', self::SAO_PAULO));
        $this->assertSame('2026-10-01 22:00:00', BrasiliaTime::toServerTime('2026-10-01 10:00:00', self::TOKYO));
        $this->assertSame('2026-10-02 01:30:00', BrasiliaTime::toServerTime('2026-10-01 22:30:00', self::UTC));
        $this->assertNull(BrasiliaTime::toServerTime('', self::UTC));
        $this->assertNull(BrasiliaTime::toServerTime('0000-00-00 00:00:00', self::UTC));
        $this->assertNull(BrasiliaTime::toServerTime('data-ruim', self::UTC));
    }

    public function testServerInUtcDoesNotFlagPaymentMadeBeforeEmission(): void
    {
        // Pago às 12:00 UTC (09:00 em Brasília), nota emitida às 10:00 em Brasília: emissão depois do pagamento.
        $this->assertFalse(BrasiliaTime::isPaidAfterEmission('2026-10-01 10:00:00', '2026-10-01 12:00:00', self::UTC));
        // Pago às 14:00 UTC (11:00 em Brasília): pagamento depois da emissão.
        $this->assertTrue(BrasiliaTime::isPaidAfterEmission('2026-10-01 10:00:00', '2026-10-01 14:00:00', self::UTC));
    }

    public function testServerInSaoPauloComparesDirectly(): void
    {
        $this->assertFalse(BrasiliaTime::isPaidAfterEmission('2026-10-01 10:00:00', '2026-10-01 09:59:59', self::SAO_PAULO));
        $this->assertFalse(BrasiliaTime::isPaidAfterEmission('2026-10-01 10:00:00', '2026-10-01 10:00:00', self::SAO_PAULO));
        $this->assertTrue(BrasiliaTime::isPaidAfterEmission('2026-10-01 10:00:00', '2026-10-01 10:00:01', self::SAO_PAULO));
    }

    public function testServerAheadOfBrasilia(): void
    {
        // Tóquio está 12 h à frente de Brasília: 10:00 em Brasília = 22:00 em Tóquio.
        $this->assertFalse(BrasiliaTime::isPaidAfterEmission('2026-10-01 10:00:00', '2026-10-01 21:00:00', self::TOKYO));
        $this->assertTrue(BrasiliaTime::isPaidAfterEmission('2026-10-01 10:00:00', '2026-10-01 23:00:00', self::TOKYO));
    }

    public function testMissingDatesAreNotPaidAfterEmission(): void
    {
        $this->assertFalse(BrasiliaTime::isPaidAfterEmission('', '2026-10-01 10:00:00', self::UTC));
        $this->assertFalse(BrasiliaTime::isPaidAfterEmission('2026-10-01 10:00:00', '0000-00-00 00:00:00', self::UTC));
    }
}
