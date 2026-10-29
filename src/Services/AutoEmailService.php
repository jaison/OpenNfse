<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Helpers\CorrelationIdGenerator;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;

/**
 * Envio automático do e-mail da NFS-e (XML e PDF), controlado por nota (chave de acesso).
 *
 * - onNotaEmitida(): ponto único de disparo, chamado pelo NfseService sempre que a nota passa a
 *   EMITIDA com chave (emissão pela fila ou direta, consulta pela fila, pelo cron ou manual).
 * - processRetries(): novas tentativas depois de falha, executadas pelo cron sob o lock do cron.
 *
 * Nenhum método lança exceção: uma falha de e-mail não pode derrubar a emissão nem mudar a fila.
 */
final class AutoEmailService
{
    private const RETRY_BATCH_LIMIT = 20;

    /**
     * @param bool $logAlreadySent false na rede de segurança da fila (QUEUE_SKIP_ALREADY_EMITIDA), onde
     *        "já enviado para esta chave" é o resultado esperado e o log seria só ruído
     */
    public function onNotaEmitida(int $invoiceId, ?string $correlationId = null, bool $logAlreadySent = true): void
    {
        try {
            $correlationId = $this->resolveCorrelationId($invoiceId, $correlationId);
            $notaRepo = new NotaRepository();
            $logRepo = new LogRepository();
            $nota = $notaRepo->findByInvoiceId($invoiceId);
            $notaId = $nota ? (int) ($nota['id'] ?? 0) : 0;
            $chave = AutomaticEmailPolicy::chave($nota);

            $reason = AutomaticEmailPolicy::skipReason($this->isEnabled(), $nota);
            if ($reason === null && !$notaRepo->claimAutomaticEmail($notaId, $chave)) {
                // Outro processo ganhou o claim (ou a nota mudou entre a leitura e o UPDATE).
                $reason = AutomaticEmailPolicy::skipReason(true, $notaRepo->findByInvoiceId($invoiceId))
                    ?? AutomaticEmailPolicy::SKIP_ALREADY_SENT;
            }

            if ($reason !== null) {
                if (!AutomaticEmailPolicy::shouldLogSkip($reason, $logAlreadySent)) {
                    return;
                }
                $logRepo->insert(
                    $notaId > 0 ? $notaId : null,
                    'EMAIL_NFSE_AUTO_SKIPPED',
                    json_encode(
                        ['invoiceid' => $invoiceId, 'chave_acesso' => $chave, 'motivo' => $reason],
                        JSON_UNESCAPED_UNICODE
                    ),
                    null,
                    $correlationId
                );
                return;
            }

            $this->send($invoiceId, $notaId, $chave, 1, $notaRepo, $logRepo, $correlationId);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Executa as novas tentativas vencidas. Com o envio automático desligado, nada é reenviado.
     */
    public function processRetries(int $limit = self::RETRY_BATCH_LIMIT): void
    {
        try {
            if (!$this->isEnabled()) {
                return;
            }

            $notaRepo = new NotaRepository();
            $logRepo = new LogRepository();
            $now = date('Y-m-d H:i:s');

            foreach ($notaRepo->findAutomaticEmailRetriesDue($now, $limit) as $row) {
                $invoiceId = (int) ($row['invoiceid'] ?? 0);
                $notaId = (int) ($row['id'] ?? 0);
                $chave = AutomaticEmailPolicy::chave($row);
                if ($invoiceId <= 0 || $notaId <= 0 || $chave === '') {
                    continue;
                }

                try {
                    if (!$notaRepo->claimAutomaticEmailRetry($notaId, $chave, $now)) {
                        continue;
                    }

                    $attempt = (int) ($row['email_automatico_tentativas'] ?? 0) + 1;
                    $this->send(
                        $invoiceId,
                        $notaId,
                        $chave,
                        $attempt,
                        $notaRepo,
                        $logRepo,
                        CorrelationIdGenerator::generate($invoiceId)
                    );
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private function send(
        int $invoiceId,
        int $notaId,
        string $chave,
        int $attempt,
        NotaRepository $notaRepo,
        LogRepository $logRepo,
        string $correlationId
    ): void {
        $context = [
            'invoiceid' => $invoiceId,
            'chave_acesso' => $chave,
            'tentativa' => $attempt,
            'total_tentativas' => AutomaticEmailPolicy::TOTAL_ATTEMPTS,
        ];

        try {
            (new InvoiceEmailService())->sendToClient($invoiceId);
            $notaRepo->markAutomaticEmailSent($notaId, $chave);
            $logRepo->insert(
                $notaId,
                'EMAIL_NFSE_AUTO_SENT',
                json_encode($context, JSON_UNESCAPED_UNICODE),
                null,
                $correlationId
            );
        } catch (\Throwable $e) {
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Falha no envio automático da NFS-e.';
            $nextRetryAt = AutomaticEmailPolicy::nextRetryAt($attempt, time());
            try {
                $notaRepo->markAutomaticEmailFailed($notaId, $chave, $nextRetryAt);
            } catch (\Throwable $ignored) {
            }
            try {
                $logRepo->insert(
                    $notaId,
                    'EMAIL_NFSE_AUTO_ERROR',
                    json_encode($context + ['proxima_tentativa_at' => $nextRetryAt], JSON_UNESCAPED_UNICODE),
                    $message,
                    $correlationId
                );
            } catch (\Throwable $ignored) {
            }
            try {
                $history = sprintf(
                    'Falha no envio automático do e-mail da NFS-e (tentativa %d de %d). Motivo: %s',
                    $attempt,
                    AutomaticEmailPolicy::TOTAL_ATTEMPTS,
                    $message
                );
                $history .= $nextRetryAt !== null
                    ? ' Nova tentativa automática a partir de ' . $nextRetryAt . '.'
                    : ' Tentativas automáticas encerradas; use o envio manual.';
                (new InvoiceHistoryService())->append($invoiceId, $history);
            } catch (\Throwable $ignored) {
            }
        }
    }

    private function isEnabled(): bool
    {
        $config = (new ConfigRepository())->get();
        return (string) ($config['auto_send_nfse_email'] ?? '0') === '1';
    }

    private function resolveCorrelationId(int $invoiceId, ?string $correlationId): string
    {
        $correlationId = trim((string) $correlationId);
        return $correlationId !== '' ? $correlationId : CorrelationIdGenerator::generate($invoiceId);
    }
}
