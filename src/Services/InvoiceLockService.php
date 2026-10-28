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

    /** @var array<int, int> faturas em que o lock não foi pedido (ver acquire) */
    private array $skipped = [];

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

        // Em MySQL < 5.7.5 / MariaDB < 10.0.2, pedir este lock soltaria o que o processo já tem
        // (o do cron, no lote da fila). Nesse caso o lock do cron segue garantindo a exclusão,
        // como antes do lock por fatura existir.
        if ($this->locks->holdsAnyLock() && !$this->locks->serverSupportsMultipleLocks()) {
            $this->skipped[$invoiceId] = ($this->skipped[$invoiceId] ?? 0) + 1;
            return true;
        }

        return $this->locks->acquire($this->locks->scopedName(self::suffixFor($invoiceId)), $timeoutSeconds);
    }

    public function release(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }

        if (($this->skipped[$invoiceId] ?? 0) > 0) {
            $this->skipped[$invoiceId]--;
            return;
        }

        $this->locks->release($this->locks->scopedName(self::suffixFor($invoiceId)));
    }
}
