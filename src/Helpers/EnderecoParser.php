<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

/**
 * Separa o número do imóvel da linha de endereço (address1 do WHMCS).
 *
 * Só considera número de imóvel o que vem depois do nome da rua e está marcado
 * como tal (", 541", "Nº 541", " - 541") ou o número no fim da linha ("RUA X 541").
 * Números que fazem parte do nome ("RUA 7 DE SETEMBRO") não são extraídos.
 */
final class EnderecoParser
{
    private const NUMERO_REGEX = '/(?<sep>\s*[,;]\s*|\s+-\s*|\s+)'
        . '(?:(?<pref>n[º°o]\.?|n\.|nr\.?|n[úu]m(?:ero|\.)?)\s*)?'
        . '(?<num>s\/n|sn|\d{1,6}[a-z]?)'
        . '(?=$|[\s,;\/-])/iu';

    /** Palavras que, antes de um número no fim da linha, indicam complemento e não número do imóvel. */
    private const PALAVRAS_COMPLEMENTO = [
        'km', 'bloco', 'bl', 'sala', 'sl', 'apto', 'apt', 'ap', 'casa', 'lote', 'lt',
        'quadra', 'qd', 'andar', 'conj', 'conjunto', 'box', 'loja', 'galpao', 'galpão',
    ];

    /**
     * @return array{0: string, 1: ?string, 2: string} [logradouro, numero|null, resto]
     */
    public static function separarNumero(string $linha): array
    {
        $linha = trim(preg_replace('/\s+/u', ' ', $linha) ?? $linha);
        if ($linha === '') {
            return ['', null, ''];
        }

        if (!preg_match_all(self::NUMERO_REGEX, $linha, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [$linha, null, ''];
        }

        $escolhido = null;
        foreach ($matches as $m) {
            $marcado = preg_match('/[,;-]/', $m['sep'][0]) === 1 || ($m['pref'][0] ?? '') !== '';
            if ($marcado) {
                $escolhido = $m;
                break;
            }
        }

        if ($escolhido === null) {
            $ultimo = $matches[count($matches) - 1];
            $fim = $ultimo[0][1] + strlen($ultimo[0][0]);
            if ($fim === strlen($linha) && !self::precedidoPorComplemento(substr($linha, 0, $ultimo[0][1]))) {
                $escolhido = $ultimo;
            }
        }

        if ($escolhido === null) {
            return [$linha, null, ''];
        }

        $inicio = $escolhido[0][1];
        $fim = $inicio + strlen($escolhido[0][0]);
        $logradouro = self::limparSeparadores(substr($linha, 0, $inicio));
        $resto = self::limparSeparadores(substr($linha, $fim));

        if ($logradouro === '' || !preg_match('/\p{L}/u', $logradouro)) {
            return [$linha, null, ''];
        }

        $numero = $escolhido['num'][0];
        $numero = preg_match('/^s\/?n$/i', $numero) ? 'S/N' : strtoupper($numero);

        return [$logradouro, $numero, $resto];
    }

    private static function precedidoPorComplemento(string $antes): bool
    {
        if (!preg_match('/(\p{L}+)\.?\s*$/u', $antes, $m)) {
            return false;
        }

        return in_array(mb_strtolower($m[1]), self::PALAVRAS_COMPLEMENTO, true);
    }

    private static function limparSeparadores(string $valor): string
    {
        return trim($valor, " \t,;-/");
    }
}
