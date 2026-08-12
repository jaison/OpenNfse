<?php

declare(strict_types=1);

namespace OpenNfse\Hooks;

use OpenNfse\Services\AutoEmitEnqueueService;

final class InvoicePaidHook
{
    public function handle(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        (new AutoEmitEnqueueService())->handle($invoiceId, AutoEmitEnqueueService::TRIGGER_PAID);
    }
}
