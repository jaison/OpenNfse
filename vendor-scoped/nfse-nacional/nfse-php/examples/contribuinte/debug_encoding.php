<?php

namespace OpenNfseVendor;

use Nfse\Dto\Nfse\CodigoServicoData;
use Nfse\Dto\Nfse\DpsData;
use Nfse\Dto\Nfse\InfDpsData;
use Nfse\Dto\Nfse\LocalPrestacaoData;
use Nfse\Dto\Nfse\PrestadorData;
use Nfse\Dto\Nfse\ServicoData;
use Nfse\Dto\Nfse\TomadorData;
use Nfse\Dto\Nfse\TributacaoData;
use Nfse\Dto\Nfse\ValoresData;
use Nfse\Dto\Nfse\ValorServicoPrestadoData;
use Nfse\Support\IdGenerator;
use Nfse\Xml\DpsXmlBuilder;
/** @var \Nfse\Nfse $nfse */
$nfse = require_once __DIR__ . '/../bootstrap.php';
$cnpjPrestador = '03279735000194';
$serie = '1';
$numero = '100';
$idDps = IdGenerator::generateDpsId(cpfCnpj: $cnpjPrestador, codIbge: $codigoMunicipio, serieDps: $serie, numDps: $numero);
$dps = new DpsData(versao: '1.0.0', infDps: new InfDpsData(id: $idDps, tipoAmbiente: 2, dataEmissao: \date('Y-m-d\TH:i:s'), versaoAplicativo: 'SDK-PHP-1.0', serie: $serie, numeroDps: $numero, dataCompetencia: \date('Y-m-d'), tipoEmitente: 1, codigoLocalEmissao: $codigoMunicipio, prestador: new PrestadorData(cnpj: $cnpjPrestador, inscricaoMunicipal: '123456', nome: 'Empresa de Teste'), tomador: new TomadorData(cnpj: '98765432000100', nome: 'Cliente de Teste'), servico: new ServicoData(localPrestacao: new LocalPrestacaoData(codigoLocalPrestacao: $codigoMunicipio, codigoPaisPrestacao: 'BR'), codigoServico: new CodigoServicoData(codigoTributacaoNacional: '01.01')), valores: new ValoresData(valorServicoPrestado: new ValorServicoPrestadoData(valorServico: 100.0), tributacao: new TributacaoData(tributacaoIssqn: 1, tipoImunidade: null, tipoRetencaoIssqn: 1, tipoSuspensao: null, numeroProcessoSuspensao: null, beneficioMunicipal: null, cstPisCofins: '01'))));
$builder = new DpsXmlBuilder();
$xml = $builder->build($dps);
echo "=== Testando diferentes formatos ===\n\n";
// Test 1: Original
$gzipped1 = \gzencode($xml);
$payload1 = \base64_encode($gzipped1);
echo "1. Original (com formatação):\n";
echo '   Tamanho XML: ' . \strlen($xml) . " bytes\n";
echo '   Tamanho GZIP: ' . \strlen($gzipped1) . " bytes\n";
echo '   Tamanho Base64: ' . \strlen($payload1) . " bytes\n";
echo '   Primeiros bytes do GZIP (hex): ' . \bin2hex(\substr($gzipped1, 0, 20)) . "\n\n";
// Test 2: Without formatting
$dom = new \DOMDocument('1.0', 'UTF-8');
$dom->loadXML($xml);
$dom->formatOutput = \false;
$dom->encoding = 'UTF-8';
$xmlNoFormat = $dom->saveXML();
$gzipped2 = \gzencode($xmlNoFormat);
$payload2 = \base64_encode($gzipped2);
echo "2. Sem formatação:\n";
echo '   Tamanho XML: ' . \strlen($xmlNoFormat) . " bytes\n";
echo '   Tamanho GZIP: ' . \strlen($gzipped2) . " bytes\n";
echo '   Tamanho Base64: ' . \strlen($payload2) . " bytes\n";
echo '   Primeiros bytes do GZIP (hex): ' . \bin2hex(\substr($gzipped2, 0, 20)) . "\n\n";
// Test 3: Force UTF-8 encoding
$xmlUtf8 = \mb_convert_encoding($xml, 'UTF-8', 'UTF-8');
$gzipped3 = \gzencode($xmlUtf8);
$payload3 = \base64_encode($gzipped3);
echo "3. Com mb_convert_encoding:\n";
echo '   Tamanho XML: ' . \strlen($xmlUtf8) . " bytes\n";
echo '   Tamanho GZIP: ' . \strlen($gzipped3) . " bytes\n";
echo '   Tamanho Base64: ' . \strlen($payload3) . " bytes\n";
echo '   Primeiros bytes do GZIP (hex): ' . \bin2hex(\substr($gzipped3, 0, 20)) . "\n\n";
// Verify decompression
echo "=== Verificando descompressão ===\n";
$decompressed = \gzdecode($gzipped1);
echo 'Descompressão OK: ' . ($decompressed === $xml ? 'SIM' : 'NÃO') . "\n";
echo 'Encoding após descompressão: ' . \mb_detect_encoding($decompressed, ['UTF-8', 'ISO-8859-1', 'ASCII'], \true) . "\n";
