<?php

declare(strict_types=1);

namespace OpenNfse\Services;

/**
 * Grupo IBSCBS mínimo da NT 009. Desligado por padrão e sem efeito fora desse leiaute.
 */
final class IbscbsConfig
{
    public const CIND_OP_PADRAO = '100301';
    public const CST_PADRAO = '000';
    public const CCLASS_TRIB_PADRAO = '000001';
    public const IND_DEST_PADRAO = '0';

    public static function enabled(array $config): bool
    {
        return (string) ($config['ibscbs_enabled'] ?? '0') === '1';
    }

    /**
     * @return array<int, string>
     */
    public static function validate(string $enabled, string $cIndOp, string $cst, string $cClassTrib): array
    {
        $errors = [];
        if (!in_array($enabled, ['0', '1'], true)) {
            $errors[] = 'Opção inválida para IBS/CBS.';
        }
        if (preg_match('/^\d{6}$/', $cIndOp) !== 1) {
            $errors[] = 'cIndOp do IBS/CBS inválido (precisa ter 6 dígitos).';
        }
        if (preg_match('/^\d{3}$/', $cst) !== 1) {
            $errors[] = 'CST do IBS/CBS inválido (precisa ter 3 dígitos).';
        }
        if (preg_match('/^\d{6}$/', $cClassTrib) !== 1) {
            $errors[] = 'cClassTrib do IBS/CBS inválido (6 dígitos).';
        }

        return $errors;
    }

    /**
     * Grupo para o infDPS, ou null quando o IBS/CBS não entra no XML.
     * tpEnteGov e tpOper só entram juntos, quando o cliente é ente público.
     *
     * @return array<string, mixed>|null
     */
    public static function grupo(array $config, string $layout, ?int $ente = null, ?int $tpOper = null): ?array
    {
        if ($layout !== DpsLayoutService::LAYOUT_NT009 || !self::enabled($config)) {
            return null;
        }

        $codigos = self::codigos($config);
        $grupo = [
            'cIndOp' => $codigos['cIndOp'],
            'indDest' => (int) $codigos['indDest'],
            'valores' => [
                'trib' => [
                    'gIBSCBS' => [
                        'CST' => $codigos['cst'],
                        'cClassTrib' => $codigos['cClassTrib'],
                    ],
                ],
            ],
        ];
        if ($ente !== null) {
            $grupo['tpEnteGov'] = $ente;
            $grupo['tpOper'] = $tpOper ?? (int) CompraGovernamental::TPOPER_PADRAO;
        }

        return $grupo;
    }

    /**
     * @return array{cIndOp: string, cst: string, cClassTrib: string, indDest: string}
     */
    public static function codigos(array $config): array
    {
        $cIndOp = trim((string) ($config['ibscbs_cindop'] ?? ''));
        $cst = trim((string) ($config['ibscbs_cst'] ?? ''));
        $cClassTrib = trim((string) ($config['ibscbs_cclasstrib'] ?? ''));

        return [
            'cIndOp' => $cIndOp !== '' ? $cIndOp : self::CIND_OP_PADRAO,
            'cst' => $cst !== '' ? $cst : self::CST_PADRAO,
            'cClassTrib' => $cClassTrib !== '' ? $cClassTrib : self::CCLASS_TRIB_PADRAO,
            // indDest 1 exige o grupo dest, que esta versão não monta.
            'indDest' => self::IND_DEST_PADRAO,
        ];
    }
}
