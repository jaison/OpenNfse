<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Repositories;

use OpenNfse\Repositories\ReportRepository;
use PHPUnit\Framework\TestCase;

/**
 * A deduplicação entre histórico e tabela atual fica no SQL (NOT EXISTS), fora do alcance do stub.
 * Aqui fica a soma das duas fontes no painel.
 */
final class ReportRepositoryDashboardTest extends TestCase
{
    public function testDailySeriesSumsBothSources(): void
    {
        $series = [
            '2026-10-01' => ['date' => '2026-10-01', 'canceladas' => 0, 'emitidas' => 4],
            '2026-10-02' => ['date' => '2026-10-02', 'canceladas' => 0, 'emitidas' => 0],
        ];

        $series = ReportRepository::addDailyCounts($series, [
            (object) ['dia' => '2026-10-01', 'total' => 2],
            (object) ['dia' => '2026-09-30', 'total' => 9],
        ], 'canceladas');
        $series = ReportRepository::addDailyCounts($series, [
            ['dia' => '2026-10-01', 'total' => 1],
            ['dia' => '2026-10-02', 'total' => 3],
        ], 'canceladas');

        $this->assertSame(3, $series['2026-10-01']['canceladas']);
        $this->assertSame(3, $series['2026-10-02']['canceladas']);
        $this->assertSame(4, $series['2026-10-01']['emitidas']);
        $this->assertCount(2, $series);
    }

    public function testHistoryCancellationsCountOncePerDocument(): void
    {
        $docs = ReportRepository::cancelamentosPorDocumento([
            (object) ['invoiceid' => 1, 'chave_acesso' => 'CHAVE-A', 'cancelado_em' => '2026-10-01 23:59:00'],
            (object) ['invoiceid' => 1, 'chave_acesso' => 'CHAVE-A', 'cancelado_em' => '2026-10-02 00:01:00'],
            ['invoiceid' => 2, 'chave_acesso' => 'CHAVE-B', 'cancelado_em' => '2026-10-01 10:00:00'],
            ['invoiceid' => 3, 'chave_acesso' => '', 'cancelado_em' => '2026-10-01 11:00:00'],
            ['invoiceid' => 3, 'chave_acesso' => null, 'cancelado_em' => '2026-10-01 11:00:00'],
            ['invoiceid' => 4, 'chave_acesso' => '', 'cancelado_em' => '2026-10-02 09:00:00'],
            ['invoiceid' => 5, 'chave_acesso' => 'CHAVE-C', 'cancelado_em' => null],
        ]);

        $this->assertCount(4, $docs);
        $this->assertSame('2026-10-02 00:01:00', $docs['chave:CHAVE-A']);

        $series = [
            '2026-10-01' => ['date' => '2026-10-01', 'canceladas' => 1],
            '2026-10-02' => ['date' => '2026-10-02', 'canceladas' => 0],
        ];
        $series = ReportRepository::addDailyCounts($series, ReportRepository::countByDay($docs), 'canceladas');

        $this->assertSame(3, $series['2026-10-01']['canceladas']);
        $this->assertSame(2, $series['2026-10-02']['canceladas']);
    }

    public function testRecentMergesBothSourcesNewestFirst(): void
    {
        $historico = [
            ['invoiceid' => 1, 'data' => '2026-10-05 10:00:00'],
            ['invoiceid' => 2, 'data' => '2026-10-01 10:00:00'],
        ];
        $notas = [
            ['invoiceid' => 3, 'data' => '2026-10-03 10:00:00'],
        ];

        $rows = ReportRepository::mergeRecentByDate($historico, $notas, 2);

        $this->assertSame([1, 3], array_column($rows, 'invoiceid'));
    }
}
