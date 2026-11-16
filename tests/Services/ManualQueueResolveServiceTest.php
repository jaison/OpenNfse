<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Hooks\InvoiceHook;
use OpenNfse\Services\ClosedInvoiceQueuePolicy;
use OpenNfse\Services\ManualQueueResolveService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class ManualQueueResolveServiceTest extends TestCase
{
    protected function setUp(): void
    {
        Capsule::reset();
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        unset($_SESSION['token']);
    }

    public function testLogPayloadKeepsAdminAndOrigin(): void
    {
        $payload = ManualQueueResolveService::logPayload(
            ['id' => 9, 'invoiceid' => 44],
            7,
            ManualQueueResolveService::ORIGEM_FATURA,
            '2026-11-16 12:00:00'
        );

        $this->assertSame(9, $payload['queue_id']);
        $this->assertSame(44, $payload['invoiceid']);
        $this->assertSame(7, $payload['admin_id']);
        $this->assertSame('fatura', $payload['origem']);
        $this->assertSame('2026-11-16 12:00:00', $payload['resolvido_em']);
    }

    public function testLogPayloadOmitsEmptyAdmin(): void
    {
        $payload = ManualQueueResolveService::logPayload(
            ['id' => 1, 'invoiceid' => 2],
            0,
            ManualQueueResolveService::ORIGEM_FILA,
            '2026-11-16 12:00:00'
        );

        $this->assertNull($payload['admin_id']);
        $this->assertSame('fila', $payload['origem']);
    }

    public function testResolveItemIgnoresAnythingOtherThanError(): void
    {
        Capsule::$updateResult = 1;
        $ok = (new ManualQueueResolveService())->resolveItem(
            ['id' => 3, 'status' => 'PENDING', 'invoiceid' => 8],
            4,
            ManualQueueResolveService::ORIGEM_FILA
        );

        $this->assertFalse($ok);
        $this->assertSame([], Capsule::$updates);
        $this->assertSame([], Capsule::$inserts);
    }

    public function testResolveInvoiceLogsEachErrorAndLeavesNotaUntouched(): void
    {
        Capsule::$updateResult = 1;
        Capsule::$rows['mod_opennfse_queue'] = [
            (object) ['id' => 10, 'invoiceid' => 7, 'correlation_id' => 'c1', 'status' => 'ERROR'],
            (object) ['id' => 11, 'invoiceid' => 7, 'correlation_id' => 'c2', 'status' => 'ERROR'],
        ];

        $resolved = (new ManualQueueResolveService())->resolveInvoice(7, 15);

        $this->assertSame(2, $resolved);
        $this->assertSame(['mod_opennfse_queue'], array_keys(Capsule::$updates));
        $logs = Capsule::$inserts['mod_opennfse_logs'] ?? [];
        $this->assertCount(2, $logs);
        $this->assertSame(ClosedInvoiceQueuePolicy::MANUAL_LOG_TYPE, $logs[0]['tipo']);
        $this->assertSame(ClosedInvoiceQueuePolicy::MANUAL_LOG_TYPE, $logs[1]['tipo']);
        $first = json_decode((string) $logs[0]['request'], true);
        $second = json_decode((string) $logs[1]['request'], true);
        $this->assertSame(10, $first['queue_id']);
        $this->assertSame(11, $second['queue_id']);
        $this->assertSame(15, $first['admin_id']);
        $this->assertSame('fatura', $first['origem']);
        $this->assertSame('fatura', $second['origem']);
        $this->assertArrayNotHasKey('mod_opennfse_notas', Capsule::$updates);
        $this->assertArrayNotHasKey('tblinvoices', Capsule::$updates);
    }

    public function testResolveInvoiceWithNoQueueReturnsZero(): void
    {
        $this->assertSame(0, (new ManualQueueResolveService())->resolveInvoice(0, 1));
        $this->assertSame(0, (new ManualQueueResolveService())->resolveInvoice(9, 1));
        $this->assertSame([], Capsule::$inserts);
    }

    public function testInvoicePanelShowsTheSameConfirmOnlyWhenThereIsAnError(): void
    {
        $_SESSION['token'] = 'tokenteste';
        $hook = new InvoiceHook();
        $without = $hook->renderControls(['filename' => 'invoices.php', 'invoiceid' => 7]);
        $this->assertStringNotContainsString('Marcar como resolvido', $without);
        $this->assertStringNotContainsString('filaResolveInvoice', $without);

        Capsule::$rows['mod_opennfse_queue'] = [
            (object) ['id' => 10, 'invoiceid' => 7, 'status' => 'ERROR', 'correlation_id' => 'c1'],
        ];
        $with = $hook->renderControls(['filename' => 'invoices.php', 'invoiceid' => 7]);

        $this->assertStringContainsString('action=filaResolveInvoice', $with);
        $this->assertStringContainsString('name="token"', $with);
        $this->assertStringContainsString('name="invoiceid" value="7"', $with);
        $this->assertStringContainsString('Marcar como resolvido', $with);
        $this->assertStringContainsString(ManualQueueResolveService::CONFIRM_MESSAGE, $with);
        $this->assertSame(
            'O erro sai da lista de pendências. Nenhuma NFS-e é emitida ou cancelada.',
            ManualQueueResolveService::CONFIRM_MESSAGE
        );
    }

    public function testResolveInvoiceMarksRejectedNoteWithoutQueueItem(): void
    {
        Capsule::$updateResult = 1;
        Capsule::$rows['mod_opennfse_notas'] = [
            (object) [
                'id' => 4,
                'invoiceid' => 152233,
                'status' => 'REJEITADA',
                'falha_resolvida_em' => null,
                'erro_api' => 'rejeitada',
            ],
        ];

        $resolved = (new ManualQueueResolveService())->resolveInvoice(152233, 9);

        $this->assertSame(1, $resolved);
        $noteUpdates = Capsule::$updates['mod_opennfse_notas'] ?? [];
        $this->assertCount(1, $noteUpdates);
        $this->assertSame(9, $noteUpdates[0]['falha_resolvida_por']);
        $this->assertNotSame('', (string) $noteUpdates[0]['falha_resolvida_em']);
        $this->assertArrayNotHasKey('status', $noteUpdates[0]);
        $this->assertArrayNotHasKey('tblinvoices', Capsule::$updates);
        $logs = Capsule::$inserts['mod_opennfse_logs'] ?? [];
        $this->assertCount(1, $logs);
        $this->assertSame(ManualQueueResolveService::NOTA_LOG_TYPE, $logs[0]['tipo']);
        $this->assertSame(4, $logs[0]['nota_id']);
        $payload = json_decode((string) $logs[0]['request'], true);
        $this->assertSame('fatura', $payload['origem']);
        $this->assertSame('REJEITADA', $payload['nota_status']);
        $this->assertSame(9, $payload['admin_id']);
    }

    public function testInvoicePanelOffersResolveForOpenRejectionAndFoldsResolvedError(): void
    {
        $_SESSION['token'] = 'tokenteste';
        $hook = new InvoiceHook();
        Capsule::$rows['mod_opennfse_notas'] = [
            (object) [
                'id' => 4,
                'invoiceid' => 7,
                'status' => 'REJEITADA',
                'erro_api' => 'erro antigo',
                'id_dps' => 'DPS1',
                'falha_resolvida_em' => null,
            ],
        ];

        $open = $hook->renderControls(['filename' => 'invoices.php', 'invoiceid' => 7]);
        $this->assertStringContainsString('Marcar como resolvido', $open);
        $this->assertStringContainsString('Reemitir NFS-e', $open);
        $this->assertStringContainsString('erro antigo', $open);
        $this->assertStringNotContainsString('Ver erro original', $open);

        Capsule::$rows['mod_opennfse_notas'][0]->falha_resolvida_em = '2026-11-16 13:05:00';
        $done = $hook->renderControls(['filename' => 'invoices.php', 'invoiceid' => 7]);
        $this->assertStringContainsString('não emitida — erro marcado como resolvido em 16/11/2026 13:05', $done);
        $this->assertStringContainsString('Ver erro original', $done);
        $this->assertStringContainsString('erro antigo', $done);
        $this->assertStringContainsString('Reemitir NFS-e', $done);
        $this->assertStringNotContainsString('Marcar como resolvido', $done);
    }
}
