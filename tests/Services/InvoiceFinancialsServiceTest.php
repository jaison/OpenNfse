<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\InvoiceFinancialsService;
use PHPUnit\Framework\TestCase;

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
}
