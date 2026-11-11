<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Repositories\LedgerCreditSource;
use OpenNfse\Services\InvoiceFinancialsService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class InvoiceFinancialsServiceTest extends TestCase
{
    private InvoiceFinancialsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoiceFinancialsService();
    }

    public function testUsesInvoiceTotalAsGatewayPaidAmountWhenCreditWasAlreadyApplied(): void
    {
        $invoice = [
            'subtotal' => '119.90',
            'total' => '95.90',
            'credit' => '24.00',
        ];

        $this->assertSame(95.90, $this->service->getGatewayPaidAmount($invoice));
    }

    public function testClampsGatewayPaidAmountToZeroWhenInvoiceTotalIsNegative(): void
    {
        $invoice = [
            'total' => '-5.00',
            'credit' => '15.00',
        ];

        $this->assertSame(0.0, $this->service->getGatewayPaidAmount($invoice));
    }

    public function testTreatsInvoiceAsCreditOnlyWhenPaymentMethodIsCredit(): void
    {
        $invoice = [
            'paymentmethod' => 'credit',
            'total' => '10.00',
            'credit' => '0.00',
        ];

        $this->assertTrue($this->service->isCreditOnlyPayment($invoice));
    }

    public function testTreatsInvoiceAsNonCreditOnlyWhenGatewayReceivesPartialAmount(): void
    {
        $invoice = [
            'paymentmethod' => 'paypal',
            'subtotal' => '119.90',
            'total' => '95.90',
            'credit' => '24.00',
        ];

        $this->assertFalse($this->service->isCreditOnlyPayment($invoice));
    }

    public function testKeepsWhmcs8AmountsWhenTheLedgerColumnIsAbsent(): void
    {
        Capsule::reset();
        Capsule::$rows['tblaccounts'] = [
            (object) [
                'invoiceid' => 10,
                'type' => 'invoice_credit_applied',
                'amountin' => '24.00',
                'amountout' => '0',
            ],
        ];
        $service = new InvoiceFinancialsService();
        $invoice = [
            'id' => 10,
            'paymentmethod' => 'paypal',
            'subtotal' => '119.90',
            'total' => '95.90',
            'credit' => '24.00',
        ];

        $this->assertSame(95.90, $service->getGatewayPaidAmount($invoice));
        $this->assertSame(24.0, $service->getAppliedCredit($invoice));
        $this->assertFalse($service->isCreditOnlyPayment($invoice));
        Capsule::reset();
    }

    public function testWhmcs9CreditOnlyPaymentUsesLedger(): void
    {
        $service = $this->withLedger(10.0);
        $invoice = [
            'id' => 139716,
            'paymentmethod' => 'pix',
            'total' => '10.00',
            'credit' => '0.00',
        ];

        $this->assertTrue($service->isCreditOnlyPayment($invoice));
        $this->assertSame(0.0, $service->getGatewayPaidAmount($invoice));
        $this->assertSame(10.0, $service->getAppliedCredit($invoice));
    }

    public function testWhmcs9PartialCreditLeavesGatewayAmount(): void
    {
        $service = $this->withLedger(4.0);
        $invoice = [
            'id' => 139715,
            'paymentmethod' => 'paghiper_pix',
            'total' => '10.00',
            'credit' => '0.00',
        ];

        $this->assertSame(6.0, $service->getGatewayPaidAmount($invoice));
        $this->assertSame(4.0, $service->getAppliedCredit($invoice));
        $this->assertFalse($service->isCreditOnlyPayment($invoice));
    }

    public function testWhmcs9MarkPaidWithoutLedgerCreditKeepsFullTotal(): void
    {
        $service = $this->withLedger(0.0);
        $invoice = [
            'id' => 139717,
            'paymentmethod' => 'pix',
            'total' => '10.00',
            'credit' => '0.00',
        ];

        $this->assertSame(10.0, $service->getGatewayPaidAmount($invoice));
        $this->assertSame(0.0, $service->getAppliedCredit($invoice));
        $this->assertFalse($service->isCreditOnlyPayment($invoice));
    }

    public function testDoesNotConsultLedgerWhenInvoiceHasNoId(): void
    {
        $source = $this->recordingLedger(10.0);
        $service = new InvoiceFinancialsService($source);
        $invoice = [
            'paymentmethod' => 'pix',
            'total' => '10.00',
            'credit' => '0.00',
        ];

        $this->assertSame(0.0, $service->getAppliedCredit($invoice));
        $this->assertSame(10.0, $service->getGatewayPaidAmount($invoice));
        $this->assertFalse($service->isCreditOnlyPayment($invoice));
        $this->assertFalse($service->isZeroValue($invoice));
        $this->assertSame(0, $source->calls);
    }

    public function testReadsLedgerOnceInsideCreditOnlyCheck(): void
    {
        $source = $this->recordingLedger(10.0);
        $service = new InvoiceFinancialsService($source);
        $invoice = [
            'id' => 139716,
            'paymentmethod' => 'pix',
            'total' => '10.00',
            'credit' => '0.00',
        ];

        $this->assertTrue($service->isCreditOnlyPayment($invoice));
        $this->assertSame(1, $source->calls);
    }

    private function withLedger(float $amount): InvoiceFinancialsService
    {
        return new InvoiceFinancialsService($this->recordingLedger($amount));
    }

    private function recordingLedger(float $amount): LedgerCreditSource
    {
        return new class ($amount) implements LedgerCreditSource {
            public int $calls = 0;

            public function __construct(private float $amount)
            {
            }

            public function appliedClientCredit(int $invoiceId): float
            {
                $this->calls++;

                return $this->amount;
            }
        };
    }
}
