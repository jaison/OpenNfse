<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\AlertService;
use OpenNfse\Services\InvoiceAlertPolicy;
use PHPUnit\Framework\TestCase;

final class InvoiceAlertPolicyTest extends TestCase
{
    private const EMITIDA = ['id' => 1, 'status' => 'EMITIDA', 'numero_nf' => '15', 'chave_acesso' => 'CHAVE', 'emitida_em' => '2026-10-01 10:00:00'];

    public static function notasSemAlerta(): array
    {
        return [
            'sem nota' => [null],
            'cancelada' => [['status' => 'CANCELADA'] + self::EMITIDA],
            'processando' => [['status' => 'PROCESSANDO'] + self::EMITIDA],
            'erro' => [['status' => 'ERRO'] + self::EMITIDA],
            'rejeitada' => [['status' => 'REJEITADA'] + self::EMITIDA],
        ];
    }

    /**
     * @dataProvider notasSemAlerta
     */
    public function testNoAlertWithoutEmittedNota(?array $nota): void
    {
        $paidCredit = ['status' => 'Paid', 'total' => '0.00', 'credit' => '100.00', 'datepaid' => '2026-10-05 10:00:00'];

        $this->assertNull(InvoiceAlertPolicy::onCancelled($nota));
        $this->assertNull(InvoiceAlertPolicy::onRefunded($nota));
        $this->assertNull(InvoiceAlertPolicy::onUnpaid($nota, 100.0, 0.0));
        $this->assertNull(InvoiceAlertPolicy::onTotalChanged($nota, 100.0, ['total' => '50.00']));
        $this->assertNull(InvoiceAlertPolicy::onPaid($nota, $paidCredit));
    }

    public function testCancelledWithEmittedNota(): void
    {
        $decision = InvoiceAlertPolicy::onCancelled(self::EMITIDA);
        $this->assertSame(AlertService::FATURA_CANCELADA_COM_NFSE, $decision['tipo']);
        $this->assertStringContainsString('NFS-e nº 15', $decision['detalhes']);
    }

    public function testStatusIsCaseInsensitive(): void
    {
        $this->assertNotNull(InvoiceAlertPolicy::onCancelled(['status' => 'emitida']));
    }

    public function testRefundedWithEmittedNota(): void
    {
        $this->assertSame(AlertService::FATURA_ESTORNADA_COM_NFSE, InvoiceAlertPolicy::onRefunded(self::EMITIDA)['tipo']);
    }

    public function testUnpaidAlertsOnlyWhenInvoiceWasPaidBefore(): void
    {
        $this->assertNull(InvoiceAlertPolicy::onUnpaid(self::EMITIDA, 0.0, 0.0));
        $this->assertSame(AlertService::FATURA_REABERTA_COM_NFSE, InvoiceAlertPolicy::onUnpaid(self::EMITIDA, 100.0, 0.0)['tipo']);
        $this->assertSame(AlertService::FATURA_REABERTA_COM_NFSE, InvoiceAlertPolicy::onUnpaid(self::EMITIDA, 0.0, 30.0)['tipo']);
    }

    public function testTotalChangedAlertsWhenTotalDiffersFromNota(): void
    {
        $decision = InvoiceAlertPolicy::onTotalChanged(self::EMITIDA, 100.0, ['total' => '120.00', 'credit' => '0.00']);
        $this->assertSame(AlertService::FATURA_ALTERADA_COM_NFSE, $decision['tipo']);
        $this->assertStringContainsString('R$ 120,00', $decision['detalhes']);
        $this->assertStringContainsString('R$ 100,00', $decision['detalhes']);
    }

    public function testTotalChangedIgnoresEqualValuesAndRounding(): void
    {
        $this->assertNull(InvoiceAlertPolicy::onTotalChanged(self::EMITIDA, 100.0, ['total' => '100.00']));
        $this->assertNull(InvoiceAlertPolicy::onTotalChanged(self::EMITIDA, 100.0, ['total' => '100,004']));
        $this->assertNull(InvoiceAlertPolicy::onTotalChanged(self::EMITIDA, 99.999, ['total' => '100.00']));
    }

    public function testTotalChangedWithUnknownNotaValueDoesNotDecide(): void
    {
        $this->assertNull(InvoiceAlertPolicy::onTotalChanged(self::EMITIDA, null, ['total' => '1.00']));
    }

    public function testTotalChangedByPartialCreditMentionsCredit(): void
    {
        $decision = InvoiceAlertPolicy::onTotalChanged(self::EMITIDA, 100.0, ['total' => '70.00', 'credit' => '30.00']);
        $this->assertSame(AlertService::FATURA_ALTERADA_COM_NFSE, $decision['tipo']);
        $this->assertStringContainsString('Crédito aplicado: R$ 30,00', $decision['detalhes']);
    }

    public function testTotalChangedLeavesCreditOnlyToPaidAlert(): void
    {
        $this->assertNull(InvoiceAlertPolicy::onTotalChanged(self::EMITIDA, 100.0, ['total' => '0.00', 'credit' => '100.00']));
    }

    public function testCreditAfterEmissionDetected(): void
    {
        $invoice = ['status' => 'Paid', 'total' => '0.00', 'credit' => '100.00', 'paymentmethod' => 'pix', 'datepaid' => '2026-10-05 10:00:00'];

        $decision = InvoiceAlertPolicy::onPaid(self::EMITIDA, $invoice);
        $this->assertSame(AlertService::CREDITO_APOS_EMISSAO, $decision['tipo']);
        $this->assertStringContainsString('R$ 100,00', $decision['detalhes']);
    }

    public function testCreditAfterEmissionWithCreditPaymentMethod(): void
    {
        $invoice = ['status' => 'Paid', 'total' => '100.00', 'credit' => '0.00', 'paymentmethod' => 'credit', 'datepaid' => '2026-10-05 10:00:00'];

        $this->assertNotNull(InvoiceAlertPolicy::onPaid(self::EMITIDA, $invoice));
    }

    public function testNoCreditAlertForGatewayPayment(): void
    {
        $invoice = ['status' => 'Paid', 'total' => '100.00', 'credit' => '0.00', 'paymentmethod' => 'pix', 'datepaid' => '2026-10-05 10:00:00'];

        $this->assertNull(InvoiceAlertPolicy::onPaid(self::EMITIDA, $invoice));
    }

    public function testNoCreditAlertForPartialCredit(): void
    {
        $invoice = ['status' => 'Paid', 'total' => '70.00', 'credit' => '30.00', 'paymentmethod' => 'pix', 'datepaid' => '2026-10-05 10:00:00'];

        $this->assertNull(InvoiceAlertPolicy::onPaid(self::EMITIDA, $invoice));
    }

    public function testNoCreditAlertWhenNotaWasEmittedAfterPayment(): void
    {
        $invoice = ['status' => 'Paid', 'total' => '0.00', 'credit' => '100.00', 'datepaid' => '2026-09-30 10:00:00'];

        $this->assertNull(InvoiceAlertPolicy::onPaid(self::EMITIDA, $invoice));
    }

    public function testNoCreditAlertWhenInvoiceIsNotPaid(): void
    {
        $invoice = ['status' => 'Unpaid', 'total' => '0.00', 'credit' => '100.00', 'datepaid' => '2026-10-05 10:00:00'];

        $this->assertNull(InvoiceAlertPolicy::onPaid(self::EMITIDA, $invoice));
    }

    public function testEmittedBeforePaymentEdgeCases(): void
    {
        $now = (int) strtotime('2026-10-28 12:00:00');

        $this->assertTrue(InvoiceAlertPolicy::emittedBeforePayment('2026-10-01 10:00:00', '2026-10-01 10:00:01', $now));
        $this->assertFalse(InvoiceAlertPolicy::emittedBeforePayment('2026-10-01 10:00:00', '2026-10-01 10:00:00', $now));
        $this->assertFalse(InvoiceAlertPolicy::emittedBeforePayment('2026-10-02 10:00:00', '2026-10-01 10:00:00', $now));
        // Sem data de pagamento válida, compara com o momento atual.
        $this->assertTrue(InvoiceAlertPolicy::emittedBeforePayment('2026-10-01 10:00:00', '0000-00-00 00:00:00', $now));
        $this->assertFalse(InvoiceAlertPolicy::emittedBeforePayment('2026-10-29 10:00:00', '', $now));
        // Nota já EMITIDA no pagamento, sem data de emissão gravada, conta como anterior.
        $this->assertTrue(InvoiceAlertPolicy::emittedBeforePayment('', '2026-10-01 10:00:00', $now));
    }
}
