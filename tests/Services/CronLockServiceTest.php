<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\CronLockService;
use OpenNfse\Services\InvoiceLockService;
use PHPUnit\Framework\TestCase;

final class CronLockServiceTest extends TestCase
{
    public function testCronLockNameIsPrefixedWithDatabase(): void
    {
        $this->assertSame(
            'whmcs_prod.mod_opennfse_cron',
            CronLockService::buildScopedName('whmcs_prod', CronLockService::CRON_LOCK_SUFFIX)
        );
    }

    public function testInvoiceLockNameIsPrefixedWithDatabase(): void
    {
        $this->assertSame(
            'whmcs_prod.opennfse_inv_123',
            CronLockService::buildScopedName('whmcs_prod', InvoiceLockService::suffixFor(123))
        );
    }

    public function testDifferentDatabasesProduceDifferentLockNames(): void
    {
        $this->assertNotSame(
            CronLockService::buildScopedName('cliente_a', CronLockService::CRON_LOCK_SUFFIX),
            CronLockService::buildScopedName('cliente_b', CronLockService::CRON_LOCK_SUFFIX)
        );
    }

    public function testEmptyDatabaseFallsBackToSuffix(): void
    {
        $this->assertSame('mod_opennfse_cron', CronLockService::buildScopedName('', 'mod_opennfse_cron'));
    }

    public function testLongDatabaseNameIsHashedToFitMysqlLimit(): void
    {
        $database = str_repeat('banco_muito_longo_', 4);
        $name = CronLockService::buildScopedName($database, InvoiceLockService::suffixFor(987654321));

        $this->assertLessThanOrEqual(CronLockService::MAX_LOCK_NAME_LENGTH, strlen($name));
        $this->assertSame(substr(sha1($database), 0, 16) . '.opennfse_inv_987654321', $name);
        $this->assertNotSame(
            $name,
            CronLockService::buildScopedName($database . 'x', InvoiceLockService::suffixFor(987654321))
        );
    }
}
