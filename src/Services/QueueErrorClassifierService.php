<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Exceptions\NfseValidationException;

final class QueueErrorClassifierService
{
    public function isTransientApiOutage($error): bool
    {
        $message = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
        $message = mb_strtolower(trim($message), 'UTF-8');
        if ($message === '') {
            return false;
        }

        $transientPatterns = [
            '503',
            'service unavailable',
            'the service is unavailable',
            'timeout',
            'timed out',
            'curl error 28',
            'failed to connect',
            'connection refused',
            'connection reset',
            'could not resolve host',
            'temporarily unavailable',
        ];

        foreach ($transientPatterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    public function isRetryable($error): bool
    {
        if ($error instanceof NfseValidationException) {
            return false;
        }

        $message = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
        $message = mb_strtolower(trim($message), 'UTF-8');
        if ($message === '') {
            return true;
        }

        $nonRetryablePatterns = [
            'validação preventiva falhou',
            'não configurad',
            'nao configurad',
            'não informad',
            'nao informad',
            'inválid',
            'invalid',
            'desativad',
            'não encontrada',
            'nao encontrada',
            'não encontrado',
            'nao encontrado',
            'mínimo',
            'minimo',
            'máximo',
            'maximo',
            'diferentes',
            'mapeamento',
            'só pode',
            'so pode',
            'acesso negado',
            'sem chave',
            'sem id dps',
            'obrigatório',
            'obrigatorio',
            'rejeitad',
        ];

        foreach ($nonRetryablePatterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }
}
