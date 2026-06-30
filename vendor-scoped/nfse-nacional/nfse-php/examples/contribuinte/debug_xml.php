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
// Sign the XML
use Nfse\Signer\Certificate;
use Nfse\Signer\XmlSigner;
$certPath = __DIR__ . '/../certs/contribuinte.pfx';
$certPass = 'Maia2040!';
$cert = new Certificate($certPath, $certPass);
$signer = new XmlSigner($cert);
$signedXml = $signer->sign($xml, 'infDPS');
echo "=== XML Assinado ===\n";
echo $signedXml;
echo "\n\n=== Encoding ===\n";
echo 'mb_detect_encoding: ' . \mb_detect_encoding($signedXml, ['UTF-8', 'ISO-8859-1', 'ASCII'], \true) . "\n";
echo 'strlen: ' . \strlen($xml) . "\n";
echo 'mb_strlen: ' . \mb_strlen($xml, 'UTF-8') . "\n";
echo "\n=== Primeiros 200 bytes ===\n";
echo \substr($xml, 0, 200) . "\n";
echo "\n=== Após GZIP + Base64 ===\n";
$payload = \base64_encode(\gzencode($xml));
echo 'Tamanho do payload: ' . \strlen($payload) . "\n";
echo 'Primeiros 100 caracteres: ' . \substr($payload, 0, 100) . "\n";
