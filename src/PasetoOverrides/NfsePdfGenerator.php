<?php

namespace NfsePdf;

use TCPDF;

class NfsePdfGenerator
{
    private $pdf;
    private $data;
    private $margin = 5;
    private $logoSvg = null;
    private $headerInfo = [
        'municipalityLine' => null,
        'secretariatLine'  => null,
        'phoneLine'        => null,
        'emailLine'        => null,
    ];
    private $showHomologationWarning = false;

    public function __construct()
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('NFS-e PDF Generator');
        $this->pdf->SetAuthor('NFS-e System');
        $this->pdf->SetTitle('DANFSe');
        $this->pdf->SetSubject('Documento Auxiliar da NFS-e');
        if (method_exists($this->pdf, 'setPrintHeader')) {
            $this->pdf->setPrintHeader(false);
        } else {
            $this->pdf->SetPrintHeader(false);
        }
        if (method_exists($this->pdf, 'setPrintFooter')) {
            $this->pdf->setPrintFooter(false);
        } else {
            $this->pdf->SetPrintFooter(false);
        }
        $this->pdf->SetMargins($this->margin, $this->margin, $this->margin);
        $this->pdf->SetAutoPageBreak(true, $this->margin);
        $this->pdf->SetFont('helvetica', '', 8);
    }

    public function setLogoSvg(string $svgContent)
    {
        $this->logoSvg = $svgContent;

        return $this;
    }

    public function setHeaderInfo(array $headerInfo)
    {
        $this->headerInfo = array_merge($this->headerInfo, $headerInfo);

        return $this;
    }

    public function setHomologationWarning(bool $show)
    {
        $this->showHomologationWarning = $show;

        return $this;
    }

    public function parseXml($xmlFile)
    {
        $content = @file_get_contents((string) $xmlFile);
        if ($content === false || $content === '') {
            throw new \Exception('Failed to read XML file');
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
            if ($xml === false) {
                throw new \Exception('Failed to parse XML file');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        $ns = $xml->getNamespaces(true);
        $infNFSe = $xml->children($ns[''])->infNFSe;
        $dps = $infNFSe->children($ns[''])->DPS->children($ns[''])->infDPS;

        $toma = $dps->toma;
        if (!isset($toma->xNome)) {
            $toma = $infNFSe->toma;
        }

        $id = (string)$infNFSe->attributes()->Id;
        $chaveAcesso = preg_replace('/^NFS/', '', $id);

        $tomadorDoc = (string) $toma->CNPJ;
        if ($tomadorDoc === '') {
            $tomadorDoc = (string) $toma->CPF;
        }
        if ($tomadorDoc === '') {
            $tomadorDoc = (string) $toma->NIF;
        }

        $tomadorEnd = $toma->end;
        $tomadorEndNac = $toma->end->endNac;
        if (!isset($tomadorEndNac->CEP)) {
            $tomadorEndNac = $toma->enderNac;
        }
        if (!isset($tomadorEndNac->CEP)) {
            $tomadorEndNac = $toma->endNac;
        }
        if (!isset($tomadorEndNac->CEP)) {
            $tomadorEndNac = $toma->end->enderNac;
        }

        $tomadorLogradouro = (string) $tomadorEnd->xLgr;
        if ($tomadorLogradouro === '') {
            $tomadorLogradouro = (string) $tomadorEndNac->xLgr;
        }
        $tomadorNumero = (string) $tomadorEnd->nro;
        if ($tomadorNumero === '') {
            $tomadorNumero = (string) $tomadorEndNac->nro;
        }
        $tomadorComplemento = (string) $tomadorEnd->xCpl;
        if ($tomadorComplemento === '') {
            $tomadorComplemento = (string) $tomadorEndNac->xCpl;
        }
        $tomadorBairro = (string) $tomadorEnd->xBairro;
        if ($tomadorBairro === '') {
            $tomadorBairro = (string) $tomadorEndNac->xBairro;
        }
        $tomadorMun = (string) $tomadorEndNac->cMun;
        if ($tomadorMun === '') {
            $tomadorMun = (string) $tomadorEnd->cMun;
        }
        $tomadorCep = (string) $tomadorEndNac->CEP;
        if ($tomadorCep === '') {
            $tomadorCep = (string) $tomadorEnd->CEP;
        }

        $this->data = [
            'chaveAcesso' => $chaveAcesso,
            'numeroNfse' => (string)$infNFSe->nNFSe,
            'localEmissao' => (string)$infNFSe->xLocEmi,
            'localPrestacao' => (string)$infNFSe->xLocPrestacao,
            'localIncidencia' => (string)$infNFSe->xLocIncid,
            'tribNac' => (string)$infNFSe->xTribNac,
            'dataProcessamento' => $this->formatDateTime((string)$infNFSe->dhProc),
            'numeroDFSe' => (string)$infNFSe->nDFSe,
            'emitente' => [
                'cnpj' => $this->formatCnpjCpf((string)$infNFSe->emit->CNPJ),
                'nome' => (string)$infNFSe->emit->xNome,
                'logradouro' => (string)$infNFSe->emit->enderNac->xLgr,
                'numero' => (string)$infNFSe->emit->enderNac->nro,
                'bairro' => (string)$infNFSe->emit->enderNac->xBairro,
                'municipio' => (string)$infNFSe->emit->enderNac->cMun,
                'uf' => (string)$infNFSe->emit->enderNac->UF,
                'cep' => $this->formatCep((string)$infNFSe->emit->enderNac->CEP),
                'fone' => $this->formatPhone((string)$infNFSe->emit->fone),
                'email' => (string)$infNFSe->emit->email,
            ],
            'tomador' => [
                'cnpj' => $this->formatCnpjCpf($tomadorDoc),
                'nome' => (string)$toma->xNome,
                'logradouro' => $tomadorLogradouro,
                'numero' => $tomadorNumero,
                'complemento' => $tomadorComplemento,
                'bairro' => $tomadorBairro,
                'municipio' => $tomadorMun,
                'cep' => $this->formatCep($tomadorCep),
                'email' => (string)$toma->email,
                'fone' => $this->formatPhone((string)$toma->fone),
            ],
            'servico' => [
                'codTribNac' => (string)$dps->serv->cServ->cTribNac,
                'descricao' => (string)$dps->serv->cServ->xDescServ,
            ],
            'valores' => [
                'valorServico' => (float)$dps->valores->vServPrest->vServ,
                'valorLiquido' => (float)$infNFSe->valores->vLiq,
                'valorTotalRet' => (float)$infNFSe->valores->vTotalRet,
            ],
            'dps' => [
                'numero' => (string)$dps->nDPS,
                'serie' => (string)$dps->serie,
                'competencia' => $this->formatDate((string)$dps->dCompet),
                'dataEmissao' => $this->formatDateTime((string)$dps->dhEmi),
            ],
            'tributacao' => [
                'tribISSQN' => (string)$dps->valores->trib->tribMun->tribISSQN,
                'tpRetISSQN' => (string)$dps->valores->trib->tribMun->tpRetISSQN,
                'totTribFed' => (float)$dps->valores->trib->totTrib->pTotTrib->pTotTribFed,
                'totTribEst' => (float)$dps->valores->trib->totTrib->pTotTrib->pTotTribEst,
                'totTribMun' => (float)$dps->valores->trib->totTrib->pTotTrib->pTotTribMun,
            ],
        ];

        return $this;
    }

    public function generate()
    {
        $this->pdf->AddPage();
        $this->addHeader();
        $this->addHorizontalLine();
        $this->addDadosNfse();
        $this->addHorizontalLine();
        $this->addEmitente();
        $this->addHorizontalLine();
        $this->addTomador();
        $this->addHorizontalLine(false);
        $this->addServico();
        $this->addHorizontalLine();
        $this->addTributacao();
        $this->addHorizontalLine();
        $this->addValores();
        $this->addHorizontalLine();
        $this->addTotaisTributos();
        $this->drawDocumentBorder();

        return $this->pdf;
    }

    private function drawDocumentBorder()
    {
        $pageWidth = 210;
        $pageHeight = 297;

        $x1 = $this->margin-3;
        $y1 = $this->margin-3;
        $width = $pageWidth - (2 * $this->margin-5);
        $height = $pageHeight - (2 * $this->margin-5);

        $this->pdf->SetLineWidth(0.1);
        $this->pdf->Rect($x1, $y1, $width, $height, 'D');
    }

    private function addHorizontalLine($addLineHeight = true)
    {
        $y = $this->pdf->GetY();
        $pageWidth = 210;
        $rightEdge = $pageWidth - $this->margin;
        $this->pdf->Line($this->margin, $y, $rightEdge, $y);
        if ($addLineHeight) {
            $this->pdf->Ln(2);
        }
    }

    private function addHeader()
    {
        $startY = $this->pdf->GetY();

        $logoPath = dirname(__DIR__, 2) . '/vendor/paseto/nfse-nacional-pdf/assets/logo-nfse-assinatura-horizontal.png';
        if (file_exists($logoPath)) {
            $this->pdf->Image($logoPath, $this->margin, $startY, 50, 0, 'PNG', '', '', false, 300, '', false, false, 0, false, false, false);
        }

        $centerX = 62;
        $this->pdf->SetXY($centerX, $startY);
        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->pdf->Cell(50, 4, 'DANFSe v1.0', 0, 0, 'C');
        $this->pdf->SetXY($centerX, $startY + 4);
        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->pdf->Cell(50, 4, 'Documento Auxiliar da NFS-e', 0, 0, 'C');
        if ($this->showHomologationWarning) {
            $this->pdf->SetXY(47, $startY + 8);
            $this->pdf->SetTextColor(200, 0, 0);
            $this->pdf->SetFont('helvetica', 'B', 9);
            $this->pdf->Cell(80, 4, 'NFS-e SEM VALIDADE JURÍDICA', 0, 0, 'C');
            $this->pdf->SetTextColor(0, 0, 0);
        }

        $rightX        = 137;
        $blockWidth    = 55;
        $logoWidth     = 8;
        $gap           = 2;
        $textBlockWidth = $blockWidth - $logoWidth - $gap;

        $local = (string) ($this->data['localEmissao'] ?? '');
        $localNorm = function_exists('mb_strtolower') ? mb_strtolower($local, 'UTF-8') : strtolower($local);
        $isItajai = in_array($localNorm, ['itajai', 'itajaí'], true);
        if ($isItajai && empty($this->logoSvg)) {
            $svgPath = dirname(__DIR__, 2) . '/assets/brasao_itajai.svg';
            if (is_file($svgPath)) {
                $svg = file_get_contents($svgPath);
                if (is_string($svg) && trim($svg) !== '') {
                    $this->logoSvg = $svg;
                }
            }
        }

        if (!empty($this->logoSvg)) {
            $logoX = $rightX;
            $this->pdf->ImageSVG('@' . $this->logoSvg, $logoX, $startY, $logoWidth, '', '', '', 0, false);
        }

        $textX = $rightX + $logoWidth + $gap;
        $municipalityLine = $this->headerInfo['municipalityLine'] ?? ($isItajai ? 'MUNICÍPIO DE ITAJAÍ' : ('Prefeitura Municipal de ' . $local));
        $secretariatLine  = $this->headerInfo['secretariatLine']  ?? ($isItajai ? 'SECRETARIA MUNICIPAL DA FAZENDA' : 'Secretaria Municipal da Fazenda');
        $phoneLine        = $this->headerInfo['phoneLine']        ?? ($isItajai ? '(47)3241-7400' : '(48)3431-0074');
        $emailLine        = $this->headerInfo['emailLine']        ?? ($isItajai ? 'plantaofiscal@itajai.sc.gov.br' : 'tributos@criciuma.sc.gov.br');

        $this->pdf->SetXY($textX, $startY);
        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->pdf->Cell($textBlockWidth, 3, $municipalityLine, 0, 1, 'L');
        $this->pdf->SetXY($textX, $startY + 3);
        $this->pdf->SetFont('helvetica', '', 6);
        $this->pdf->Cell($textBlockWidth, 2.5, $secretariatLine, 0, 1, 'L');
        $this->pdf->SetXY($textX, $startY + 5.5);
        $this->pdf->Cell($textBlockWidth, 2.5, $phoneLine, 0, 1, 'L');
        $this->pdf->SetXY($textX, $startY + 8);
        $this->pdf->Cell($textBlockWidth, 2.5, $emailLine, 0, 1, 'L');

        $this->pdf->SetY($startY + ($this->showHomologationWarning ? 16 : 12));
        $this->pdf->Ln(1);
    }

    private function addDadosNfse()
    {
        $col1X = $this->margin;
        $col2X = 47;
        $col3X = 97;
        $col4X = 147;
        $col1W = 45;
        $col2W = 50;
        $col3W = 50;
        $col4W = 45;

        $startY = $this->pdf->GetY();

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $startY);
        $this->pdf->Cell($col1W + $col2W + $col3W + $col4W, 4, 'Chave de Acesso da NFS-e', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $startY + 4);
        $this->pdf->Cell($col1W + $col2W + $col3W + $col4W, 4, $this->data['chaveAcesso'], 0, 1, 'L');

        $row1Y = $this->pdf->GetY();

        $qrUrl = 'https://www.nfse.gov.br/ConsultaPublica?tpc=1&chave=' . $this->data['chaveAcesso'];
        $qrSize = 18;
        $qrX = $col4X + ($col4W - $qrSize) / 1.5;
        $qrY = $row1Y - 10;

        $style = array(
            'border' => 0,
            'vpadding' => 'auto',
            'hpadding' => 'auto',
            'fgcolor' => array(0, 0, 0),
            'bgcolor' => false,
            'module_width' => 1,
            'module_height' => 1
        );

        $this->pdf->write2DBarcode($qrUrl, 'QRCODE,L', $qrX, $qrY, $qrSize, $qrSize, $style, 'N');

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row1Y);
        $this->pdf->Cell($col1W, 4, 'Número da NFS-e', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row1Y);
        $this->pdf->Cell($col2W, 4, 'Competência da NFS-e', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row1Y);
        $this->pdf->Cell($col3W, 4, 'Data e Hora da emissão da NFS-e', 0, 0, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $row2Y = $row1Y + 4;
        $this->pdf->SetXY($col1X, $row2Y);
        $this->pdf->Cell($col1W, 4, $this->data['numeroNfse'], 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row2Y);
        $this->pdf->Cell($col2W, 4, $this->data['dps']['competencia'], 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row2Y);
        $this->pdf->Cell($col3W, 4, $this->data['dataProcessamento'], 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row2Y);
        $this->pdf->Cell($col4W, 4, '', 0, 1, 'L');

        $row3Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row3Y);
        $this->pdf->Cell($col1W, 4, 'Número da DPS', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row3Y);
        $this->pdf->Cell($col2W, 4, 'Série da DPS', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row3Y);
        $this->pdf->Cell($col3W, 4, 'Data e Hora da emissão da DPS', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row3Y);
        $this->pdf->Cell($col4W, 4, '', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $row4Y = $row3Y + 4;
        $this->pdf->SetXY($col1X, $row4Y);
        $this->pdf->Cell($col1W, 4, $this->data['dps']['numero'], 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row4Y);
        $this->pdf->Cell($col2W, 4, $this->data['dps']['serie'], 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row4Y);
        $this->pdf->Cell($col3W, 4, $this->data['dps']['dataEmissao'], 0, 0, 'L');

        $this->pdf->SetXY($col4X, $row4Y);
        $this->pdf->SetFont('helvetica', '', 5);
        $message = 'A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e';
        $this->pdf->MultiCell($col4W - 1, 1, $message, 0, 'L', false, 1, $col4X+5, $row4Y-4);
        $messageEndY = $this->pdf->GetY();

        $this->pdf->SetY(max($row1Y + $qrSize, $messageEndY) + 2);
        $this->pdf->Ln(1);
    }

    private function addEmitente()
    {
        $col1X = $this->margin;
        $col2X = 47;
        $col3X = 97;
        $col4X = 147;
        $col1W = 45;
        $col2W = 50;
        $col3W = 50;
        $col4W = 45;

        $emit = $this->data['emitente'];
        $startY = $this->pdf->GetY();

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $startY);
        $this->pdf->Cell($col1W, 4, 'EMITENTE DA NFS-e', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY);
        $this->pdf->Cell($col2W, 4, 'CNPJ / CPF / NIF', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY);
        $this->pdf->Cell($col3W, 4, 'Inscrição Municipal', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $startY);
        $this->pdf->Cell($col4W, 4, 'Telefone', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $startY + 4);
        $this->pdf->Cell($col1W, 4, 'Prestador do Serviço', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY + 4);
        $this->pdf->Cell($col2W, 4, $emit['cnpj'], 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY + 4);
        $this->pdf->Cell($col3W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $startY + 4);
        $this->pdf->Cell($col4W, 4, $emit['fone'], 0, 1, 'L');

        $row2Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row2Y);
        $this->pdf->Cell($col1W, 4, 'Nome / Nome Empresarial', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row2Y);
        $this->pdf->Cell($col2W, 4, '', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row2Y);
        $this->pdf->Cell($col3W, 4, 'E-mail', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row2Y);
        $this->pdf->Cell($col4W, 4, '', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $row2Y + 4);
        $this->pdf->Cell($col1W, 4, $emit['nome'], 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row2Y + 4);
        $this->pdf->Cell($col2W, 4, '', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row2Y + 4);
        $this->pdf->Cell($col3W, 4, $emit['email'], 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row2Y + 4);
        $this->pdf->Cell($col4W, 4, '', 0, 1, 'L');

        $endereco = $emit['logradouro'] . ', ' . $emit['numero'] . ', ' . $emit['bairro'];
        $row3Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row3Y);
        $this->pdf->Cell($col1W, 4, 'Endereço', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row3Y);
        $this->pdf->Cell($col2W, 4, '', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row3Y);
        $this->pdf->Cell($col3W, 4, 'Município', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row3Y);
        $this->pdf->Cell($col4W, 4, 'CEP', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $row3Y + 4);
        $this->pdf->Cell($col1W, 4, $endereco, 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row3Y + 4);
        $this->pdf->Cell($col2W, 4, '', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row3Y + 4);
        $this->pdf->Cell($col3W, 4, $this->data['localEmissao'] . ' - ' . $emit['uf'], 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row3Y + 4);
        $this->pdf->Cell($col4W, 4, $emit['cep'], 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $this->pdf->GetY());
        $this->pdf->Cell($col1W, 4, 'Simples Nacional na Data de Competência', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $this->pdf->GetY());
        $this->pdf->Cell($col3W + $col4W, 4, 'Regime de Apuração Tributária pelo SN', 0, 1, 'L');
        $this->pdf->SetXY($col1X, $this->pdf->GetY());
        $this->pdf->Cell($col1W, 4, 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $this->pdf->GetY());
        $this->pdf->Cell($col3W + $col4W, 4, 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional', 0, 1, 'L');
        $this->pdf->Ln(1);
    }

    private function addTomador()
    {
        $col1X = $this->margin;
        $col2X = 47;
        $col3X = 97;
        $col4X = 147;
        $col1W = 45;
        $col2W = 50;
        $col3W = 50;
        $col4W = 45;

        $toma = $this->data['tomador'];
        $startY = $this->pdf->GetY();

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $startY);
        $this->pdf->Cell($col1W, 4, 'TOMADOR DO SERVIÇO', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY);
        $this->pdf->Cell($col2W, 4, 'CNPJ / CPF / NIF', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY);
        $this->pdf->Cell($col3W, 4, 'Inscrição Municipal', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $startY);
        $this->pdf->Cell($col4W, 4, 'Telefone', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $startY + 4);
        $this->pdf->Cell($col1W, 4, '', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY + 4);
        $this->pdf->Cell($col2W, 4, $toma['cnpj'], 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY + 4);
        $this->pdf->Cell($col3W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $startY + 4);
        $this->pdf->Cell($col4W, 4, $toma['fone'] ?? '', 0, 1, 'L');

        $row2Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row2Y);
        $this->pdf->Cell($col1W, 4, 'Nome / Nome Empresarial', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row2Y);
        $this->pdf->Cell($col2W, 4, '', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row2Y);
        $this->pdf->Cell($col3W, 4, 'E-mail', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row2Y);
        $this->pdf->Cell($col4W, 4, '', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $row2Y + 4);
        $this->pdf->Cell($col1W, 4, $toma['nome'], 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row2Y + 4);
        $this->pdf->Cell($col2W, 4, '', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row2Y + 4);
        $this->pdf->Cell($col3W, 4, !empty($toma['email']) ? $toma['email'] : '-', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row2Y + 4);
        $this->pdf->Cell($col4W, 4, '', 0, 1, 'L');

        $parts = [];
        $logradouro = (string) ($toma['logradouro'] ?? '');
        if ($logradouro !== '') {
            $parts[] = $logradouro;
        }
        $numero = (string) ($toma['numero'] ?? '');
        if ($numero !== '' && ($logradouro === '' || preg_match('/\b' . preg_quote($numero, '/') . '\b/', $logradouro) !== 1)) {
            $parts[] = $numero;
        }
        if (!empty($toma['complemento'])) {
            $parts[] = $toma['complemento'];
        }
        if (!empty($toma['bairro'])) {
            $parts[] = $toma['bairro'];
        }
        $endereco = !empty($parts) ? implode(', ', $parts) : '-';

        $row3Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row3Y);
        $this->pdf->Cell($col1W, 4, 'Endereço', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row3Y);
        $this->pdf->Cell($col2W, 4, '', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row3Y);
        $this->pdf->Cell($col3W, 4, 'Município', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row3Y);
        $this->pdf->Cell($col4W, 4, 'CEP', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $row3Y + 4);
        $this->pdf->Cell($col1W, 4, $endereco, 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row3Y + 4);
        $this->pdf->Cell($col2W, 4, '', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row3Y + 4);
        $mun = $this->resolveMunicipioDisplay((string) ($toma['municipio'] ?? ''));
        $this->pdf->Cell($col3W, 4, $mun, 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row3Y + 4);
        $this->pdf->Cell($col4W, 4, $toma['cep'], 0, 1, 'L');
        $this->pdf->Ln(2);

        $this->pdf->SetFont('helvetica', '', 7);
        $this->addHorizontalLine(false);
        $this->pdf->Cell(0, 4, 'INTERMEDIÁRIO DO SERVIÇO NÃO IDENTIFICADO NA NFS-e', 0, 1, 'C');
    }

    private function addServico()
    {
        $col1X = $this->margin;
        $col2X = 47;
        $col3X = 97;
        $col4X = 147;
        $col1W = 45;
        $col2W = 50;
        $col3W = 50;
        $col4W = 45;

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->Cell(0, 4, 'SERVIÇO PRESTADO', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 8);

        $serv = $this->data['servico'];
        $startY = $this->pdf->GetY();

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $startY);
        $this->pdf->Cell($col1W, 4, 'Código de Tributação Nacional', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY);
        $this->pdf->Cell($col2W, 4, 'Código de Tributação Municipal', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY);
        $this->pdf->Cell($col3W, 4, 'Local da Prestação', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $startY);
        $this->pdf->Cell($col4W, 4, 'País da Prestação', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $codTribFormatted = $this->formatCodTribNac($serv['codTribNac']);
        $codTrib = $codTribFormatted . ' - ' . substr($this->data['tribNac'], 0, 40) . '...';
        $this->pdf->SetXY($col1X, $startY + 4);
        $this->pdf->Cell($col1W, 4, $codTrib, 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY + 4);
        $this->pdf->Cell($col2W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY + 4);
        $this->pdf->Cell($col3W, 4, $this->data['localPrestacao'], 0, 0, 'L');
        $this->pdf->SetXY($col4X, $startY + 4);
        $this->pdf->Cell($col4W, 4, '-', 0, 1, 'L');

        $row2Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row2Y);
        $this->pdf->Cell($col1W, 4, 'Descrição do Serviço', 0, 0, 'L');
        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col2X, $row2Y);
        $this->pdf->Cell($col2W + $col3W + $col4W, 4, $serv['descricao'], 0, 1, 'L');
        $this->pdf->Ln(2);
    }

    private function addTributacao()
    {
        $col1X = $this->margin;
        $col2X = 47;
        $col3X = 97;
        $col4X = 147;
        $col1W = 45;
        $col2W = 50;
        $col3W = 50;
        $col4W = 45;

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->Cell(0, 4, 'TRIBUTAÇÃO MUNICIPAL', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 8);

        $trib = $this->data['tributacao'];
        $startY = $this->pdf->GetY();

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $startY);
        $this->pdf->Cell($col1W, 4, 'Tributação do ISSQN', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY);
        $this->pdf->Cell($col2W, 4, 'País Resultado da Prestação do Serviço', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY);
        $this->pdf->Cell($col3W, 4, 'Município de Incidência do ISSQN', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $startY);
        $this->pdf->Cell($col4W, 4, 'Regime Especial de Tributação', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $startY + 4);
        $this->pdf->Cell($col1W, 4, 'Operação Tributável', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY + 4);
        $this->pdf->Cell($col2W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY + 4);
        $this->pdf->Cell($col3W, 4, $this->data['localIncidencia'], 0, 0, 'L');
        $this->pdf->SetXY($col4X, $startY + 4);
        $this->pdf->Cell($col4W, 4, 'Nenhum', 0, 1, 'L');

        $row2Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row2Y);
        $this->pdf->Cell($col1W, 4, 'Tipo de Imunidade', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row2Y);
        $this->pdf->Cell($col2W, 4, 'Suspensão da Exigibilidade do ISSQN', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row2Y);
        $this->pdf->Cell($col3W, 4, 'Número Processo Suspensão', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row2Y);
        $this->pdf->Cell($col4W, 4, 'Benefício Municipal', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $row2Y + 4);
        $this->pdf->Cell($col1W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row2Y + 4);
        $this->pdf->Cell($col2W, 4, 'Não', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row2Y + 4);
        $this->pdf->Cell($col3W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row2Y + 4);
        $this->pdf->Cell($col4W, 4, '-', 0, 1, 'L');

        $val = $this->data['valores'];
        $row3Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row3Y);
        $this->pdf->Cell($col1W, 4, 'Valor do Serviço', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row3Y);
        $this->pdf->Cell($col2W, 4, 'Desconto Incondicionado', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row3Y);
        $this->pdf->Cell($col3W, 4, 'Total Deduções/Reduções', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row3Y);
        $this->pdf->Cell($col4W, 4, 'Cálculo do BM', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $row3Y + 4);
        $this->pdf->Cell($col1W, 4, 'R$ ' . number_format($val['valorServico'], 2, ',', '.'), 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row3Y + 4);
        $this->pdf->Cell($col2W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row3Y + 4);
        $this->pdf->Cell($col3W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row3Y + 4);
        $this->pdf->Cell($col4W, 4, '-', 0, 1, 'L');

        $row4Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row4Y);
        $this->pdf->Cell($col1W, 4, 'BC ISSQN', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row4Y);
        $this->pdf->Cell($col2W, 4, 'Alíquota Aplicada', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row4Y);
        $this->pdf->Cell($col3W, 4, 'Retenção do ISSQN', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row4Y);
        $this->pdf->Cell($col4W, 4, 'ISSQN Apurado', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $row4Y + 4);
        $this->pdf->Cell($col1W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row4Y + 4);
        $this->pdf->Cell($col2W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row4Y + 4);
        $this->pdf->Cell($col3W, 4, 'Não Retido', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row4Y + 4);
        $this->pdf->Cell($col4W, 4, '-', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->Cell(0, 4, 'TRIBUTAÇÃO FEDERAL', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 8);

        $row5Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row5Y);
        $this->pdf->Cell($col1W, 4, 'IRRF', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row5Y);
        $this->pdf->Cell($col2W, 4, 'CP', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row5Y);
        $this->pdf->Cell($col3W, 4, 'CSLL', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row5Y);
        $this->pdf->Cell($col4W, 4, '', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $row5Y + 4);
        $this->pdf->Cell($col1W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row5Y + 4);
        $this->pdf->Cell($col2W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row5Y + 4);
        $this->pdf->Cell($col3W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row5Y + 4);
        $this->pdf->Cell($col4W, 4, '', 0, 1, 'L');

        $row6Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row6Y);
        $this->pdf->Cell($col1W, 4, 'PIS', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row6Y);
        $this->pdf->Cell($col2W, 4, 'COFINS', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row6Y);
        $this->pdf->Cell($col3W, 4, 'Retenção do PIS/COFINS', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row6Y);
        $this->pdf->Cell($col4W, 4, 'TOTAL TRIBUTAÇÃO FEDERAL', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $row6Y + 4);
        $this->pdf->Cell($col1W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row6Y + 4);
        $this->pdf->Cell($col2W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row6Y + 4);
        $this->pdf->Cell($col3W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row6Y + 4);
        $this->pdf->Cell($col4W, 4, '-', 0, 1, 'L');
        $this->pdf->Ln(2);
    }

    private function addValores()
    {
        $col1X = $this->margin;
        $col2X = 47;
        $col3X = 97;
        $col4X = 147;
        $col1W = 45;
        $col2W = 50;
        $col3W = 50;
        $col4W = 45;

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->Cell(0, 4, 'VALOR TOTAL DA NFS-E', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 8);

        $val = $this->data['valores'];
        $startY = $this->pdf->GetY();

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $startY);
        $this->pdf->Cell($col1W, 4, 'Valor do Serviço', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY);
        $this->pdf->Cell($col2W, 4, 'Desconto Condicionado', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY);
        $this->pdf->Cell($col3W, 4, 'Desconto Incondicionado', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $startY);
        $this->pdf->Cell($col4W, 4, 'ISSQN Retido', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $startY + 4);
        $this->pdf->Cell($col1W, 4, 'R$ ' . number_format($val['valorServico'], 2, ',', '.'), 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY + 4);
        $this->pdf->Cell($col2W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY + 4);
        $this->pdf->Cell($col3W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $startY + 4);
        $this->pdf->Cell($col4W, 4, '-', 0, 1, 'L');

        $row2Y = $this->pdf->GetY();
        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $row2Y);
        $this->pdf->Cell($col1W, 4, 'IRRF, CP,CSLL - Retidos', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row2Y);
        $this->pdf->Cell($col2W, 4, 'PIS/COFINS Retidos', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row2Y);
        $this->pdf->Cell($col3W, 4, '', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row2Y);
        $this->pdf->Cell($col4W, 4, 'Valor Líquido da NFS-e', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $row2Y + 4);
        $this->pdf->Cell($col1W, 4, 'R$ ' . number_format($val['valorTotalRet'], 2, ',', '.'), 0, 0, 'L');
        $this->pdf->SetXY($col2X, $row2Y + 4);
        $this->pdf->Cell($col2W, 4, '-', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $row2Y + 4);
        $this->pdf->Cell($col3W, 4, '', 0, 0, 'L');
        $this->pdf->SetXY($col4X, $row2Y + 4);
        $this->pdf->Cell($col4W, 4, 'R$ ' . number_format($val['valorLiquido'], 2, ',', '.'), 0, 1, 'L');
        $this->pdf->Ln(2);
    }

    private function addTotaisTributos()
    {
        $col1X = $this->margin;
        $col2X = 62;
        $col3X = 122;
        $col1W = 60;
        $col2W = 60;
        $col3W = 60;

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->Cell(0, 4, 'TOTAIS APROXIMADOS DOS TRIBUTOS', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 8);

        $trib = $this->data['tributacao'];
        $startY = $this->pdf->GetY();

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->SetXY($col1X, $startY);
        $this->pdf->Cell($col1W, 4, 'Federais', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY);
        $this->pdf->Cell($col2W, 4, 'Estaduais', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY);
        $this->pdf->Cell($col3W, 4, 'Municípios', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetXY($col1X, $startY + 4);
        $this->pdf->Cell($col1W, 4, number_format($trib['totTribFed'], 2, ',', '.') . ' %', 0, 0, 'L');
        $this->pdf->SetXY($col2X, $startY + 4);
        $this->pdf->Cell($col2W, 4, number_format($trib['totTribEst'], 2, ',', '.') . ' %', 0, 0, 'L');
        $this->pdf->SetXY($col3X, $startY + 4);
        $this->pdf->Cell($col3W, 4, number_format($trib['totTribMun'], 2, ',', '.') . ' %', 0, 1, 'L');
        $this->pdf->Ln(5);

        $this->pdf->SetFont('helvetica', 'B', 7);
        $this->pdf->Cell(0, 4, 'INFORMAÇÕES COMPLEMENTARES', 0, 1, 'L');
    }

    private function formatCnpjCpf($value)
    {
        $value = preg_replace('/\D/', '', $value);
        if (strlen($value) == 14) {
            return substr($value, 0, 2) . '.' . substr($value, 2, 3) . '.' . substr($value, 5, 3) . '/' . substr($value, 8, 4) . '-' . substr($value, 12, 2);
        } elseif (strlen($value) == 11) {
            return substr($value, 0, 3) . '.' . substr($value, 3, 3) . '.' . substr($value, 6, 3) . '-' . substr($value, 9, 2);
        }
        return $value;
    }

    private function formatCep($value)
    {
        $value = preg_replace('/\D/', '', $value);
        if (strlen($value) == 8) {
            return substr($value, 0, 5) . '-' . substr($value, 5, 3);
        }
        return $value;
    }

    private function formatPhone($value)
    {
        $value = preg_replace('/\D/', '', $value);
        if (strlen($value) == 11) {
            return '(' . substr($value, 0, 2) . ') ' . substr($value, 2, 5) . '-' . substr($value, 7, 4);
        } elseif (strlen($value) == 10) {
            return '(' . substr($value, 0, 2) . ') ' . substr($value, 2, 4) . '-' . substr($value, 6, 4);
        }
        return $value;
    }

    private function formatDate($value)
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches)) {
            return $matches[3] . '/' . $matches[2] . '/' . $matches[1];
        }
        return $value;
    }

    private function formatDateTime($value)
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/', $value, $matches)) {
            return $matches[3] . '/' . $matches[2] . '/' . $matches[1] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];
        }
        return $value;
    }

    private function formatCodTribNac($value)
    {
        $value = preg_replace('/\D/', '', $value);
        if (strlen($value) == 6) {
            return substr($value, 0, 2) . '.' . substr($value, 2, 2) . '.' . substr($value, 4, 2);
        }
        return $value;
    }

    private function resolveMunicipioDisplay(string $ibgeCode): string
    {
        $ibgeCode = preg_replace('/\D/', '', $ibgeCode);
        if ($ibgeCode === '') {
            return '-';
        }

        if (class_exists(\WHMCS\Database\Capsule::class)) {
            try {
                $row = \WHMCS\Database\Capsule::table('mod_opennfse_ibge_municipios')->where('ibge_code', $ibgeCode)->first();
                if ($row) {
                    $nome = (string) ($row->nome_original ?? $row->nome_normalizado ?? '');
                    $uf = (string) ($row->uf ?? '');
                    if ($nome !== '') {
                        return $uf !== '' ? ($nome . ' - ' . $uf) : $nome;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        return $ibgeCode;
    }

}
