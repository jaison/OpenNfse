<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

final class NameNormalizer
{
    public static function normalize(string $nome): string
    {
        $nome = mb_strtolower($nome, 'UTF-8');
        $nome = preg_replace('/[áàãâä]/u', 'a', $nome);
        $nome = preg_replace('/[éèêë]/u', 'e', $nome);
        $nome = preg_replace('/[íìîï]/u', 'i', $nome);
        $nome = preg_replace('/[óòõôö]/u', 'o', $nome);
        $nome = preg_replace('/[úùûü]/u', 'u', $nome);
        $nome = preg_replace('/ç/u', 'c', $nome);
        $nome = preg_replace('/[^a-z\s]/u', '', $nome);
        $nome = trim((string) preg_replace('/\s+/', ' ', $nome));
        return $nome;
    }
}

