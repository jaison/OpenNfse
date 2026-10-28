<?php

declare(strict_types=1);

namespace OpenNfse\Services;

/**
 * Decide qual leiaute da DPS enviar: o anterior à NT 009 ou o da NT 009
 * (finNFSe em infDPS e xDescServ depois de cNBS).
 *
 * A NT 009 vale em homologação (produção restrita) desde 20/10/2026 e em
 * produção a partir de NT009_PRODUCAO_INICIO.
 */
final class DpsLayoutService
{
    public const MODE_AUTO = 'auto';
    public const MODE_PRE_NT009 = 'pre_nt009';
    public const MODE_NT009 = 'nt009';

    public const LAYOUT_PRE_NT009 = 'pre_nt009';
    public const LAYOUT_NT009 = 'nt009';

    /** Início da NT 009 em produção, no fuso abaixo. */
    public const NT009_PRODUCAO_INICIO = '2026-12-01 00:00:00';
    public const TIMEZONE = 'America/Sao_Paulo';

    /** finNFSe = 0: NFS-e regular (único valor do TSRTCFinNFSe). */
    public const FIN_NFSE_REGULAR = '0';

    /**
     * versao da DPS por leiaute. O anterior à NT 009 mantém 1.00, que é o
     * que a produção aceita hoje. A NT 009 só existe no pacote 1.01.
     */
    private const VERSAO_DPS = [
        self::LAYOUT_PRE_NT009 => '1.00',
        self::LAYOUT_NT009 => '1.01',
    ];

    /** @return array<string, string> */
    public static function modeOptions(): array
    {
        return [
            self::MODE_AUTO => 'Automático (NT 009 em homologação; em produção a partir de 01/12/2026)',
            self::MODE_PRE_NT009 => 'Pré-NT 009 (força o leiaute anterior)',
            self::MODE_NT009 => 'NT 009 (força o leiaute novo)',
        ];
    }

    public static function layoutLabel(string $layout): string
    {
        return $layout === self::LAYOUT_NT009 ? 'NT 009' : 'Pré-NT 009';
    }

    public static function isValidMode(string $mode): bool
    {
        return array_key_exists($mode, self::modeOptions());
    }

    public static function normalizeMode(?string $mode): string
    {
        $mode = trim((string) $mode);

        return self::isValidMode($mode) ? $mode : self::MODE_AUTO;
    }

    public static function productionStart(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NT009_PRODUCAO_INICIO, new \DateTimeZone(self::TIMEZONE));
    }

    public function resolve(string $mode, string $environment, \DateTimeInterface $now): string
    {
        $mode = self::normalizeMode($mode);
        if ($mode === self::MODE_PRE_NT009) {
            return self::LAYOUT_PRE_NT009;
        }
        if ($mode === self::MODE_NT009) {
            return self::LAYOUT_NT009;
        }

        if ($environment !== 'producao') {
            return self::LAYOUT_NT009;
        }

        return $now >= self::productionStart() ? self::LAYOUT_NT009 : self::LAYOUT_PRE_NT009;
    }

    public function effectiveLayout(array $config, ?\DateTimeInterface $now = null): string
    {
        return $this->resolve(
            (string) ($config['dps_leiaute'] ?? self::MODE_AUTO),
            (string) ($config['environment'] ?? 'homologacao'),
            $now ?? new \DateTimeImmutable('now')
        );
    }

    public function versaoDps(string $layout): string
    {
        return self::VERSAO_DPS[$layout] ?? self::VERSAO_DPS[self::LAYOUT_PRE_NT009];
    }

    /**
     * Texto curto para a tela de configuração.
     */
    public function describe(array $config, ?\DateTimeInterface $now = null): string
    {
        $mode = self::normalizeMode((string) ($config['dps_leiaute'] ?? self::MODE_AUTO));
        $layout = $this->effectiveLayout($config, $now);
        $label = self::layoutLabel($layout);

        if ($mode !== self::MODE_AUTO) {
            return $label . ' (forçado na configuração)';
        }

        $environment = (string) ($config['environment'] ?? 'homologacao');
        if ($environment !== 'producao') {
            return $label . ' (automático: homologação)';
        }

        if ($layout === self::LAYOUT_NT009) {
            return $label . ' (automático: produção desde ' . self::productionStart()->format('d/m/Y H:i') . ')';
        }

        return $label . ' (automático: produção muda para NT 009 em '
            . self::productionStart()->format('d/m/Y H:i') . ')';
    }

    /**
     * Mensagem para rejeição E1235 causada por leiaute, ou null se a
     * rejeição não for desse tipo.
     */
    public static function layoutRejectionHint(string $errorMessage, string $layoutEnviado): ?string
    {
        if (stripos($errorMessage, 'E1235') === false) {
            return null;
        }
        if (stripos($errorMessage, 'finNFSe') === false && stripos($errorMessage, 'cLocEmi') === false) {
            return null;
        }

        $sugerido = $layoutEnviado === self::LAYOUT_NT009 ? self::LAYOUT_PRE_NT009 : self::LAYOUT_NT009;

        return sprintf(
            'Rejeição de leiaute da DPS (E1235): foi enviado o leiaute %s e a SEFIN espera o leiaute %s. '
            . 'Ajuste "Leiaute da DPS" em Configurações > Ambiente e reenvie a nota.',
            self::layoutLabel($layoutEnviado),
            self::layoutLabel($sugerido)
        );
    }
}
