<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

/**
 * Neutraliza fórmulas em células de CSV (CSV/formula injection).
 *
 * Valor que começa com =, +, -, @, TAB ou CR ganha um apóstrofo na frente, para a planilha
 * tratá-lo como texto. Números simples (inclusive negativos) ficam como estão.
 */
final class CsvSanitizer
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = (string) $value;
        if ($text === '') {
            return '';
        }

        if (preg_match('/^-?\d+([.,]\d+)?$/', $text) === 1) {
            return $text;
        }

        if (in_array($text[0], self::DANGEROUS_PREFIXES, true)) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * @param array<int|string, mixed> $row
     * @return array<int|string, string>
     */
    public static function row(array $row): array
    {
        return array_map([self::class, 'cell'], $row);
    }
}
