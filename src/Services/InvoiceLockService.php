<?php

declare(strict_types=1);

namespace OpenNfse\Services;

/**
 * Lock por fatura (GET_LOCK), usado na emissão e no enfileiramento para
 * impedir duas DPS ou dois itens ativos da fila para a mesma fatura.
 */
final class InvoiceLockService
{
    public const WAIT_SECONDS = 5;
    public const BUSY_MESSAGE = 'Emissão desta fatura já em andamento em outro processo. '
        . 'Aguarde alguns instantes e consulte o status antes de tentar de novo.';

    private CronLockService $locks;

    public function __construct(?CronLockService $locks = null)
    {
        $this->locks = $locks ?? new CronLockService();
    }

    public static function suffixFor(int $invoiceId): string
    {
        return 'opennfse_inv_' . $invoiceId;
    }

    public function acquire(int $invoiceId, int $timeoutSeconds = self::WAIT_SECONDS): bool
    {
        if ($invoiceId <= 0) {
            return false;
        }

        return $this->locks->acquire($this->locks->scopedName(self::suffixFor($invoiceId)), $timeoutSeconds);
    }

    public function release(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }

        $this->locks->release($this->locks->scopedName(self::suffixFor($invoiceId)));
    }
}
