<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\ClosedInvoiceQueuePolicy;
use OpenNfse\Services\QueueStatePolicy;
use PHPUnit\Framework\TestCase;

final class ClosedInvoiceQueuePolicyTest extends TestCase
{
    public function testResolvesClosedInvoiceWithoutNota(): void
    {
        $this->assertTrue(ClosedInvoiceQueuePolicy::shouldResolveQueueErrors('Cancelled', null));
        $this->assertTrue(ClosedInvoiceQueuePolicy::shouldResolveQueueErrors('refunded', null));
        $this->assertTrue(ClosedInvoiceQueuePolicy::shouldResolveQueueErrors(' REFUNDED ', null));
    }

    public function testDoesNotResolveOpenInvoice(): void
    {
        foreach (['Paid', 'Unpaid', 'Collections', 'Payment Pending', 'Draft', ''] as $status) {
            $this->assertFalse(
                ClosedInvoiceQueuePolicy::shouldResolveQueueErrors($status, null),
                $status
            );
        }
    }

    /**
     * @dataProvider failureNoteProvider
     */
    public function testResolvesClosedInvoiceWhenFailureNoteHasNoChave(array $nota): void
    {
        $this->assertTrue(ClosedInvoiceQueuePolicy::shouldResolveQueueErrors('Cancelled', $nota));
        $this->assertFalse(ClosedInvoiceQueuePolicy::hasIssuedNfse($nota));
    }

    public static function failureNoteProvider(): array
    {
        return [
            'rejeitada sem chave' => [['status' => 'REJEITADA', 'chave_acesso' => '']],
            'erro sem chave' => [['status' => 'ERRO', 'chave_acesso' => null]],
            'emitida sem chave' => [['status' => 'EMITIDA', 'chave_acesso' => '']],
            'processando sem chave' => [['status' => 'PROCESSANDO', 'chave_acesso' => '   ']],
            'chave só em nota que não é emissão' => [['status' => 'REJEITADA', 'chave_acesso' => 'ABC']],
        ];
    }

    public function testKeepsQueueWhenIssuedNoteHasChave(): void
    {
        $emitida = ['status' => 'EMITIDA', 'chave_acesso' => 'NFS123'];
        $processando = ['status' => 'processando', 'chave_acesso' => 'NFS456'];

        $this->assertTrue(ClosedInvoiceQueuePolicy::hasIssuedNfse($emitida));
        $this->assertTrue(ClosedInvoiceQueuePolicy::hasIssuedNfse($processando));
        $this->assertFalse(ClosedInvoiceQueuePolicy::shouldResolveQueueErrors('Cancelled', $emitida));
        $this->assertFalse(ClosedInvoiceQueuePolicy::shouldResolveQueueErrors('Refunded', $processando));
    }

    public function testClosedInvoiceSqlRequiresStatusAndChave(): void
    {
        $sql = ClosedInvoiceQueuePolicy::closedWithoutIssuedNfseSql('q.invoiceid');

        $this->assertStringContainsString("closed_inv.status IN ('Cancelled', 'Refunded')", $sql);
        $this->assertStringContainsString("issued_nf.status IN ('EMITIDA', 'PROCESSANDO')", $sql);
        $this->assertStringContainsString('issued_nf.chave_acesso IS NOT NULL', $sql);
        $this->assertStringContainsString("issued_nf.chave_acesso <> ''", $sql);
        $this->assertStringContainsString('issued_nf.invoiceid = q.invoiceid', $sql);
        $this->assertStringContainsString('NOT EXISTS', $sql);
    }

    public function testPendingFailureSqlExcludesThatCase(): void
    {
        $sql = ClosedInvoiceQueuePolicy::pendingFailureSql('n.invoiceid');

        $this->assertStringStartsWith('NOT (', $sql);
        $this->assertStringContainsString('n.invoiceid IN (SELECT closed_inv.id', $sql);
        $this->assertStringNotContainsString('falha_resolvida_em', $sql);
    }

    public function testPendingFailureSqlHidesResolvedNoteFailure(): void
    {
        $sql = ClosedInvoiceQueuePolicy::pendingFailureSql('n.invoiceid', 'n');

        $this->assertStringContainsString('n.falha_resolvida_em IS NULL', $sql);
        $this->assertStringStartsWith('NOT (', $sql);
    }

    public function testUnresolvedFailureOnlyForOpenRejection(): void
    {
        $this->assertFalse(ClosedInvoiceQueuePolicy::isUnresolvedFailure(null));
        $this->assertFalse(ClosedInvoiceQueuePolicy::isUnresolvedFailure(['status' => 'EMITIDA']));
        $this->assertTrue(ClosedInvoiceQueuePolicy::isUnresolvedFailure(['status' => 'REJEITADA', 'falha_resolvida_em' => null]));
        $this->assertTrue(ClosedInvoiceQueuePolicy::isUnresolvedFailure(['status' => 'erro', 'falha_resolvida_em' => '']));
        $this->assertFalse(ClosedInvoiceQueuePolicy::isUnresolvedFailure([
            'status' => 'REJEITADA',
            'falha_resolvida_em' => '2026-11-16 13:00:00',
        ]));
    }

    public function testFeedKeepsResolvedItemsAndDropsClosedErrors(): void
    {
        $sql = ClosedInvoiceQueuePolicy::feedQueueSql('q.status', 'q.invoiceid');

        $this->assertStringContainsString("q.status <> 'ERROR'", $sql);
        $this->assertStringContainsString('NOT (', $sql);
    }

    public function testSqlRejectsUntrustedColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClosedInvoiceQueuePolicy::closedWithoutIssuedNfseSql('q.invoiceid; DROP TABLE mod_opennfse_queue');
    }

    public function testMarkResolvedButtonOnlyForError(): void
    {
        $this->assertTrue(QueueStatePolicy::canMarkResolved('ERROR'));
        foreach (['RESOLVIDO', 'DONE', 'PENDING', 'RUNNING', 'WAIT_STATUS', 'error', ''] as $status) {
            $this->assertFalse(QueueStatePolicy::canMarkResolved($status), $status);
        }
        $this->assertNotNull(QueueStatePolicy::manualRetryBlockReason(['status' => 'RESOLVIDO'], time()));
    }
}
