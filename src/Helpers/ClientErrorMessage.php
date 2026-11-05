<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

/**
 * Texto do erro da NFS-e para a área do cliente. O admin continua vendo o erro_api completo.
 *
 * Só passa a rejeição da SEFIN que vem estruturada (JSON com código Exxxx e descrição),
 * montada a partir desses dois campos; o Complemento nunca aparece. Sem nenhum item
 * aproveitável, vira um texto genérico. O escape de HTML fica com quem exibe.
 */
final class ClientErrorMessage
{
    public const GENERIC = 'Não foi possível emitir a NFS-e desta fatura. Entre em contato com o suporte.';
    private const SUPPORT = 'Entre em contato com o suporte.';
    private const MAX_MESSAGES = 3;
    private const MAX_DESCRIPTION_LENGTH = 200;
    private const SENSITIVE_EXTENSIONS = 'pfx|p12|pem|key|crt|cer|der|csr|jks'
        . '|php|phtml|log|ini|env|conf|cfg|sql|bak|sh|yml|yaml';

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

        return 'Retorno da SEFIN: ' . implode('; ', $rejeicoes) . '. ' . self::SUPPORT;
    }

    /**
     * "Exxxx - descrição" de cada item com código e descrição. Item inseguro fica de fora.
     *
     * @return array<int, string>
     */
    private static function sefinRejections(string $erroApi): array
    {
        $out = [];
        foreach (self::jsonValues($erroApi) as $value) {
            foreach (self::sefinItems($value) as $item) {
                $codigo = trim((string) ($item['codigo'] ?? $item['Codigo'] ?? ''));
                $descricao = self::normalizeDescription($item['descricao'] ?? $item['Descricao'] ?? '');
                if (!preg_match('/^E\d{3,5}$/', $codigo) || $descricao === null) {
                    continue;
                }
                $out[$codigo . '|' . $descricao] = $codigo . ' - ' . $descricao;
                if (count($out) >= self::MAX_MESSAGES) {
                    return array_values($out);
                }
            }
        }

        return array_values($out);
    }

    /**
     * Trechos JSON (objeto ou lista) decodificados do texto. Os colchetes são casados
     * respeitando strings, então chaves dentro do Complemento não atrapalham.
     *
     * @return array<int, array<mixed>>
     */
    private static function jsonValues(string $text): array
    {
        $values = [];
        $text = substr($text, 0, 65536);
        $length = strlen($text);
        $pos = 0;
        while ($pos < $length) {
            $start = strcspn($text, '{[', $pos) + $pos;
            if ($start >= $length) {
                break;
            }
            $end = self::matchingBracket($text, $start);
            if ($end !== null) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
                if (is_array($decoded)) {
                    $values[] = $decoded;
                    $pos = $end + 1;
                    continue;
                }
            }
            $pos = $start + 1;
        }

        return $values;
    }

    private static function matchingBracket(string $text, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $length = strlen($text);
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Itens com código, em qualquer nível (ex.: {"erro":{"codigo":...}} ou [{"Codigo":...}]).
     *
     * @param array<mixed> $value
     * @return array<int, array<mixed>>
     */
    private static function sefinItems(array $value): array
    {
        if (isset($value['codigo']) || isset($value['Codigo'])) {
            return [$value];
        }

        $items = [];
        foreach ($value as $child) {
            if (is_array($child)) {
                $items = array_merge($items, self::sefinItems($child));
            }
        }

        return $items;
    }

    /**
     * Descrição numa linha só e com tamanho limitado; null se vazia ou insegura.
     */
    private static function normalizeDescription($descricao): ?string
    {
        if (!is_string($descricao)) {
            return null;
        }
        $descricao = trim((string) preg_replace('/[\r\n\t]+/u', ' ', $descricao));
        $descricao = rtrim($descricao, '. ');
        if ($descricao === '' || preg_match('/[\x00-\x1F\x7F]/u', $descricao) === 1) {
            return null;
        }
        // Arquivo com extensão sensível/técnica ou caminho com unidade do Windows.
        if (preg_match('/\.(' . self::SENSITIVE_EXTENSIONS . ')\b/iu', $descricao) === 1) {
            return null;
        }
        if (preg_match('/(^|[^A-Za-z])[A-Za-z]:\\\\/u', $descricao) === 1) {
            return null;
        }
        if (mb_strlen($descricao, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH) {
            $descricao = rtrim(mb_substr($descricao, 0, self::MAX_DESCRIPTION_LENGTH, 'UTF-8')) . '...';
        }

        return $descricao;
    }
}
