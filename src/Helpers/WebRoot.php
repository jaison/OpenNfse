<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

/**
 * Diz se um caminho fica dentro do webroot (DOCUMENT_ROOT ou a raiz do WHMCS).
 * Usado para o certificado A1 e para os arquivos sensíveis do atualizador.
 */
final class WebRoot
{
    /**
     * @param list<string>|null $webRoots raízes a considerar; null usa DOCUMENT_ROOT e ROOTDIR
     */
    public static function contains(string $path, ?array $webRoots = null): bool
    {
        $real = realpath($path);
        if ($real === false || $real === '') {
            return false;
        }

        foreach ($webRoots ?? self::defaultRoots() as $root) {
            $root = realpath($root);
            if (!is_string($root) || $root === '') {
                continue;
            }
            if ($real === $root || strpos($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function defaultRoots(): array
    {
        $roots = [];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $roots[] = (string) $_SERVER['DOCUMENT_ROOT'];
        }
        if (defined('ROOTDIR')) {
            $roots[] = (string) ROOTDIR;
        }

        return array_values(array_unique($roots));
    }
}
