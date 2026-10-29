<?php

declare(strict_types=1);

namespace OpenNfse\Tests;

use OpenNfse\Migrations\Migrator;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class MigratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Capsule::reset();
        Migrator::resetForTests();
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        Migrator::resetForTests();
        parent::tearDown();
    }

    public function testRunsAtMostOncePerProcess(): void
    {
        $migrator = new Migrator();
        $migrator->up();
        $firstRun = count(Capsule::$statements);
        $this->assertGreaterThan(0, $firstRun);

        $migrator->up();
        (new Migrator())->up();
        $this->assertCount($firstRun, Capsule::$statements, 'Chamadas seguintes no mesmo processo não executam nada.');
    }

    public function testEachStatementOfNewMigrationsIsSeparate(): void
    {
        (new Migrator())->up();
        $policy = array_values(array_filter(
            Capsule::$statements,
            static fn (string $sql): bool => str_contains($sql, 'auto_emit_')
                && !str_contains($sql, 'ADD COLUMN auto_emit_on_payment')
        ));
        $this->assertCount(5, $policy, 'Migration 040: 4 ALTER e 1 UPDATE, cada um isolado.');
        $this->assertStringStartsWith('UPDATE mod_opennfse_config SET auto_emit_trigger', $policy[4]);
    }
}
