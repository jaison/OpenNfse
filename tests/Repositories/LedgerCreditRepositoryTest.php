<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Repositories;

use OpenNfse\Repositories\LedgerCreditRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WHMCS\Database\Capsule;

final class LedgerCreditRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Capsule::reset();
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        parent::tearDown();
    }

    public function testReturnsZeroWhenTypeColumnIsMissing(): void
    {
        Capsule::$rows['tblaccounts'] = [
            (object) [
                'invoiceid' => 139716,
                'type' => 'invoice_credit_applied',
                'amountin' => '10.00',
                'amountout' => '0',
            ],
        ];

        $this->assertSame(0.0, (new LedgerCreditRepository())->appliedClientCredit(139716));
    }

    public function testReturnsZeroWhenSchemaCheckThrows(): void
    {
        Capsule::$schemaError = new RuntimeException('schema indisponível');

        $this->assertSame(0.0, (new LedgerCreditRepository())->appliedClientCredit(139716));
    }

    public function testReturnsZeroWhenTheQueryThrows(): void
    {
        Capsule::$columns['tblaccounts']['type'] = true;
        Capsule::$getError = new RuntimeException('falha na leitura');

        $this->assertSame(0.0, (new LedgerCreditRepository())->appliedClientCredit(139715));
    }

    public function testSumsOnlyClientCreditAppliedToTheInvoice(): void
    {
        Capsule::$columns['tblaccounts']['type'] = true;
        Capsule::$rows['tblaccounts'] = [
            (object) [
                'invoiceid' => 139715,
                'type' => 'invoice_credit_applied',
                'amountin' => '4.00',
                'amountout' => '1.00',
            ],
            (object) [
                'invoiceid' => 139715,
                'type' => 'gateway_funds_in',
                'amountin' => '6.00',
                'amountout' => '0',
            ],
            (object) [
                'invoiceid' => 139715,
                'type' => 'invoice_billing_adjustment_credit',
                'amountin' => '10.00',
                'amountout' => '0',
            ],
            (object) [
                'invoiceid' => 99,
                'type' => 'invoice_credit_applied',
                'amountin' => '8.00',
                'amountout' => '0',
            ],
        ];

        $this->assertSame(3.0, (new LedgerCreditRepository())->appliedClientCredit(139715));
    }

    public function testCachesTheColumnCheckButNotTheAmount(): void
    {
        Capsule::$columns['tblaccounts']['type'] = true;
        Capsule::$rows['tblaccounts'] = [
            (object) [
                'invoiceid' => 1,
                'type' => 'invoice_credit_applied',
                'amountin' => '4.00',
                'amountout' => '0',
            ],
        ];
        $repository = new LedgerCreditRepository();

        $this->assertSame(4.0, $repository->appliedClientCredit(1));
        Capsule::$rows['tblaccounts'][0]->amountin = '7.00';

        $this->assertSame(7.0, $repository->appliedClientCredit(1));
        $this->assertSame(1, Capsule::$schemaCalls);
    }

    public function testSchemaFailureDoesNotStickForTheRestOfTheProcess(): void
    {
        Capsule::$schemaError = new RuntimeException('schema indisponível');
        $repository = new LedgerCreditRepository();
        $this->assertSame(0.0, $repository->appliedClientCredit(1));

        Capsule::$schemaError = null;
        Capsule::$columns['tblaccounts']['type'] = true;
        Capsule::$rows['tblaccounts'] = [
            (object) [
                'invoiceid' => 1,
                'type' => 'invoice_credit_applied',
                'amountin' => '2.00',
                'amountout' => '0',
            ],
        ];

        $this->assertSame(2.0, $repository->appliedClientCredit(1));
    }

    public function testHasLedgerTypeColumnIsFalseWithoutTheColumn(): void
    {
        $this->assertFalse(LedgerCreditRepository::hasLedgerTypeColumn());
    }

    public function testHasLedgerTypeColumnIsTrueWhenTheColumnExistsAndStaysCached(): void
    {
        Capsule::$columns['tblaccounts']['type'] = true;

        $this->assertTrue(LedgerCreditRepository::hasLedgerTypeColumn());
        $this->assertTrue(LedgerCreditRepository::hasLedgerTypeColumn());
        $this->assertSame(1, Capsule::$schemaCalls);
    }

    public function testHasLedgerTypeColumnIsFalseWhenSchemaThrowsAndDoesNotCache(): void
    {
        Capsule::$schemaError = new RuntimeException('schema indisponível');

        $this->assertFalse(LedgerCreditRepository::hasLedgerTypeColumn());

        Capsule::$schemaError = null;
        Capsule::$columns['tblaccounts']['type'] = true;

        $this->assertTrue(LedgerCreditRepository::hasLedgerTypeColumn());
    }

    public function testManualPaidStaysOutOfTheGatewayAmountWhileTheOptionIsOn(): void
    {
        Capsule::$columns['tblaccounts']['type'] = true;
        Capsule::$rows['tblaccounts'] = [
            (object) [
                'invoiceid' => 20,
                'type' => 'invoice_credit_applied',
                'amountin' => '3.00',
                'amountout' => '0',
            ],
            (object) [
                'invoiceid' => 20,
                'type' => 'invoice_billing_adjustment_credit',
                'amountin' => '7.00',
                'amountout' => '0',
            ],
        ];
        $repository = new LedgerCreditRepository();

        $this->assertFalse(LedgerCreditRepository::deductsManualPaidFromGateway());
        $this->assertSame(3.0, $repository->appliedClientCredit(20));
        $this->assertSame(3.0, $repository->appliedNonGatewayAmount(20));
    }

    public function testManualPaidJoinsTheNonGatewayAmountWhenTheOptionIsOff(): void
    {
        Capsule::$columns['tblaccounts']['type'] = true;
        Capsule::$rows['mod_opennfse_config'] = [
            (object) ['emit_on_manual_paid' => '0'],
        ];
        Capsule::$rows['tblaccounts'] = [
            (object) [
                'invoiceid' => 21,
                'type' => 'invoice_credit_applied',
                'amountin' => '3.00',
                'amountout' => '0',
            ],
            (object) [
                'invoiceid' => 21,
                'type' => 'invoice_billing_adjustment_credit',
                'amountin' => '7.00',
                'amountout' => '1.00',
            ],
            (object) [
                'invoiceid' => 21,
                'type' => 'gateway_funds_in',
                'amountin' => '4.00',
                'amountout' => '0',
            ],
        ];
        $repository = new LedgerCreditRepository();

        $this->assertTrue(LedgerCreditRepository::deductsManualPaidFromGateway());
        $this->assertSame(3.0, $repository->appliedClientCredit(21));
        $this->assertSame(9.0, $repository->appliedNonGatewayAmount(21));
    }

    public function testManualPaidIsIgnoredWithoutTheTypeColumnEvenIfTheOptionIsOff(): void
    {
        Capsule::$rows['mod_opennfse_config'] = [
            (object) ['emit_on_manual_paid' => '0'],
        ];
        Capsule::$rows['tblaccounts'] = [
            (object) [
                'invoiceid' => 22,
                'type' => 'invoice_billing_adjustment_credit',
                'amountin' => '8.00',
                'amountout' => '0',
            ],
        ];

        $this->assertFalse(LedgerCreditRepository::deductsManualPaidFromGateway());
        $this->assertSame(0.0, (new LedgerCreditRepository())->appliedNonGatewayAmount(22));
    }
}
