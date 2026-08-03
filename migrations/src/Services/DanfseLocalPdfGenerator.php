<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Exceptions\NfseModuleException;

final class DanfseLocalPdfGenerator
{
    public function generateFromXmlFile(string $xmlAbsPath): string
    {
        $xmlAbsPath = trim($xmlAbsPath);
        if ($xmlAbsPath === '' || !is_file($xmlAbsPath)) {
            throw new NfseModuleException('XML não encontrado para geração do PDF.');
        }

        if (!class_exists(\TCPDF::class)) {
            throw new NfseModuleException('Dependência tecnickcom/tcpdf não encontrada.');
        }

        $xml = file_get_contents($xmlAbsPath);
        if ($xml === false || trim($xml) === '') {
            throw new NfseModuleException('Falha ao ler XML para geração do PDF.');
        }

        $data = $this->extract($xml);

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('WHMCS NFS-e');
        $pdf->SetAuthor('WHMCS NFS-e');
        $pdf->SetTitle('DANFSe');
        $pdf->SetSubject('Documento Auxiliar da NFS-e');
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->AddPage();

        $title = 'DANFSe - Documento Auxiliar da NFS-e';
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, $title, 0, 1, 'C');
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', '', 9);
        $pdf->writeHTML($this->renderHtml($data), true, false, true, false, '');

        $bytes = (string) $pdf->Output('', 'S');
        if ($bytes === '') {
            throw new NfseModuleException('Falha ao gerar PDF: PDF vazio.');
        }
        return $bytes;
    }

    private function renderHtml(array $d): string
    {
        $h = '';
        $h .= '<table cellpadding="4" cellspacing="0" border="1" width="100%">';
        $h .= '<tr><td width="25%"><b>Número NFS-e</b></td><td width="75%">' . htmlspecialchars((string) ($d['numero_nfse'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '<tr><td><b>Chave de Acesso</b></td><td>' . htmlspecialchars((string) ($d['chave'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '<tr><td><b>Competência</b></td><td>' . htmlspecialchars((string) ($d['competencia'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '<tr><td><b>Emitida em</b></td><td>' . htmlspecialchars((string) ($d['emitida_em'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '</table>';
        $h .= '<br/>';

        $h .= '<table cellpadding="4" cellspacing="0" border="1" width="100%">';
        $h .= '<tr><td colspan="2"><b>Emitente</b></td></tr>';
        $h .= '<tr><td width="25%"><b>Documento</b></td><td width="75%">' . htmlspecialchars((string) ($d['emitente_doc'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '<tr><td><b>Nome</b></td><td>' . htmlspecialchars((string) ($d['emitente_nome'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '<tr><td><b>Endereço</b></td><td>' . htmlspecialchars((string) ($d['emitente_endereco'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '<tr><td><b>E-mail</b></td><td>' . htmlspecialchars((string) ($d['emitente_email'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '</table>';
        $h .= '<br/>';

        $h .= '<table cellpadding="4" cellspacing="0" border="1" width="100%">';
        $h .= '<tr><td colspan="2"><b>Tomador</b></td></tr>';
        $h .= '<tr><td width="25%"><b>Documento</b></td><td width="75%">' . htmlspecialchars((string) ($d['tomador_doc'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '<tr><td><b>Nome</b></td><td>' . htmlspecialchars((string) ($d['tomador_nome'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '<tr><td><b>Endereço</b></td><td>' . htmlspecialchars((string) ($d['tomador_endereco'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '<tr><td><b>E-mail</b></td><td>' . htmlspecialchars((string) ($d['tomador_email'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '</table>';
        $h .= '<br/>';

        $h .= '<table cellpadding="4" cellspacing="0" border="1" width="100%">';
        $h .= '<tr><td colspan="2"><b>Serviço</b></td></tr>';
        $h .= '<tr><td width="25%"><b>Descrição</b></td><td width="75%">' . htmlspecialchars((string) ($d['servico_desc'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '<tr><td><b>Valor Líquido</b></td><td>' . htmlspecialchars((string) ($d['valor_liq'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $h .= '</table>';
        return $h;
    }

    private function extract(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $ok = $doc->loadXML($xml, LIBXML_NONET);
            if (!$ok) {
                throw new NfseModuleException('XML inválido para geração do PDF.');
            }

            $xp = new \DOMXPath($doc);

            $getText = static function (\DOMXPath $xp, string $q): string {
                $n = $xp->query($q);
                if ($n && $n->length > 0) {
                    return trim((string) $n->item(0)?->textContent);
                }
                return '';
            };

            $getAttr = static function (\DOMXPath $xp, string $q, string $attr): string {
                $n = $xp->query($q);
                if ($n && $n->length > 0 && $n->item(0) instanceof \DOMElement) {
                    return trim((string) $n->item(0)->getAttribute($attr));
                }
                return '';
            };

            $chaveId = $getAttr($xp, '//*[local-name()="infNFSe"]', 'Id');
            $chave = preg_replace('/^NFS/', '', $chaveId);

            $numeroNfse = $getText($xp, '//*[local-name()="infNFSe"]/*[local-name()="nNFSe"]');
            $competencia = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="dCompet"]');
            $emitidaRaw = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="dhEmi"]');
            $emitidaEm = $this->formatIsoDateTime($emitidaRaw);

            $emitDoc = $getText($xp, '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="CNPJ"]');
            if ($emitDoc === '') {
                $emitDoc = $getText($xp, '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="CPF"]');
            }
            $emitNome = $getText($xp, '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="xNome"]');
            $emitEmail = $getText($xp, '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="email"]');
            $emitLgr = $getText($xp, '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="enderNac"]/*[local-name()="xLgr"]');
            $emitNro = $getText($xp, '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="enderNac"]/*[local-name()="nro"]');
            $emitBairro = $getText($xp, '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="enderNac"]/*[local-name()="xBairro"]');
            $emitCep = $getText($xp, '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="enderNac"]/*[local-name()="CEP"]');
            $emitEndereco = trim($emitLgr . ', ' . $emitNro . ($emitBairro !== '' ? ' - ' . $emitBairro : '') . ($emitCep !== '' ? ' CEP ' . $this->formatCep($emitCep) : ''));

            $tomaDoc = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="toma"]/*[local-name()="CNPJ"]');
            if ($tomaDoc === '') {
                $tomaDoc = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="toma"]/*[local-name()="CPF"]');
            }
            $tomaNome = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="toma"]/*[local-name()="xNome"]');
            $tomaEmail = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="toma"]/*[local-name()="email"]');
            $tomaLgr = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="toma"]/*[local-name()="end"]/*[local-name()="xLgr"]');
            $tomaNro = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="toma"]/*[local-name()="end"]/*[local-name()="nro"]');
            $tomaCpl = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="toma"]/*[local-name()="end"]/*[local-name()="xCpl"]');
            $tomaBairro = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="toma"]/*[local-name()="end"]/*[local-name()="xBairro"]');
            $tomaCep = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="toma"]/*[local-name()="end"]/*[local-name()="endNac"]/*[local-name()="CEP"]');
            $tomaEnderecoParts = [];
            if ($tomaLgr !== '') {
                $tomaEnderecoParts[] = $tomaLgr;
            }
            if ($tomaNro !== '' && preg_match('/\b' . preg_quote($tomaNro, '/') . '\b/', $tomaLgr) !== 1) {
                $tomaEnderecoParts[] = $tomaNro;
            }
            if ($tomaCpl !== '') {
                $tomaEnderecoParts[] = $tomaCpl;
            }
            if ($tomaBairro !== '') {
                $tomaEnderecoParts[] = $tomaBairro;
            }
            $tomaEndereco = implode(', ', $tomaEnderecoParts);
            if ($tomaCep !== '') {
                $tomaEndereco .= ($tomaEndereco !== '' ? ' - ' : '') . 'CEP ' . $this->formatCep($tomaCep);
            }

            $servDesc = $getText($xp, '//*[local-name()="infDPS"]/*[local-name()="serv"]/*[local-name()="cServ"]/*[local-name()="xDescServ"]');
            $vLiq = $getText($xp, '//*[local-name()="infNFSe"]/*[local-name()="valores"]/*[local-name()="vLiq"]');

            return [
                'chave' => $chave,
                'numero_nfse' => $numeroNfse,
                'competencia' => $competencia,
                'emitida_em' => $emitidaEm,
                'emitente_doc' => $this->formatCnpjCpf($emitDoc),
                'emitente_nome' => $emitNome,
                'emitente_endereco' => $emitEndereco,
                'emitente_email' => $emitEmail,
                'tomador_doc' => $this->formatCnpjCpf($tomaDoc),
                'tomador_nome' => $tomaNome,
                'tomador_endereco' => $tomaEndereco,
                'tomador_email' => $tomaEmail,
                'servico_desc' => $servDesc,
                'valor_liq' => $vLiq,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private function formatCnpjCpf(string $value): string
    {
        $value = preg_replace('/\D/', '', $value);
        if (strlen($value) === 14) {
            return substr($value, 0, 2) . '.' . substr($value, 2, 3) . '.' . substr($value, 5, 3) . '/' . substr($value, 8, 4) . '-' . substr($value, 12, 2);
        }
        if (strlen($value) === 11) {
            return substr($value, 0, 3) . '.' . substr($value, 3, 3) . '.' . substr($value, 6, 3) . '-' . substr($value, 9, 2);
        }
        return $value;
    }

    private function formatCep(string $value): string
    {
        $value = preg_replace('/\D/', '', $value);
        if (strlen($value) === 8) {
            return substr($value, 0, 5) . '-' . substr($value, 5, 3);
        }
        return $value;
    }

    private function formatIsoDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->format('d/m/Y H:i:s');
        } catch (\Throwable $e) {
            return $value;
        }
    }
}

