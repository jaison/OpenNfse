<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

/**
 * Texto do erro da NFS-e para a área do cliente. O admin continua vendo o erro_api completo.
 *
 * Só passa a rejeição da SEFIN que vem estruturada (objeto JSON com código Exxxx e descrição),
 * montada a partir desses dois campos. Qualquer outra coisa vira um texto genérico.
 */
final class ClientErrorMessage
{
    public const GENERIC = 'Não foi possível emitir a NFS-e desta fatura. Entre em contato com o suporte.';
    private const SUPPORT = 'Entre em contato com o suporte.';
    private const MAX_MESSAGES = 3;
    private const MAX_DESCRIPTION_LENGTH = 200;

    public static function forClient(?string $erroApi): string
    {
        $erroApi = trim((string) $erroApi);
        if ($erroApi === '') {
            return '';
        }

        $rejeicoes = self::sefinRejections($erroApi);
        if ($rejeicoes === []) {
            return self::GENERIC;
        }

        return 'NFS-e rejeitada pela SEFIN: ' . implode('; ', $rejeicoes) . '. ' . self::SUPPORT;
    }

    /**
     * "Exxxx - descrição" de cada objeto JSON plano com código e descrição. Vazio se algum
     * item parecer inseguro (caminho de arquivo, HTML).
     *
     * @return array<int, string>
     */
    private static function sefinRejections(string $erroApi): array
    {
        if (preg_match_all('/\{[^{}]*\}/u', $erroApi, $matches) < 1) {
            return [];
        }

        $out = [];
        foreach ($matches[0] as $json) {
            $data = json_decode($json, true);
            if (!is_array($data)) {
                continue;
            }
            $codigo = trim((string) ($data['codigo'] ?? $data['Codigo'] ?? ''));
            $descricao = trim((string) ($data['descricao'] ?? $data['Descricao'] ?? ''));
            if (!preg_match('/^E\d{3,5}$/', $codigo) || $descricao === '') {
                continue;
            }
            if (!self::isSafeDescription($descricao)) {
                return [];
            }
            if (mb_strlen($descricao, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH) {
                $descricao = rtrim(mb_substr($descricao, 0, self::MAX_DESCRIPTION_LENGTH, 'UTF-8')) . '...';
            }
            $out[$codigo . '|' . $descricao] = $codigo . ' - ' . rtrim($descricao, '. ');
            if (count($out) >= self::MAX_MESSAGES) {
                break;
            }
        }

        return array_values($out);
    }

    private static function isSafeDescription(string $descricao): bool
    {
        if (preg_match('/[\x00-\x1F\x7F<>]/u', $descricao) === 1) {
            return false;
        }
        // Caminho absoluto (Unix ou Windows) ou arquivo de certificado.
        if (preg_match('#(^|[\s:="\'(])(/[^\s/]+/|[A-Za-z]:\\\\)#u', $descricao) === 1) {
            return false;
        }

        return preg_match('/\.(pfx|p12|pem|key|php)\b/iu', $descricao) !== 1;
    }
}
