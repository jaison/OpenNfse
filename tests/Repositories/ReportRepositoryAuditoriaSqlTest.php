<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Repositories;

use OpenNfse\Repositories\ReportRepository;
use PHPUnit\Framework\TestCase;

final class ReportRepositoryAuditoriaSqlTest extends TestCase
{
    public function testWhmcs8KeepsTheInvoiceTotal(): void
    {
        $sql = ReportRepository::auditoriaGatewaySql(false);

        $this->assertSame([
            'amount' => 'i.total',
            'filter' => null,
            'invoice_total' => 'i.total as invoice_total',
            'total_valor' => 'SUM(i.total) as total_valor',
        ], $sql);
        $this->assertStringNotContainsString('tblaccounts', $sql['amount']);
        $this->assertStringNotContainsString('0.005', $sql['invoice_total']);
        $this->assertStringNotContainsString('0.005', $sql['total_valor']);
    }

    public function testWhmcs9SubtractsNonNegativeLedgerCredit(): void
    {
        $amount = '(i.total - GREATEST(COALESCE((SELECT SUM(amountin - amountout) FROM tblaccounts'
            . " WHERE invoiceid = i.id AND type = 'invoice_credit_applied'), 0), 0))";

        $sql = ReportRepository::auditoriaGatewaySql(true);

        $this->assertSame($amount, $sql['amount']);
        $this->assertSame($amount . ' > 0.005', $sql['filter']);
        $this->assertSame($amount . ' as invoice_total', $sql['invoice_total']);
        $this->assertSame('SUM(' . $amount . ') as total_valor', $sql['total_valor']);
    }
}
