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

    /**
     * @dataProvider multipleLocksVersionProvider
     */
    public function testDetectsServersThatAcceptMultipleLocksPerSession(string $version, bool $expected): void
    {
        $this->assertSame($expected, CronLockService::supportsMultipleLocks($version), $version);
    }

    public static function multipleLocksVersionProvider(): array
    {
        return [
            'mysql 5.5' => ['5.5.62', false],
            'mysql 5.6' => ['5.6.51-log', false],
            'mysql 5.7.4' => ['5.7.4-m14', false],
            'mysql 5.7.5' => ['5.7.5', true],
            'mysql 5.7 percona' => ['5.7.44-48-log', true],
            'mysql 8.0' => ['8.0.36-0ubuntu0.22.04.1', true],
            'mysql 8.4 sem patch' => ['8.4', true],
            'mariadb 5.5' => ['5.5.68-MariaDB', false],
            'mariadb 10.0.1' => ['10.0.1-MariaDB', false],
            'mariadb 10.0.2' => ['10.0.2-MariaDB', true],
            'mariadb 10.6' => ['10.6.18-MariaDB-0ubuntu0.22.04.1', true],
            'mariadb 11.4' => ['11.4.2-MariaDB-log', true],
            'mariadb com prefixo 5.5.5' => ['5.5.5-10.3.39-MariaDB', true],
            'mariadb antigo com prefixo' => ['5.5.5-10.0.1-MariaDB', false],
            'vazio' => ['', false],
            'desconhecido' => ['versao-estranha', false],
        ];
    }
}
