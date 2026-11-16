<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\QueueRepository;

/**
 * Passa itens ERROR da fila para RESOLVIDO quando a fatura foi cancelada ou estornada
 * e não há NFS-e emitida. Não apaga nota nem fatura.
 */
final class ClosedInvoiceErrorResolver
{
    public function resolveInvoice(int $invoiceId, string $invoiceStatus, string $origem): int
    {
        if ($invoiceId <= 0 || !ClosedInvoiceQueuePolicy::shouldResolveQueueErrors(
            $invoiceStatus,
            (new NotaRepository())->findByInvoiceId($invoiceId)
        )) {
            return 0;
        }

        return $this->resolveItems(
            (new QueueRepository())->listErrorItemsByInvoice($invoiceId),
            $invoiceStatus,
            $origem
        );
    }

    public function resolveBatch(int $limit = 100): int
    {
        $resolved = 0;
        foreach ((new QueueRepository())->listErrorItemsForClosedInvoices($limit) as $item) {
            $resolved += $this->resolveItems(
                [$item],
                (string) ($item['invoice_status'] ?? ''),
                'cron'
            );
        }

        return $resolved;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function resolveItems(array $items, string $invoiceStatus, string $origem): int
    {
        $repo = new QueueRepository();
        $log = new LogRepository();
        $count = 0;
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0 || !$repo->markErrorResolved($id)) {
                continue;
            }
            $count++;
            $correlation = trim((string) ($item['correlation_id'] ?? ''));
            $log->insert(
                null,
                ClosedInvoiceQueuePolicy::LOG_TYPE,
                json_encode([
                    'queue_id' => $id,
                    'invoiceid' => (int) ($item['invoiceid'] ?? 0),
                    'invoice_status' => $invoiceStatus,
                    'origem' => $origem,
                ], JSON_UNESCAPED_UNICODE),
                null,
                $correlation !== '' ? $correlation : null
            );
        }

        return $count;
    }
}
