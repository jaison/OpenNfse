<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Hooks\InvoiceCreatedHook;
use OpenNfse\Migrations\Migrator;
use OpenNfse\Repositories\GenerationCheckRepository;
use OpenNfse\Repositories\GenerationCheckRepositoryInterface;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;

/**
 * Reserva da emissão na geração para os caminhos do WHMCS sem hook útil (ex.: "Publish" sem
 * e-mail no admin, que só chama InvoiceCreation com a fatura ainda Draft).
 *
 * O hook InvoiceCreation registra a fatura; o caminho imediato (InvoiceCreated ou
 * InvoiceCreationPreEmail) apaga o registro ao tratá-la; o cron trata o que sobrou:
 * - Unpaid: roda o mesmo tratamento da geração e apaga;
 * - Draft: mantém (apaga sem ação depois de DRAFT_MAX_DAYS);
 * - outro status (Paid, Cancelled...) ou fatura inexistente: apaga sem ação (o pagamento cuida do Paid);
 * - já tratada (item na fila em qualquer status, ou nota EMITIDA/PROCESSANDO): apaga em silêncio;
 * - falha no tratamento: loga e tenta de novo no próximo cron (até DRAFT_MAX_DAYS).
 */
final class GenerationCheckService
{
    public const BATCH_LIMIT = 200;
    public const DRAFT_MAX_DAYS = 30;

    private GenerationCheckRepositoryInterface $repository;

    /** @var callable(int): ?string status da fatura em tblinvoices, ou null se ela não existe */
    private $statusReader;

    /** @var callable(int): bool */
    private $alreadyHandled;

    /** @var callable(int, array): bool tratamento da geração; false se a fatura ainda está em rascunho */
    private $handler;

    /** @var callable(): void */
    private $ensureSchema;

    public function __construct(
        ?GenerationCheckRepositoryInterface $repository = null,
        ?callable $statusReader = null,
        ?callable $alreadyHandled = null,
        ?callable $handler = null,
        ?callable $ensureSchema = null
    ) {
        $this->repository = $repository ?? new GenerationCheckRepository();
        $this->statusReader = $statusReader ?? static function (int $invoiceId): ?string {
            return (new WhmcsInvoiceRepository())->getStatus($invoiceId);
        };
        $this->alreadyHandled = $alreadyHandled ?? static function (int $invoiceId): bool {
            return InvoiceCreatedHook::alreadyHandled($invoiceId);
        };
        $this->handler = $handler ?? static function (int $invoiceId, array $config): bool {
            return (new InvoiceCreatedHook())->processGenerated($invoiceId, $config);
        };
        $this->ensureSchema = $ensureSchema ?? static function (): void {
            (new Migrator())->up();
        };
    }

    public function register(int $invoiceId, ?int $now = null): void
    {
        if ($invoiceId <= 0) {
            return;
        }

        $at = date('Y-m-d H:i:s', $now ?? time());
        try {
            $this->repository->register($invoiceId, $at);
        } catch (\Throwable $e) {
            if (!self::isMissingTableError($e)) {
                throw $e;
            }
            ($this->ensureSchema)();
            $this->repository->register($invoiceId, $at);
        }
    }

    public function forget(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }

        try {
            $this->repository->delete($invoiceId);
        } catch (\Throwable $e) {
            if (!self::isMissingTableError($e)) {
                throw $e;
            }
        }
    }

    /**
     * Trata as conferências pendentes (só com a fila ligada). Devolve quantas faturas Unpaid
     * passaram pelo tratamento da geração.
     */
    public function processPending(array $config, ?int $now = null, int $limit = self::BATCH_LIMIT): int
    {
        if ((string) ($config['queue_enabled'] ?? '0') !== '1') {
            return 0;
        }

        $now ??= time();
        $nowAt = date('Y-m-d H:i:s', $now);
        $draftCutoff = $now - (self::DRAFT_MAX_DAYS * 86400);
        $handled = 0;

        foreach ($this->repository->listPending($limit) as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }

            try {
                if (($this->alreadyHandled)($invoiceId)) {
                    $this->repository->delete($invoiceId);
                    continue;
                }

                $status = ($this->statusReader)($invoiceId);
                $status = $status === null ? null : strtolower(trim($status));

                if ($status === 'draft') {
                    $registeredAt = strtotime((string) ($row['registrado_em'] ?? ''));
                    if ($registeredAt !== false && $registeredAt < $draftCutoff) {
                        $this->repository->delete($invoiceId);
                    } else {
                        $this->repository->touch($invoiceId, $nowAt);
                    }
                    continue;
                }

                if ($status === 'unpaid') {
                    if (($this->handler)($invoiceId, $config)) {
                        $handled++;
                        $this->repository->delete($invoiceId);
                    } else {
                        $this->repository->touch($invoiceId, $nowAt);
                    }
                    continue;
                }

                // Paid, Cancelled, Refunded, Collections ou fatura apagada: nada a fazer na geração.
                $this->repository->delete($invoiceId);
            } catch (\Throwable $e) {
                // Falha no tratamento (ex.: lock da fatura ocupado): tenta de novo no próximo cron,
                // com o mesmo teto de DRAFT_MAX_DAYS para não repetir para sempre.
                self::logError($invoiceId, $e);
                try {
                    $registeredAt = strtotime((string) ($row['registrado_em'] ?? ''));
                    if ($registeredAt !== false && $registeredAt < $draftCutoff) {
                        $this->repository->delete($invoiceId);
                    } else {
                        $this->repository->touch($invoiceId, $nowAt);
                    }
                } catch (\Throwable $ignored) {
                }
            }
        }

        return $handled;
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
                InvoiceCreatedHook::LOG_ERROR,
                json_encode(['invoiceid' => $invoiceId, 'origem' => 'conferencia_geracao'], JSON_UNESCAPED_UNICODE),
                $e->getMessage()
            );
        } catch (\Throwable $loggingError) {
            return;
        }
    }
}
