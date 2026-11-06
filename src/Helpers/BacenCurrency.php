<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

/**
 * Código da moeda na tabela de moedas do Banco Central (usado em serv/comExt/tpMoeda).
 * Não é o código numérico ISO 4217: o Real é 790 (ISO 986) e o dólar dos EUA é 220 (ISO 840).
 */
final class BacenCurrency
{
    public const REAL = '790';

    /** @var array<string, string> código ISO (letras) => código do Banco Central */
    private const CODES = [
        'BRL' => '790',
        'USD' => '220',
        'EUR' => '978',
        'GBP' => '540',
        'ARS' => '706',
        'CAD' => '165',
        'AUD' => '150',
        'CHF' => '425',
        'JPY' => '470',
        'CNY' => '795',
        'MXN' => '741',
        'CLP' => '715',
        'COP' => '720',
        'SEK' => '070',
        'NOK' => '065',
        'DKK' => '055',
        'NZD' => '245',
    ];

    /**
     * Código do Banco Central para o código da moeda do WHMCS.
     * Vazio vira Real; três dígitos são aceitos como já sendo o código do Banco Central;
     * moeda fora da tabela devolve null.
     */
    public static function fromIso(string $iso): ?string
    {
        $iso = strtoupper(trim($iso));
        if ($iso === '') {
            return self::REAL;
        }
        if (preg_match('/^\d{3}$/', $iso) === 1) {
            return $iso;
        }

        return self::CODES[$iso] ?? null;
    }
}
