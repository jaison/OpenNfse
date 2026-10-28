<?php

declare(strict_types=1);

namespace OpenNfse\Services;

/**
 * Regras puras de estado da fila (sem banco), usadas pelo reprocessar manual
 * e pela recuperação automática de itens presos em RUNNING.
 */
final class QueueStatePolicy
{
    // Novas tentativas automáticas depois da primeira (QueueService::processEmissaoBatch).
    public const MAX_TENTATIVAS = 5;

    // Total de tentativas automáticas de emissão: a primeira mais as novas tentativas.
    public const TOTAL_TENTATIVAS = self::MAX_TENTATIVAS + 1;

    // Tempo em RUNNING a partir do qual o item é considerado travado (processo morto).
    public const RUNNING_STALE_SECONDS = 900;

    /**
     * Momento em que o item entrou em RUNNING. O claim grava ultima_tentativa;
     * updated_at e created_at são apenas fallback para linhas antigas.
     */
    public static function runningSince(array $row): ?string
    {
        foreach (['ultima_tentativa', 'updated_at', 'created_at'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function isRunningStale(?string $since, int $now, int $threshold = self::RUNNING_STALE_SECONDS): bool
    {
        $since = trim((string) $since);
        if ($since === '') {
            return true;
        }

        $sinceTs = strtotime($since);
        if ($sinceTs === false) {
            return true;
        }

        return ($now - $sinceTs) > $threshold;
    }

    /**
     * Retorna a mensagem de bloqueio do "Reprocessar" para o item, ou null se for permitido.
     */
    public static function manualRetryBlockReason(array $row, int $now): ?string
    {
        $status = trim((string) ($row['status'] ?? ''));

        switch ($status) {
            case 'ERROR':
            case 'PENDING':
                return null;
            case 'WAIT_STATUS':
                return 'Este item está em WAIT_STATUS: a DPS já foi enviada e aguarda a consulta. '
                    . 'Use "Consultar agora" (botão Consultar) em vez de reprocessar.';
            case 'RUNNING':
                if (self::isRunningStale(self::runningSince($row), $now)) {
                    return null;
                }

                return 'Este item está em execução (RUNNING) desde ' . (string) self::runningSince($row) . '. '
                    . 'Reprocessar só é permitido quando ele está travado há mais de '
                    . (int) (self::RUNNING_STALE_SECONDS / 60) . ' minutos.';
            case 'DONE':
            case 'RESOLVIDO':
                return 'Este item está em status terminal (DONE/RESOLVIDO). '
                    . 'Reprocessar está bloqueado para evitar inconsistências e duplicidade.';
            default:
                return 'Reprocessar não é permitido para o status ' . ($status !== '' ? $status : '(vazio)') . '.';
        }
    }

    /**
     * Para onde volta um item travado em RUNNING.
     * - WAIT_STATUS se a nota já está PROCESSANDO com DPS (ou EMITIDA sem chave): a consulta resolve, sem nova DPS.
     * - ERROR se as tentativas automáticas já se esgotaram, para não repetir um processo que morre sempre.
     * - PENDING nos demais casos (o claim seguinte reavalia a nota antes de emitir).
     *
     * @param int $tentativas valor gravado na fila (já inclui a tentativa interrompida)
     */
    public static function recoveryTargetStatus(?array $nota, int $tentativas): string
    {
        $notaStatus = $nota ? trim((string) ($nota['status'] ?? '')) : '';
        $idDps = $nota ? trim((string) ($nota['id_dps'] ?? '')) : '';
        $chave = $nota ? trim((string) ($nota['chave_acesso'] ?? '')) : '';

        if ($idDps !== '' && ($notaStatus === 'PROCESSANDO' || ($notaStatus === 'EMITIDA' && $chave === ''))) {
            return 'WAIT_STATUS';
        }

        $notaEmitida = $notaStatus === 'EMITIDA' && $chave !== '';
        if (!$notaEmitida && $tentativas >= self::TOTAL_TENTATIVAS) {
            return 'ERROR';
        }

        return 'PENDING';
    }

    public static function attemptsLabel(int $tentativas): string
    {
        return 'Tentativa ' . max(0, $tentativas) . ' de ' . self::TOTAL_TENTATIVAS;
    }
}
