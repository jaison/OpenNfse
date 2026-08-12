<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\EmissionEligibilityService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class EmissionEligibilityServiceTest extends TestCase
{
    private EmissionEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmissionEligibilityService();
        Capsule::reset();
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        parent::tearDown();
    }

    public function testSkipsWhenInvoiceIsNotPaid(): void
    {
        $invoice = [
            'status' => 'Unpaid',
            'paymentmethod' => 'paypal',
            'credit' => '0.00',
        ];

        $result = $this->service->check($invoice, ['context' => EmissionEligibilityService::CONTEXT_AUTO]);

        $this->assertNotNull($result);
        $this->assertSame(EmissionEligibilityService::SKIP_NOT_PAID, $result['reason']);
        $this->assertSame('unpaid', $result['status']);
    }

    public function testAllowsUnpaidWhenManualAndConfigEnabled(): void
    {
        Capsule::$rows['mod_opennfse_config'] = [
            (object) ['id' => 1, 'allow_unpaid_manual_emit' => 1],
        ];
        Capsule::$rows['mod_opennfse_payment_gateway_settings'] = [
            (object) ['gateway' => 'paypal', 'enabled' => 1],
        ];

        $invoice = [
            'status' => 'Unpaid',
            'paymentmethod' => 'paypal',
            'credit' => '0.00',
        ];

        $this->assertNull($this->service->check($invoice, ['context' => EmissionEligibilityService::CONTEXT_MANUAL]));
    }

    public function testStillSkipsUnpaidOnAutoEvenWhenManualAllowed(): void
    {
        Capsule::$rows['mod_opennfse_config'] = [
            (object) ['id' => 1, 'allow_unpaid_manual_emit' => 1, 'allow_unpaid_auto_emit' => 0],
        ];

        $invoice = [
            'status' => 'Unpaid',
            'paymentmethod' => 'paypal',
            'credit' => '0.00',
        ];

        $result = $this->service->check($invoice, ['context' => EmissionEligibilityService::CONTEXT_AUTO]);
        $this->assertNotNull($result);
        $this->assertSame(EmissionEligibilityService::SKIP_NOT_PAID, $result['reason']);
    }

    public function testAllowsUnpaidOnAutoWhenConfigEnabled(): void
    {
        Capsule::$rows['mod_opennfse_config'] = [
            (object) ['id' => 1, 'allow_unpaid_auto_emit' => 1],
        ];

        $invoice = [
            'status' => 'Unpaid',
            'paymentmethod' => 'paypal',
            'credit' => '0.00',
        ];

        $this->assertNull($this->service->check($invoice, ['context' => EmissionEligibilityService::CONTEXT_AUTO]));
    }

    public function testSkipsWhenPaidWithCreditPaymentMethod(): void
    {
        $invoice = [
            'status' => 'Paid',
            'paymentmethod' => 'credit',
            'credit' => '0.00',
        ];

        $result = $this->service->check($invoice);

        $this->assertNotNull($result);
        $this->assertSame(EmissionEligibilityService::SKIP_CREDIT_PAYMENT, $result['reason']);
    }

    public function testAllowsWhenPaidWithGatewayAndPartialCreditApplied(): void
    {
        Capsule::$rows['mod_opennfse_payment_gateway_settings'] = [
            (object) ['gateway' => 'paypal', 'enabled' => 1],
        ];

        $invoice = [
            'status' => 'Paid',
            'paymentmethod' => 'paypal',
            'subtotal' => '119.90',
            'total' => '95.90',
            'credit' => '24,00',
        ];

        $this->assertNull($this->service->check($invoice));
    }

    public function testSkipsWhenCreditConsumesEntireInvoiceBalance(): void
    {
        $invoice = [
            'status' => 'Paid',
            'paymentmethod' => 'paypal',
            'subtotal' => '10.00',
            'total' => '0.00',
            'credit' => '10.00',
        ];

        $result = $this->service->check($invoice);

        $this->assertNotNull($result);
        $this->assertSame(EmissionEligibilityService::SKIP_CREDIT_PAYMENT, $result['reason']);
        $this->assertSame(0.0, $result['gatewayPaidAmount']);
    }

    public function testSkipsWhenPaymentGatewayIsDisabled(): void
    {
        Capsule::$rows['mod_opennfse_payment_gateway_settings'] = [
            (object) ['gateway' => 'paypal', 'enabled' => 0],
        ];

        $invoice = [
            'status' => 'Paid',
            'paymentmethod' => 'paypal',
            'credit' => '0.00',
        ];

        $result = $this->service->check($invoice);

        $this->assertNotNull($result);
        $this->assertSame(EmissionEligibilityService::SKIP_GATEWAY_DISABLED, $result['reason']);
        $this->assertSame('paypal', $result['paymentMethod']);
    }

    public function testEligibleWhenPaidNonCreditAndGatewayEnabled(): void
    {
        Capsule::$rows['mod_opennfse_payment_gateway_settings'] = [
            (object) ['gateway' => 'paypal', 'enabled' => 1],
        ];

        $invoice = [
            'status' => 'Paid',
            'paymentmethod' => 'paypal',
            'credit' => '0.00',
        ];

        $this->assertNull($this->service->check($invoice));
    }

    public function testEligibleWhenPaidAndNoGatewaySettingsConfigured(): void
    {
        $invoice = [
            'status' => 'Paid',
            'paymentmethod' => 'banktransfer',
            'credit' => '0',
        ];

        $this->assertNull($this->service->check($invoice));
    }
}
