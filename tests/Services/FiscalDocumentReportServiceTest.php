<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\FiscalDocumentReportService;
use PHPUnit\Framework\TestCase;

final class FiscalDocumentReportServiceTest extends TestCase
{
    private const STATUSES = ['EMITIDA', 'CANCELADA'];

    private function emissao(int $id, int $invoiceId, string $chave, string $xml, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'invoiceid' => $invoiceId,
            'userid' => 3,
            'tipo_registro' => 'EMISSAO',
            'numero_nf' => (string) $id,
            'chave_acesso' => $chave,
            'xml_path' => $xml,
            'cancel_xml_path' => null,
            'emitida_em' => '2026-10-10 10:00:00',
            'cancelado_em' => null,
            'country' => 'BR',
        ], $extra);
    }

    private function cancelamento(int $id, int $invoiceId, string $chave, string $xml, string $cancelXml): array
    {
        return array_merge($this->emissao($id, $invoiceId, $chave, $xml), [
            'tipo_registro' => 'CANCELAMENTO',
            'cancel_xml_path' => $cancelXml,
            'cancelado_em' => '2026-10-10 11:00:00',
        ]);
    }

    private function nota(int $invoiceId, string $status, string $chave, string $xml, ?string $cancelXml = null, string $country = 'BR'): array
    {
        return [
            'invoiceid' => $invoiceId,
            'status' => $status,
            'chave_acesso' => $chave,
            'xml_path' => $xml,
            'cancel_xml_path' => $cancelXml,
            'cancelado_em' => $cancelXml !== null ? '2026-10-10 11:00:00' : null,
            'country' => $country,
        ];
    }

    /**
     * @return array<string, array<int, string>> diretório => arquivos
     */
    private function layout(array $history, array $notas, array $statuses = self::STATUSES): array
    {
        $out = [];
        foreach ((new FiscalDocumentReportService())->buildCompetenciaZipDocuments($history, $notas, $statuses) as $doc) {
            foreach ($doc['paths'] as $path) {
                $out[$doc['directory']][] = $path;
            }
        }

        return $out;
    }

    public function testSimpleEmittedNotaGoesToEmitidas(): void
    {
        $history = [$this->emissao(1, 100, 'K1', 'x/nfse_1.xml')];
        $notas = [$this->nota(100, 'EMITIDA', 'K1', 'x/nfse_1.xml')];

        $this->assertSame(['Emitidas' => ['x/nfse_1.xml']], $this->layout($history, $notas));
    }

    public function testCancelledNotaWithoutReemissionGoesToCanceladasWithBothXmls(): void
    {
        $history = [
            $this->cancelamento(2, 100, 'K1', 'x/nfse_1.xml', 'x/cancelamento_1.xml'),
            $this->emissao(1, 100, 'K1', 'x/nfse_1.xml'),
        ];
        $notas = [$this->nota(100, 'CANCELADA', 'K1', 'x/nfse_1.xml', 'x/cancelamento_1.xml')];

        $this->assertSame(
            ['Canceladas' => ['x/nfse_1.xml', 'x/cancelamento_1.xml']],
            $this->layout($history, $notas)
        );
    }

    public function testCancelledNotaReplacedByReemissionStaysInCanceladas(): void
    {
        // Registro EMISSAO da nota nova contaminado com o cancelamento antigo (antes da correção).
        $history = [
            $this->emissao(3, 151900, 'K2', 'x/nfse_2.xml', [
                'cancel_xml_path' => 'x/cancelamento_1.xml',
                'cancelado_em' => '2026-10-10 11:00:00',
            ]),
            $this->cancelamento(2, 151900, 'K1', 'x/nfse_1.xml', 'x/cancelamento_1.xml'),
            $this->emissao(1, 151900, 'K1', 'x/nfse_1.xml'),
        ];
        $notas = [$this->nota(151900, 'EMITIDA', 'K2', 'x/nfse_2.xml', 'x/cancelamento_1.xml')];

        $this->assertSame(
            [
                'Emitidas' => ['x/nfse_2.xml'],
                'Canceladas' => ['x/nfse_1.xml', 'x/cancelamento_1.xml'],
            ],
            $this->layout($history, $notas)
        );
    }

    public function testForeignClientGoesToEmitidasParaEstrangeiros(): void
    {
        $history = [$this->emissao(1, 100, 'K1', 'x/nfse_1.xml', ['country' => 'US'])];
        $notas = [$this->nota(100, 'EMITIDA', 'K1', 'x/nfse_1.xml', null, 'US')];

        $this->assertSame(['Emitidas para Estrangeiros' => ['x/nfse_1.xml']], $this->layout($history, $notas));
    }

    public function testForeignCancelledNotaGoesToCanceladas(): void
    {
        $history = [array_merge($this->cancelamento(2, 100, 'K1', 'x/nfse_1.xml', 'x/cancelamento_1.xml'), ['country' => 'US'])];

        $this->assertSame(
            ['Canceladas' => ['x/nfse_1.xml', 'x/cancelamento_1.xml']],
            $this->layout($history, [])
        );
    }

    public function testDuplicateEmissionRecordsOfSameKeyBecomeOneDocument(): void
    {
        $history = [
            $this->emissao(5, 100, 'K1', 'x/nfse_1.xml'),
            $this->emissao(4, 100, 'K1', 'x/nfse_1.xml'),
            $this->emissao(1, 100, 'K1', ''),
        ];

        $docs = (new FiscalDocumentReportService())->buildCompetenciaZipDocuments($history, [], self::STATUSES);
        $this->assertCount(1, $docs);
        $this->assertSame(['x/nfse_1.xml'], $docs[0]['paths']);
    }

    public function testRowsMissingFromHistoryAreStillExported(): void
    {
        $notas = [
            $this->nota(200, 'CANCELADA', 'K9', 'x/nfse_9.xml', 'x/cancelamento_9.xml'),
            $this->nota(201, 'EMITIDA', '', 'x/nfse_sem_chave.xml'),
        ];

        $this->assertSame(
            [
                'Canceladas' => ['x/nfse_9.xml', 'x/cancelamento_9.xml'],
                'Emitidas' => ['x/nfse_sem_chave.xml'],
            ],
            $this->layout([], $notas)
        );
    }

    public function testCancelledRowOfKeyWithOnlyEmissionInHistoryStaysCancelled(): void
    {
        $history = [$this->emissao(1, 100, 'K1', 'x/nfse_1.xml')];
        $notas = [$this->nota(100, 'CANCELADA', 'K1', 'x/nfse_1.xml', 'x/cancelamento_1.xml')];

        $this->assertSame(
            ['Canceladas' => ['x/nfse_1.xml', 'x/cancelamento_1.xml']],
            $this->layout($history, $notas)
        );
    }

    public function testEmittedRowIgnoresCancelXmlThatIsNotItsOwn(): void
    {
        $notas = [$this->nota(100, 'EMITIDA', 'K2', 'x/nfse_2.xml', 'x/cancelamento_1.xml')];

        $this->assertSame(['Emitidas' => ['x/nfse_2.xml']], $this->layout([], $notas));
    }

    public function testStatusFilterApplies(): void
    {
        $history = [
            $this->cancelamento(2, 100, 'K1', 'x/nfse_1.xml', 'x/cancelamento_1.xml'),
            $this->emissao(3, 101, 'K2', 'x/nfse_2.xml'),
        ];

        $this->assertSame(['Emitidas' => ['x/nfse_2.xml']], $this->layout($history, [], ['EMITIDA']));
        $this->assertSame(
            ['Canceladas' => ['x/nfse_1.xml', 'x/cancelamento_1.xml']],
            $this->layout($history, [], ['CANCELADA'])
        );
    }

    public function testCancellationRecordWithoutDateIsNotACancellation(): void
    {
        // Backfill de linha EMITIDA que só tinha falha de cancelamento: CANCELAMENTO sem cancelado_em.
        $semData = $this->cancelamento(2, 100, 'K1', 'x/nfse_1.xml', 'x/cancelamento_falho.xml');
        $semData['cancelado_em'] = null;
        $history = [$semData, $this->emissao(1, 100, 'K1', 'x/nfse_1.xml')];
        $notas = [$this->nota(100, 'EMITIDA', 'K1', 'x/nfse_1.xml')];

        $this->assertSame(['Emitidas' => ['x/nfse_1.xml']], $this->layout($history, $notas));
    }

    public function testRowWhoseKeyIsInHistoryOutsideTheWindowIsNotDuplicated(): void
    {
        $notas = [
            $this->nota(100, 'EMITIDA', 'K1', 'x/nfse_1.xml'),
            $this->nota(101, 'EMITIDA', 'K2', 'x/nfse_2.xml'),
        ];

        $docs = (new FiscalDocumentReportService())->buildCompetenciaZipDocuments([], $notas, self::STATUSES, ['K1']);

        $this->assertCount(1, $docs);
        $this->assertSame(['x/nfse_2.xml'], $docs[0]['paths']);
    }

    public function testMergeCancelamentosKeepsReplacedNotaAndSkipsDuplicates(): void
    {
        $history = [
            ['invoiceid' => 151900, 'chave_acesso' => 'K1', 'cancelado_em' => '2026-10-10 11:00:00', 'status' => 'CANCELADA'],
            ['invoiceid' => 300, 'chave_acesso' => 'K3', 'cancelado_em' => '2026-10-12 09:00:00', 'status' => 'CANCELADA'],
        ];
        $notas = [
            // Mesma nota cancelada vista pela tabela atual.
            ['invoiceid' => 300, 'chave_acesso' => 'K3', 'cancelado_em' => '2026-10-12 09:00:00', 'status' => 'CANCELADA'],
            // Linha ainda contaminada: mesma fatura e horário do cancelamento da chave antiga.
            ['invoiceid' => 151900, 'chave_acesso' => 'K2', 'cancelado_em' => '2026-10-10 11:00:00', 'status' => 'EMITIDA'],
            // Cancelada que não está no histórico.
            ['invoiceid' => 400, 'chave_acesso' => 'K4', 'cancelado_em' => '2026-10-11 08:00:00', 'status' => 'CANCELADA'],
        ];

        $rows = (new FiscalDocumentReportService())->mergeCancelamentos($history, $notas, 200);

        $this->assertSame(['K3', 'K4', 'K1'], array_column($rows, 'chave_acesso'));
    }

    public function testMergeCancelamentosRespectsLimit(): void
    {
        $history = [
            ['invoiceid' => 1, 'chave_acesso' => 'A', 'cancelado_em' => '2026-10-01 10:00:00'],
            ['invoiceid' => 2, 'chave_acesso' => 'B', 'cancelado_em' => '2026-10-02 10:00:00'],
        ];

        $rows = (new FiscalDocumentReportService())->mergeCancelamentos($history, [], 1);

        $this->assertSame(['B'], array_column($rows, 'chave_acesso'));
    }
}
