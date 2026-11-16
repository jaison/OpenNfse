<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\QueueRepository;

/**
 * Marca item ERROR da fila como RESOLVIDO, na fila ou na fatura.
 * Não emite, não cancela e não altera nota nem fatura.
 */
final class ManualQueueResolveService
{
    public const CONFIRM_MESSAGE = 'O erro sai da lista de pendências. Nenhuma NFS-e é emitida ou cancelada.';

    public const ORIGEM_FILA = 'fila';

    public const ORIGEM_FATURA = 'fatura';

    /**
     * @param array<string, mixed> $row
     * @return array{queue_id: int, invoiceid: int, admin_id: int|null, resolvido_em: string, origem: string}
     */
    public static function logPayload(array $row, int $adminId, string $origem, string $resolvedAt): array
    {
        return [
            'queue_id' => (int) ($row['id'] ?? 0),
            'invoiceid' => (int) ($row['invoiceid'] ?? 0),
            'admin_id' => $adminId > 0 ? $adminId : null,
            'resolvido_em' => $resolvedAt,
            'origem' => $origem,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolveItem(array $row, int $adminId, string $origem): bool
    {
        if (!QueueStatePolicy::canMarkResolved((string) ($row['status'] ?? ''))) {
            return false;
        }

        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || !(new QueueRepository())->markErrorResolved($id)) {
            return false;
        }

        $correlation = trim((string) ($row['correlation_id'] ?? ''));
        (new LogRepository())->insert(
            null,
            ClosedInvoiceQueuePolicy::MANUAL_LOG_TYPE,
            json_encode(self::logPayload($row, $adminId, $origem, date('Y-m-d H:i:s')), JSON_UNESCAPED_UNICODE),
            null,
            $correlation !== '' ? $correlation : null
        );

        return true;
    }

    public function resolveInvoice(int $invoiceId, int $adminId): int
    {
        if ($invoiceId <= 0) {
            return 0;
        }

        $resolved = 0;
        foreach ((new QueueRepository())->listErrorItemsByInvoice($invoiceId) as $item) {
            $item['status'] = 'ERROR';
            if ($this->resolveItem($item, $adminId, self::ORIGEM_FATURA)) {
                $resolved++;
            }
        }

        return $resolved;
    }
}
