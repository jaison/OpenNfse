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
}
