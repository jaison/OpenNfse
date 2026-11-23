<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

/**
 * JSON embutido em <script>. Substitui UTF-8 inválido e escapa caracteres
 * que fechariam a tag ou o atributo. Se a codificação falhar, devolve um literal vazio.
 */
final class ScriptJson
{
    public static function encode(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT);
        if (is_string($json)) {
            return $json;
        }

        return is_array($value) || is_object($value) ? '{}' : '""';
    }
}
