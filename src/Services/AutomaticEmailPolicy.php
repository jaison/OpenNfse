<?php

declare(strict_types=1);

namespace OpenNfse\Services;

/**
 * Regras puras (sem banco) do envio automático do e-mail da NFS-e.
 * O controle é por nota: o e-mail automático sai uma vez para cada chave de acesso.
 */
final class AutomaticEmailPolicy
{
    public const SKIP_CONFIG_DISABLED = 'config_desligada';
    public const SKIP_ALREADY_SENT = 'ja_enviado_para_chave';
    public const SKIP_NOT_EMITIDA = 'nota_nao_emitida';
    public const SKIP_NO_KEY = 'nota_sem_chave';

    // Espera antes de cada nova tentativa depois de uma falha (15 min, 1 h, 6 h).
    public const RETRY_DELAYS_SECONDS = [900, 3600, 21600];

    // Novas tentativas automáticas depois da primeira.
    public const MAX_RETRIES = 3;

    // Total de tentativas automáticas: a primeira mais as novas tentativas.
    public const TOTAL_ATTEMPTS = self::MAX_RETRIES + 1;

    public static function chave(?array $nota): string
    {
        return $nota ? trim((string) ($nota['chave_acesso'] ?? '')) : '';
    }

    public static function isEmitidaComChave(?array $nota): bool
    {
        return $nota !== null
            && trim((string) ($nota['status'] ?? '')) === 'EMITIDA'
            && self::chave($nota) !== '';
    }

    /**
     * A nota passou a EMITIDA com chave nesta operação: antes não estava EMITIDA com a mesma chave.
     * Cobre a emissão, a consulta que conclui uma nota PROCESSANDO e a recuperação da chave de
     * uma nota EMITIDA sem chave. Consultar de novo uma nota já emitida não é transição.
     */
    public static function isEmissionTransition(?array $before, ?array $after): bool
    {
        if (!self::isEmitidaComChave($after)) {
            return false;
        }

        return !self::isEmitidaComChave($before) || self::chave($before) !== self::chave($after);
    }

    /**
     * Motivo para não disparar o e-mail automático, ou null quando o disparo pode seguir para o claim.
     */
    public static function skipReason(bool $enabled, ?array $nota): ?string
    {
        if (!$enabled) {
            return self::SKIP_CONFIG_DISABLED;
        }

        if ($nota === null || trim((string) ($nota['status'] ?? '')) !== 'EMITIDA') {
            return self::SKIP_NOT_EMITIDA;
        }

        $chave = self::chave($nota);
        if ($chave === '') {
            return self::SKIP_NO_KEY;
        }

        if (self::alreadyClaimedForKey($nota, $chave)) {
            return self::SKIP_ALREADY_SENT;
        }

        return null;
    }

    /**
     * O e-mail automático já foi disparado para esta chave (enviado, em envio ou falho).
     * A mesma regra está no WHERE do claim atômico (NotaRepository::claimAutomaticEmail).
     */
    public static function alreadyClaimedForKey(array $nota, string $chave): bool
    {
        $chaveEnviada = trim((string) ($nota['email_automatico_chave'] ?? ''));
        return $chave !== '' && $chaveEnviada === $chave;
    }

    /**
     * Quando fazer a próxima tentativa depois de uma falha, ou null quando as tentativas acabaram.
     *
     * @param int $attemptsMade tentativas já feitas, contando a que acabou de falhar
     */
    public static function nextRetryAt(int $attemptsMade, int $now): ?string
    {
        if ($attemptsMade < 1 || $attemptsMade > self::MAX_RETRIES) {
            return null;
        }

        return date('Y-m-d H:i:s', $now + self::RETRY_DELAYS_SECONDS[$attemptsMade - 1]);
    }
}
