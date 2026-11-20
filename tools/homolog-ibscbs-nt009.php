<?php

declare(strict_types=1);

/**
 * Teste pontual, só para homologação: emite uma DPS com o grupo IBSCBS no leiaute da NT 009
 * (CST e cClassTrib direto em IBSCBS/valores/trib) e cancela a nota em seguida.
 *
 * Valida o ajuste da nfse-php para a issue nfse-nacional/nfse-php#81. Não faz parte do addon:
 * vive só no branch test/nt009-ibscbs e não grava nada nas tabelas de notas do OpenNfse.
 *
 * Uso (na raiz do WHMCS):
 *   php modules/addons/OpenNfse/tools/homolog-ibscbs-nt009.php <invoiceid> [--dry] [--fin-duplicado]
 *
 *   --dry            só monta e mostra o grupo IBSCBS, sem enviar.
 *   --fin-duplicado  também informa finNFSe em IbscbsData; o XML deve sair com um finNFSe só.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

const CLIENTE_TESTE = 7660;

$init = realpath(__DIR__ . '/../../../../init.php');
if ($init === false) {
    fwrite(STDERR, "init.php não encontrado.\n");
    exit(1);
}
require_once $init;
require_once __DIR__ . '/../bootstrap.php';

use OpenNfse\Api\NfsePhpSdkAdapter;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\SequenceRepository;
use OpenNfse\Repositories\WhmcsCustomerRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use OpenNfse\Services\CryptoService;
use OpenNfse\Services\DpsBuilderService;
use OpenNfse\Services\TomadorBairroService;

$args = array_slice($argv, 1);
$dry = in_array('--dry', $args, true);
$finDuplicado = in_array('--fin-duplicado', $args, true);
$invoiceId = (int) ($args[0] ?? 0);

function falhar(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

if ($invoiceId <= 0) {
    falhar('Informe o ID da fatura de teste.');
}

$config = (new ConfigRepository())->get();
if (($config['environment'] ?? '') !== 'homologacao') {
    falhar('Recusado: o ambiente configurado não é homologação.');
}

$invoiceRepo = new WhmcsInvoiceRepository();
$customerRepo = new WhmcsCustomerRepository();
$invoice = $invoiceRepo->getInvoice($invoiceId);
$userId = (int) ($invoice['userid'] ?? 0);
if ($userId !== CLIENTE_TESTE) {
    falhar('Recusado: a fatura não é do cliente de teste ' . CLIENTE_TESTE . '.');
}

$items = $invoiceRepo->getItems($invoiceId);
$client = $customerRepo->getClient($userId);
$tomadorCpfCnpj = $customerRepo->getCpfCnpjFromCustomField($userId, (int) ($config['tomador_cpfcnpj_customfield_id'] ?? 0));
$client = (new TomadorBairroService())->withCustomFieldBairro($config, $client, $customerRepo);

$numeroDps = $dry ? 999999 : (new SequenceRepository())->next(
    (string) $config['environment'],
    (string) $config['cnpj_emissor'],
    (string) $config['serie_dps']
);
$dps = (new DpsBuilderService())->build($config, $invoice, $items, $client, $tomadorCpfCnpj, $numeroDps);

if (($dps->infDps->finalidadeNfse ?? null) === null) {
    falhar('O addon montou a DPS no leiaute anterior à NT 009 (sem finNFSe). Ajuste o leiaute para NT 009 ou automático.');
}

$ibscbs = [
    'cIndOp' => '100301',
    'indDest' => 0,
    'valores' => ['trib' => ['gIBSCBS' => ['CST' => '000', 'cClassTrib' => '000001']]],
];
if ($finDuplicado) {
    $ibscbs = ['finNFSe' => '0'] + $ibscbs;
}
$dps->infDps->ibscbs = new \Nfse\Dto\Nfse\IbscbsData($ibscbs);

$xml = (new \Nfse\Xml\DpsXmlBuilder())->build($dps);
preg_match('#<IBSCBS>.*</IBSCBS>#s', $xml, $m);
echo 'idDps: ', $dps->infDps->id, "\n";
echo 'finNFSe no XML: ', substr_count($xml, '<finNFSe>'), "\n";
echo 'IBSCBS: ', $m[0] ?? '(ausente)', "\n";

if ($dry) {
    exit(0);
}

$sdkConfig = [
    'ambiente' => \Nfse\Enums\TipoAmbiente::Homologacao,
    'certificatePath' => (string) $config['certificate_path'],
    'certificatePassword' => (new CryptoService())->decrypt((string) $config['certificate_password_enc']),
    'codigoMunicipio' => (string) ($config['codigo_ibge'] ?? ''),
];
$adapter = new NfsePhpSdkAdapter();
$result = $adapter->emitir($sdkConfig, $dps);

echo 'emissão: ', $result->success ? 'AUTORIZADA' : 'REJEITADA', ' (HTTP ', $result->httpStatus ?? '-', ")\n";
if (!$result->success) {
    echo 'erro: ', $result->errorMessage ?? '', "\n";
    exit(2);
}
echo 'chave: ', $result->chaveAcesso, "\n";

$evento = new \Nfse\Dto\Nfse\PedRegEventoData([
    'versao' => '1.01',
    'infPedReg' => [
        'tpAmb' => 2,
        'verAplic' => 'WHMCS-NFSE-ADDON',
        'dhEvento' => date('c'),
        'chNFSe' => $result->chaveAcesso,
        'cnpjAutor' => preg_replace('/\D/', '', (string) $config['cnpj_emissor']),
        'tipoEvento' => '101101',
        'e101101' => [
            'xDesc' => 'Cancelamento de NFS-e',
            'cMotivo' => '1',
            'xMotivo' => 'Teste de homologacao do leiaute NT 009 com IBSCBS',
        ],
    ],
]);
$cancel = $adapter->cancelarNfse($sdkConfig, $evento);
echo 'cancelamento: ', $cancel->success ? 'REGISTRADO' : 'FALHOU', "\n";
if (!$cancel->success) {
    echo 'erro: ', $cancel->errorMessage ?? '', "\n";
    exit(3);
}
