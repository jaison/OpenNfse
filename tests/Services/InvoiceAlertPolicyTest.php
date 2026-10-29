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

    public function testCreditAlertDoesNotDependOnDates(): void
    {
        // Nota EMITIDA no momento do InvoicePaid já é anterior ao pagamento, mesmo com datas em fusos diferentes.
        $invoice = ['status' => 'Paid', 'total' => '0.00', 'credit' => '100.00', 'datepaid' => '2026-09-30 10:00:00'];

        $this->assertNotNull(InvoiceAlertPolicy::onPaid(self::EMITIDA, $invoice));
        $this->assertNotNull(InvoiceAlertPolicy::onPaid(['emitida_em' => null] + self::EMITIDA, $invoice));
    }

    public function testNoCreditAlertWhenInvoiceIsNotPaid(): void
    {
        $invoice = ['status' => 'Unpaid', 'total' => '0.00', 'credit' => '100.00', 'datepaid' => '2026-10-05 10:00:00'];

        $this->assertNull(InvoiceAlertPolicy::onPaid(self::EMITIDA, $invoice));
    }

    public function testTransactionInvoiceIdAcceptsDocumentedTypo(): void
    {
        $this->assertSame(10, InvoiceAlertPolicy::transactionInvoiceId(['invoiceid' => '10']));
        $this->assertSame(11, InvoiceAlertPolicy::transactionInvoiceId(['invocieid' => 11]));
        $this->assertSame(12, InvoiceAlertPolicy::transactionInvoiceId(['invoiceid' => 0, 'invocieid' => '12']));
        $this->assertSame(0, InvoiceAlertPolicy::transactionInvoiceId([]));
    }

    public function testOutgoingTransactionDetection(): void
    {
        $this->assertTrue(InvoiceAlertPolicy::isOutgoingTransaction(['amountin' => '0', 'amountout' => '30.00']));
        $this->assertTrue(InvoiceAlertPolicy::isOutgoingTransaction(['amountout' => '0', 'refundid' => '55']));
        $this->assertTrue(InvoiceAlertPolicy::isOutgoingTransaction(['amountout' => '10,50']));
        $this->assertFalse(InvoiceAlertPolicy::isOutgoingTransaction(['amountin' => '100.00', 'amountout' => '0.00', 'refundid' => '0']));
        $this->assertFalse(InvoiceAlertPolicy::isOutgoingTransaction([]));
    }

    public function testPartialRefundOrReversalAlertsAsRefund(): void
    {
        $vars = ['invoiceid' => 10, 'amountout' => '30.00', 'refundid' => 99, 'gateway' => 'stripe', 'transid' => 'ch_1'];

        $decision = InvoiceAlertPolicy::onTransaction(self::EMITIDA, $vars);
        $this->assertSame(AlertService::FATURA_ESTORNADA_COM_NFSE, $decision['tipo']);
        $this->assertStringContainsString('R$ 30,00', $decision['detalhes']);
        $this->assertStringContainsString('stripe ch_1', $decision['detalhes']);
    }

    public function testTransactionUsesSameTypeAsInvoiceRefundedForDeduplication(): void
    {
        $vars = ['invoiceid' => 10, 'amountout' => '100.00'];

        $this->assertSame(
            InvoiceAlertPolicy::onRefunded(self::EMITIDA)['tipo'],
            InvoiceAlertPolicy::onTransaction(self::EMITIDA, $vars)['tipo']
        );
    }

    public function testTransactionWithoutAlert(): void
    {
        $this->assertNull(InvoiceAlertPolicy::onTransaction(self::EMITIDA, ['amountin' => '100.00', 'amountout' => '0']));
        $this->assertNull(InvoiceAlertPolicy::onTransaction(null, ['amountout' => '30.00']));
        $this->assertNull(InvoiceAlertPolicy::onTransaction(['status' => 'CANCELADA'] + self::EMITIDA, ['amountout' => '30.00']));
    }

    public function testLateFeeRequestsCheckForTheInvoice(): void
    {
        $this->assertSame([10], InvoiceAlertPolicy::deferredCheckInvoiceIds('AddInvoiceLateFee', ['invoiceid' => '10']));
        $this->assertSame([], InvoiceAlertPolicy::deferredCheckInvoiceIds('AddInvoiceLateFee', ['invoiceid' => 0]));
    }

    public function testSplitRequestsCheckForBothInvoices(): void
    {
        $vars = ['originalinvoiceid' => '10', 'newinvoiceid' => 11];

        $this->assertSame([10, 11], InvoiceAlertPolicy::deferredCheckInvoiceIds('InvoiceSplit', $vars));
        $this->assertSame([10], InvoiceAlertPolicy::deferredCheckInvoiceIds('InvoiceSplit', ['originalinvoiceid' => 10]));
        $this->assertSame([10], InvoiceAlertPolicy::deferredCheckInvoiceIds('InvoiceSplit', ['originalinvoiceid' => 10, 'newinvoiceid' => 10]));
    }

    public function testOtherHooksDoNotRequestCheck(): void
    {
        $this->assertSame([], InvoiceAlertPolicy::deferredCheckInvoiceIds('InvoicePaid', ['invoiceid' => 10]));
    }
}
