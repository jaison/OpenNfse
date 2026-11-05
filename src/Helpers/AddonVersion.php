<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

/**
 * Versão do addon, lida do opennfse_config() em OpenNfse.php (fonte única).
 *
 * O arquivo é lido como texto: OpenNfse.php encerra fora do WHMCS e pode não estar
 * carregado no cron, nos hooks ou nos testes.
 */
final class AddonVersion
{
    public const VER_APLIC_PREFIX = 'whmcs-nfse/';
    // TSVerAplic do leiaute nacional: até 20 caracteres.
    public const VER_APLIC_MAX_LENGTH = 20;
    public const VER_APLIC_FALLBACK = 'whmcs-nfse';

    private static bool $loaded = false;
    private static ?string $version = null;

    /**
     * Versão declarada em OpenNfse.php, ou null se não der para ler.
     */
    public static function current(): ?string
    {
        if (self::$loaded) {
            return self::$version;
        }
        self::$loaded = true;

        $contents = @file_get_contents(dirname(__DIR__, 2) . '/OpenNfse.php');
        if (is_string($contents) && preg_match("/'version'\s*=>\s*'([^']+)'/", $contents, $matches)) {
            $version = trim((string) ($matches[1] ?? ''));
            self::$version = $version !== '' ? $version : null;
        }

        return self::$version;
    }

    /**
     * verAplic da DPS: "whmcs-nfse/<versão>", sempre com no máximo 20 caracteres.
     * Sem versão legível (ou com caracteres estranhos), usa só "whmcs-nfse".
     */
    public static function verAplic(?string $version = null): string
    {
        $version = trim((string) ($version ?? self::current()));
        if ($version === '' || !preg_match('/^[0-9A-Za-z.+-]+$/', $version)) {
            return self::VER_APLIC_FALLBACK;
        }

        return substr(self::VER_APLIC_PREFIX . $version, 0, self::VER_APLIC_MAX_LENGTH);
    }
}
