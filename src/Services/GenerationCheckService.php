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
 * Reserva da emissão na geração para os caminhos sem hook útil (ex.: "Publish" sem e-mail no
 * admin, observado no WHMCS 9, que só chama InvoiceCreation com a fatura ainda Draft).
 *
 * O hook InvoiceCreation registra a fatura, com a primeira conferência marcada para
 * INTERVAL_SECONDS depois; o caminho imediato (InvoiceCreated ou InvoiceCreationPreEmail) apaga
 * o registro ao tratá-la; o cron trata as conferências vencidas:
 * - Unpaid: roda o mesmo tratamento da geração e apaga;
 * - Draft: confere de novo em INTERVAL_SECONDS (apaga sem ação depois de MAX_DAYS);
 * - outro status (Paid, Cancelled...) ou fatura inexistente: apaga sem ação (o pagamento cuida do Paid);
 * - já tratada (item na fila em qualquer status, ou nota EMITIDA/PROCESSANDO): apaga em silêncio;
 * - falha no tratamento: tenta de novo com intervalo crescente (BACKOFF_SECONDS), loga na 1ª falha
 *   e depois no máximo 1 vez por dia, até MAX_DAYS.
 */
final class GenerationCheckService
{
    public const BATCH_LIMIT = 200;
    public const MAX_DAYS = 30;

    /** Intervalo da primeira conferência e entre conferências de rascunho. */
    public const INTERVAL_SECONDS = 300;

    /** Intervalo depois da 1ª, 2ª, 3ª, 4ª falha; da 5ª em diante, o último (1 vez por dia). */
    public const BACKOFF_SECONDS = [300, 900, 3600, 21600, 86400];

    public const ERROR_LOG_INTERVAL_SECONDS = 86400;

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

    /**
     * Intervalo até a próxima tentativa depois de $attempts falhas (1 = primeira falha).
     */
    public static function backoffSeconds(int $attempts): int
    {
        $index = max(1, $attempts) - 1;
        $last = count(self::BACKOFF_SECONDS) - 1;

        return self::BACKOFF_SECONDS[min($index, $last)];
    }

    public function register(int $invoiceId, ?int $now = null): void
    {
        if ($invoiceId <= 0) {
            return;
        }

        $now ??= time();
        $at = date('Y-m-d H:i:s', $now);
        $dueAt = date('Y-m-d H:i:s', $now + self::INTERVAL_SECONDS);
        try {
            $this->repository->register($invoiceId, $at, $dueAt);
        } catch (\Throwable $e) {
            if (!self::isMissingTableError($e)) {
                throw $e;
            }
            ($this->ensureSchema)();
            $this->repository->register($invoiceId, $at, $dueAt);
        }
    }

    /** Se a fatura tem conferência registrada (o InvoiceCreation passou por ela). */
    public function isPending(int $invoiceId): bool
    {
        if ($invoiceId <= 0) {
            return false;
        }

        try {
            return $this->repository->exists($invoiceId);
        } catch (\Throwable $e) {
            if (self::isMissingTableError($e)) {
                return false;
            }
            throw $e;
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
     * Trata as conferências vencidas. Sai sem consultar nada se a emissão na geração não pode
     * valer (fila desligada ou nenhuma regra de geração). Devolve quantas faturas Unpaid passaram
     * pelo tratamento da geração.
     */
    public function processPending(array $config, ?int $now = null, int $limit = self::BATCH_LIMIT): int
    {
        if (!InvoiceCreatedHook::onCreateMayApply($config)) {
            return 0;
        }

        $now ??= time();
        $nowAt = date('Y-m-d H:i:s', $now);
        $expireBefore = $now - (self::MAX_DAYS * 86400);
        $handled = 0;

        try {
            $rows = $this->repository->listDue($nowAt, $limit);
        } catch (\Throwable $e) {
            if (self::isMissingTableError($e)) {
                return 0;
            }
            throw $e;
        }

        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }
            $registeredAt = strtotime((string) ($row['registrado_em'] ?? ''));
            $expired = $registeredAt !== false && $registeredAt < $expireBefore;

            try {
                if (($this->alreadyHandled)($invoiceId)) {
                    $this->repository->delete($invoiceId);
                    continue;
                }

                // Registrada há mais de MAX_DAYS: apaga sem ação, qualquer que seja o status. Um
                // rascunho antigo publicado depois não é emitido na geração (segue a regra do pagamento).
                if ($expired) {
                    $this->repository->delete($invoiceId);
                    continue;
                }

                $status = ($this->statusReader)($invoiceId);
                $status = $status === null ? null : strtolower(trim($status));

                if ($status === 'unpaid' && ($this->handler)($invoiceId, $config)) {
                    $handled++;
                    $this->repository->delete($invoiceId);
                    continue;
                }

                if ($status === 'draft' || $status === 'unpaid') {
                    // Rascunho (ou o tratamento ainda viu rascunho): confere de novo no intervalo normal.
                    $this->repository->reschedule($invoiceId, [
                        'verificado_em' => $nowAt,
                        'proxima_em' => date('Y-m-d H:i:s', $now + self::INTERVAL_SECONDS),
                    ]);
                    continue;
                }

                // Paid, Cancelled, Refunded, Collections ou fatura apagada: nada a fazer na geração.
                $this->repository->delete($invoiceId);
            } catch (\Throwable $e) {
                $this->handleFailure($invoiceId, $row, $e, $now, $expired);
            }
        }

        return $handled;
    }

    /**
     * Falha no tratamento (ex.: lock da fatura ocupado, erro persistente): tenta de novo com
     * intervalo crescente. O log sai na 1ª falha e depois no máximo 1 vez por dia por fatura.
     */
    private function handleFailure(int $invoiceId, array $row, \Throwable $e, int $now, bool $expired): void
    {
        $attempts = (int) ($row['tentativas'] ?? 0) + 1;
        $lastLogged = strtotime((string) ($row['erro_logado_em'] ?? ''));
        $shouldLog = $lastLogged === false || ($now - $lastLogged) >= self::ERROR_LOG_INTERVAL_SECONDS;
        if ($shouldLog) {
            self::logError($invoiceId, $attempts, $e);
        }

        try {
            if ($expired) {
                $this->repository->delete($invoiceId);
                return;
            }
            $fields = [
                'verificado_em' => date('Y-m-d H:i:s', $now),
                'proxima_em' => date('Y-m-d H:i:s', $now + self::backoffSeconds($attempts)),
                'tentativas' => $attempts,
            ];
            if ($shouldLog) {
                $fields['erro_logado_em'] = date('Y-m-d H:i:s', $now);
            }
            $this->repository->reschedule($invoiceId, $fields);
        } catch (\Throwable $ignored) {
        }
    }

    private static function isMissingTableError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return stripos($message, 'Base table or view not found') !== false || stripos($message, "doesn't exist") !== false;
    }

    private static function logError(int $invoiceId, int $attempts, \Throwable $e): void
    {
        try {
            (new LogRepository())->insert(
                null,
                InvoiceCreatedHook::LOG_ERROR,
                json_encode(
                    ['invoiceid' => $invoiceId, 'origem' => 'conferencia_geracao', 'tentativas' => $attempts],
                    JSON_UNESCAPED_UNICODE
                ),
                $e->getMessage()
            );
        } catch (\Throwable $loggingError) {
            return;
        }
    }
}
