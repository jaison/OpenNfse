<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\AlertService;
use PHPUnit\Framework\TestCase;

final class AlertServiceTest extends TestCase
{
    private const NOTA = ['id' => 10, 'status' => 'EMITIDA', 'chave_acesso' => '42000000000000000000000000000000000000000000000001', 'numero_nf' => '15'];

    private InMemoryAlertRepository $repo;

    private int $schemaRuns = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new InMemoryAlertRepository();
        $this->schemaRuns = 0;
    }

    private function service(): AlertService
    {
        return new AlertService($this->repo, function (): void {
            $this->schemaRuns++;
            $this->repo->tableMissing = false;
        });
    }

    public function testRaiseCreatesAlertWithNotaData(): void
    {
        $id = $this->service()->raise(100, AlertService::FATURA_CANCELADA_COM_NFSE, self::NOTA, 'Fatura cancelada.');

        $this->assertSame(1, $id);
        $row = $this->repo->rows[1];
        $this->assertSame(100, $row['invoiceid']);
        $this->assertSame(10, $row['nota_id']);
        $this->assertSame(self::NOTA['chave_acesso'], $row['chave_acesso']);
        $this->assertSame('FATURA_CANCELADA_COM_NFSE', $row['tipo']);
        $this->assertNull($row['resolvido_em']);
    }

    public function testRaiseIsIdempotentWhileAlertIsOpen(): void
    {
        $service = $this->service();
        $this->assertNotNull($service->raise(100, AlertService::FATURA_CANCELADA_COM_NFSE, self::NOTA, 'Primeira.'));
        $this->assertNull($service->raise(100, AlertService::FATURA_CANCELADA_COM_NFSE, self::NOTA, 'Segunda.'));
        $this->assertCount(1, $this->repo->rows);
    }

    public function testRaiseCreatesNewAlertAfterResolution(): void
    {
        $service = $this->service();
        $first = $service->raise(100, AlertService::FATURA_ALTERADA_COM_NFSE, self::NOTA, 'Alterada.');
        $this->assertTrue($service->resolve((int) $first, 5));

        $second = $service->raise(100, AlertService::FATURA_ALTERADA_COM_NFSE, self::NOTA, 'Alterada de novo.');
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertCount(2, $this->repo->rows);
    }

    public function testDifferentTipoOrChaveOrInvoiceAreDifferentAlerts(): void
    {
        $service = $this->service();
        $outraNota = ['chave_acesso' => 'OUTRA'] + self::NOTA;
        $this->assertNotNull($service->raise(100, AlertService::FATURA_CANCELADA_COM_NFSE, self::NOTA, 'a'));
        $this->assertNotNull($service->raise(100, AlertService::FATURA_ESTORNADA_COM_NFSE, self::NOTA, 'b'));
        $this->assertNotNull($service->raise(100, AlertService::FATURA_CANCELADA_COM_NFSE, $outraNota, 'c'));
        $this->assertNotNull($service->raise(101, AlertService::FATURA_CANCELADA_COM_NFSE, self::NOTA, 'd'));
        $this->assertCount(4, $this->repo->rows);
    }

    public function testRaiseWithoutChaveIsStillIdempotent(): void
    {
        $service = $this->service();
        $semChave = ['chave_acesso' => ''] + self::NOTA;
        $this->assertNotNull($service->raise(100, AlertService::CREDITO_APOS_EMISSAO, $semChave, 'a'));
        $this->assertNull($service->raise(100, AlertService::CREDITO_APOS_EMISSAO, $semChave, 'b'));
        $this->assertNull($this->repo->rows[1]['chave_acesso']);
    }

    public function testConcurrentDuplicateInsertIsTreatedAsExistingAlert(): void
    {
        $service = $this->service();
        $this->assertNotNull($service->raise(100, AlertService::FATURA_CANCELADA_COM_NFSE, self::NOTA, 'a'));

        $this->repo->hideOpenOnRead = true;
        $this->assertNull($service->raise(100, AlertService::FATURA_CANCELADA_COM_NFSE, self::NOTA, 'b'));
        $this->assertCount(1, $this->repo->rows);
    }

    public function testRaiseRunsMigrationsOnceWhenTableIsMissing(): void
    {
        $this->repo->tableMissing = true;

        $id = $this->service()->raise(100, AlertService::FATURA_REABERTA_COM_NFSE, self::NOTA, 'Reaberta.');

        $this->assertNotNull($id);
        $this->assertSame(1, $this->schemaRuns);
    }

    public function testRaiseIgnoresInvalidInput(): void
    {
        $service = $this->service();
        $this->assertNull($service->raise(0, AlertService::FATURA_CANCELADA_COM_NFSE, self::NOTA, 'a'));
        $this->assertNull($service->raise(100, 'TIPO_INEXISTENTE', self::NOTA, 'a'));
        $this->assertCount(0, $this->repo->rows);
    }

    public function testResolveRecordsAdminAndCannotRunTwice(): void
    {
        $service = $this->service();
        $id = (int) $service->raise(100, AlertService::FATURA_CANCELADA_COM_NFSE, self::NOTA, 'a');

        $this->assertTrue($service->resolve($id, 7));
        $this->assertSame(7, $this->repo->rows[$id]['resolvido_por']);
        $this->assertNotNull($this->repo->rows[$id]['resolvido_em']);
        $this->assertFalse($service->resolve($id, 7));
        $this->assertFalse($service->resolve(999, 7));
    }

    public function testOpenKeyNormalizesTipoAndChave(): void
    {
        $this->assertSame(
            AlertService::openKey(1, 'credito_apos_emissao', ' ABC '),
            AlertService::openKey(1, 'CREDITO_APOS_EMISSAO', 'ABC')
        );
    }

    public function testFullRefundFiringBothHooksKeepsOneAlert(): void
    {
        $service = $this->service();
        $transaction = \OpenNfse\Services\InvoiceAlertPolicy::onTransaction(self::NOTA, ['invoiceid' => 100, 'amountout' => '100.00']);
        $refunded = \OpenNfse\Services\InvoiceAlertPolicy::onRefunded(self::NOTA);

        $this->assertNotNull($service->raise(100, $transaction['tipo'], self::NOTA, $transaction['detalhes']));
        $this->assertNull($service->raise(100, $refunded['tipo'], self::NOTA, $refunded['detalhes']));
        $this->assertCount(1, $this->repo->rows);
    }
}
