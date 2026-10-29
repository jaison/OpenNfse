<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Hooks\InvoiceAlertHook;
use OpenNfse\Migrations\Migrator;
use OpenNfse\Repositories\AlertCheckRepository;
use OpenNfse\Repositories\AlertCheckRepositoryInterface;
use OpenNfse\Repositories\LogRepository;

/**
 * Conferência diferida do total da fatura com o valor da NFS-e.
 *
 * O hook UpdateInvoiceTotal só registra o pedido. O cron confere depois, com o total
 * já gravado, o que torna o resultado independente de o hook rodar antes ou depois
 * de o WHMCS salvar o total novo.
 */
final class AlertCheckService
{
    /** Idade mínima do pedido, para a requisição que alterou a fatura terminar de gravar. */
    public const MIN_AGE_SECONDS = 30;

    public const BATCH_LIMIT = 200;

    private AlertCheckRepositoryInterface $repository;

    /** @var callable(int): void */
    private $checker;

    /** @var callable(): void */
    private $ensureSchema;

    public function __construct(
        ?AlertCheckRepositoryInterface $repository = null,
        ?callable $checker = null,
        ?callable $ensureSchema = null
    ) {
        $this->repository = $repository ?? new AlertCheckRepository();
        $this->checker = $checker ?? static function (int $invoiceId): void {
            (new InvoiceAlertHook())->checkTotalNow($invoiceId);
        };
        $this->ensureSchema = $ensureSchema ?? static function (): void {
            (new Migrator())->up();
        };
    }

    public function request(int $invoiceId, ?int $now = null): void
    {
        if ($invoiceId <= 0) {
            return;
        }

        $at = date('Y-m-d H:i:s', $now ?? time());
        try {
            $this->repository->request($invoiceId, $at);
        } catch (\Throwable $e) {
            if (!self::isMissingTableError($e)) {
                throw $e;
            }
            ($this->ensureSchema)();
            $this->repository->request($invoiceId, $at);
        }
    }

    /**
     * Processa os pedidos vencidos. Devolve quantas faturas foram conferidas.
     */
    public function processDue(?int $now = null, int $limit = self::BATCH_LIMIT): int
    {
        $before = date('Y-m-d H:i:s', ($now ?? time()) - self::MIN_AGE_SECONDS);
        $processed = 0;

        foreach ($this->repository->listDue($before, $limit) as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $requestedAt = (string) ($row['solicitado_em'] ?? '');
            if ($invoiceId <= 0 || !$this->repository->claim($invoiceId, $requestedAt)) {
                continue;
            }

            try {
                ($this->checker)($invoiceId);
                $processed++;
            } catch (\Throwable $e) {
                self::logError($invoiceId, $e);
            }
        }

        return $processed;
    }

    private static function isMissingTableError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return stripos($message, 'Base table or view not found') !== false || stripos($message, "doesn't exist") !== false;
    }

    private static function logError(int $invoiceId, \Throwable $e): void
    {
        try {
            (new LogRepository())->insert(
                null,
                'NFSE_ALERTA_ERRO',
                json_encode(['invoiceid' => $invoiceId, 'origem' => 'conferencia_diferida'], JSON_UNESCAPED_UNICODE),
                $e->getMessage()
            );
        } catch (\Throwable $loggingError) {
            return;
        }
    }
}
