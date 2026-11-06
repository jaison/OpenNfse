<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Helpers\NfseXmlExtractor;

/**
 * Regras do E0014: a SEFIN recusa a DPS porque já existe uma NFS-e com a mesma série, número,
 * município e emissor. Acontece quando o primeiro envio foi autorizado mas a resposta não chegou
 * e a mesma DPS foi enviada de novo. A nota existente só é vinculada à fatura se for desta DPS
 * e tiver o mesmo tomador e o mesmo valor do serviço.
 */
final class DuplicateDpsRecovery
{
    public const LOG_TYPE = 'E0014_RECUPERACAO';

    public static function isDuplicateDpsError(?string $message): bool
    {
        return $message !== null && preg_match('/\bE0014\b/', $message) === 1;
    }

    /**
     * Confere a NFS-e encontrada contra a DPS enviada.
     *
     * @return string|null null quando confere; senão, o que não confere
     */
    public static function mismatchReason(string $nfseXml, string $idDps, string $dpsXml): ?string
    {
        $idNaNota = NfseXmlExtractor::extractIdDps($nfseXml);
        if ($idNaNota === null || $idNaNota !== $idDps) {
            return 'a NFS-e encontrada é de outra DPS';
        }

        $tomadorNota = NfseXmlExtractor::extractTomadorDocumento($nfseXml);
        $tomadorDps = NfseXmlExtractor::extractTomadorDocumento($dpsXml);
        if ($tomadorNota !== $tomadorDps) {
            return 'o tomador da NFS-e encontrada é outro';
        }

        $valorNota = NfseXmlExtractor::extractValorServico($nfseXml);
        $valorDps = NfseXmlExtractor::extractValorServico($dpsXml);
        if ($valorNota === null || $valorDps === null || abs($valorNota - $valorDps) >= 0.005) {
            return 'o valor do serviço da NFS-e encontrada é outro';
        }

        return null;
    }

    /**
     * Mensagem da nota quando a recuperação automática não pôde confirmar a NFS-e existente.
     */
    public static function unresolvedMessage(string $idDps, string $reason, string $apiError): string
    {
        $serie = '';
        $numero = '';
        // idDPS: "DPS" + município (7) + tipo de inscrição (1) + inscrição (14) + série (5) + número (15).
        if (preg_match('/^DPS\d{7}\d\d{14}(\d{5})(\d{15})$/', $idDps, $m) === 1) {
            $serie = (string) (int) $m[1];
            $numero = (string) (int) $m[2];
        }
        $dps = $serie !== '' ? sprintf(' (série %s, DPS nº %s)', $serie, $numero) : '';

        return sprintf(
            'A SEFIN já tem uma NFS-e para esta DPS%s, mas não foi possível vinculá-la a esta fatura: %s. '
            . 'Confira a nota no Emissor Nacional. Se for desta fatura, use Consultar Status. Não reemita, para não gerar outra nota. Detalhe: %s',
            $dps,
            $reason,
            $apiError
        );
    }
}
