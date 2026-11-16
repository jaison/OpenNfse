<?php

declare(strict_types=1);

namespace OpenNfse\Services;

/**
 * Emissão com resposta incerta da SEFIN (E999 ou HTTP 500).
 *
 * A NFS-e pode ter sido autorizada mesmo com esse erro. A consulta da DPS decide:
 * confere e vincula, não confere e rejeita, ou ainda não dá para saber e fica em processamento.
 */
final class UncertainEmissionRecovery
{
    public const LOG_TYPE = 'E999_RECUPERACAO';

    public const PENDING_MESSAGE = 'SEFIN retornou erro não catalogado; a DPS será consultada novamente';

    public const STATUS_EMITIDA = 'emitida';

    public const STATUS_REJEITADA = 'rejeitada';

    public const STATUS_PROCESSANDO = 'processando';

    public static function isUncertain(?string $message, ?string $rawResponse = null, ?int $httpStatus = null): bool
    {
        if ($httpStatus === 500) {
            return true;
        }

        $text = trim((string) $message . "\n" . (string) $rawResponse);
        if ($text === '') {
            return false;
        }

        if (preg_match('/\bE999\b/', $text) === 1) {
            return true;
        }

        return preg_match('/\b500 Internal Server Error\b/i', $text) === 1;
    }

    /**
     * @param array{chave?: string, nfseXml?: string, reason?: string} $outcome
     */
    public static function statusFor(array $outcome): string
    {
        $chave = trim((string) ($outcome['chave'] ?? ''));
        $nfseXml = trim((string) ($outcome['nfseXml'] ?? ''));
        if ($chave !== '' && $nfseXml !== '') {
            return self::STATUS_EMITIDA;
        }

        $reason = trim((string) ($outcome['reason'] ?? ''));
        if ($reason !== '' && DuplicateDpsRecovery::isBindingMismatch($reason)) {
            return self::STATUS_REJEITADA;
        }

        return self::STATUS_PROCESSANDO;
    }
}
