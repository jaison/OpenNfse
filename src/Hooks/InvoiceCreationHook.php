<?php

declare(strict_types=1);

namespace OpenNfse\Hooks;

use OpenNfse\Services\AutoEmitEnqueueService;

/**
 * Dispara emissão automática na criação da fatura quando allow_unpaid_auto_emit=1
 * (clientes que só pagam após receber a NFS-e).
 */
final class InvoiceCreationHook
{
    public function handle(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        (new AutoEmitEnqueueService())->handle($invoiceId, AutoEmitEnqueueService::TRIGGER_CREATED);
    }
}
