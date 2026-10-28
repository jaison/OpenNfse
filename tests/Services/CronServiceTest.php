<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\CronService;
use PHPUnit\Framework\TestCase;

final class CronServiceTest extends TestCase
{
    public function testLogsLockBusyWhenNeverLoggedBefore(): void
    {
        $this->assertTrue(CronService::shouldLogLockBusy(null, time()));
        $this->assertTrue(CronService::shouldLogLockBusy('', time()));
    }

    public function testDoesNotLogLockBusyAgainInsideInterval(): void
    {
        $now = strtotime('2026-10-28 10:10:00');
        $this->assertFalse(CronService::shouldLogLockBusy('2026-10-28 10:00:00', $now));
    }

    public function testLogsLockBusyAgainAfterInterval(): void
    {
        $now = strtotime('2026-10-28 10:15:00');
        $this->assertTrue(CronService::shouldLogLockBusy('2026-10-28 10:00:00', $now));
    }
}
