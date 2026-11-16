<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Repositories;

use OpenNfse\Repositories\NotaRepository;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class NotaFailureResolvedTest extends TestCase
{
    protected function setUp(): void
    {
        Capsule::reset();
        Capsule::$updateResult = 1;
    }

    protected function tearDown(): void
    {
        Capsule::reset();
    }

    public function testNewAttemptClearsResolvedFailure(): void
    {
        Capsule::$rows['mod_opennfse_notas'] = [
            (object) [
                'id' => 4,
                'invoiceid' => 7,
                'status' => 'REJEITADA',
                'falha_resolvida_em' => '2026-11-16 13:00:00',
                'falha_resolvida_por' => 9,
                'erro_api' => 'antiga',
            ],
        ];

        (new NotaRepository())->upsert([
            'invoiceid' => 7,
            'status' => 'PROCESSANDO',
            'id_dps' => 'DPS2',
        ]);

        $update = Capsule::$updates['mod_opennfse_notas'][0];
        $this->assertNull($update['falha_resolvida_em']);
        $this->assertNull($update['falha_resolvida_por']);
    }

    public function testIssuedNoteClearsResolvedFailure(): void
    {
        Capsule::$rows['mod_opennfse_notas'] = [
            (object) [
                'id' => 4,
                'invoiceid' => 7,
                'status' => 'ERRO',
                'falha_resolvida_em' => '2026-11-16 13:00:00',
                'falha_resolvida_por' => 9,
            ],
        ];

        (new NotaRepository())->upsert([
            'invoiceid' => 7,
            'status' => 'EMITIDA',
        ]);

        $update = Capsule::$updates['mod_opennfse_notas'][0];
        $this->assertNull($update['falha_resolvida_em']);
        $this->assertNull($update['falha_resolvida_por']);
    }

    public function testRewritingTheSameFailureKeepsTheMark(): void
    {
        Capsule::$rows['mod_opennfse_notas'] = [
            (object) [
                'id' => 4,
                'invoiceid' => 7,
                'status' => 'REJEITADA',
                'falha_resolvida_em' => '2026-11-16 13:00:00',
                'falha_resolvida_por' => 9,
                'erro_api' => 'antiga',
            ],
        ];

        (new NotaRepository())->upsert([
            'invoiceid' => 7,
            'status' => 'REJEITADA',
            'erro_api' => 'antiga',
        ]);

        $update = Capsule::$updates['mod_opennfse_notas'][0];
        $this->assertArrayNotHasKey('falha_resolvida_em', $update);
        $this->assertArrayNotHasKey('falha_resolvida_por', $update);
    }
}
