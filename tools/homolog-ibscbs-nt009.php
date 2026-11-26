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
 *   --cancelar-dps=<idDps> só consulta a DPS informada e cancela a NFS-e dela (use com a mesma fatura).
 *   --nbs=<9 dígitos> NBS a usar quando a DPS sair sem cNBS (a Sefin exige NBS com IBS/CBS, E0322).
 *   --ente=<1-4>     compra governamental: tpEnteGov (1 União, 2 Estado, 3 DF, 4 Município).
 *   --oper=<1|4|5>   tpOper da compra governamental (2 e 3 exigem gRefNFSe e não são cobertos aqui).
 *   --cindop=<6 dígitos> cIndOp a enviar no lugar de 100301.
 *   --sem-endereco   envia o tomador sem endereço (confere se a Sefin exige o endereço).
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
$semEndereco = in_array('--sem-endereco', $args, true);
$invoiceId = (int) ($args[0] ?? 0);
$nbs = '';
$cancelarDps = '';
$ente = 0;
$oper = 0;
$cIndOp = '100301';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--cancelar-dps=')) {
        $cancelarDps = trim(substr($arg, 15));
    }
    if (str_starts_with($arg, '--ente=')) {
        $ente = (int) substr($arg, 7);
    }
    if (str_starts_with($arg, '--oper=')) {
        $oper = (int) substr($arg, 7);
    }
    if (str_starts_with($arg, '--cindop=')) {
        $cIndOp = preg_replace('/\D/', '', substr($arg, 9));
    }
    if (str_starts_with($arg, '--nbs=')) {
        $nbs = preg_replace('/\D/', '', substr($arg, 6));
    }
}

function falhar(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

if ($invoiceId <= 0) {
    falhar('Informe o ID da fatura de teste.');
}
if (strlen($cIndOp) !== 6) {
    falhar('Use --cindop com 6 dígitos.');
}
if (($ente === 0) !== ($oper === 0)) {
    falhar('Compra governamental: informe --ente e --oper juntos (tpOper sem tpEnteGov é rejeitado, E0904).');
}
if ($ente !== 0 && (!in_array($ente, [1, 2, 3, 4], true) || !in_array($oper, [1, 4, 5], true))) {
    falhar('Use --ente=1..4 e --oper=1, 4 ou 5.');
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

$sdkConfig = [
    'ambiente' => \Nfse\Enums\TipoAmbiente::Homologacao,
    'certificatePath' => (string) $config['certificate_path'],
    'certificatePassword' => (new CryptoService())->decrypt((string) $config['certificate_password_enc']),
    'codigoMunicipio' => (string) ($config['codigo_ibge'] ?? ''),
];
$adapter = new NfsePhpSdkAdapter();

function cancelar(NfsePhpSdkAdapter $adapter, array $sdkConfig, array $config, string $chave): void
{
    $evento = new \Nfse\Dto\Nfse\PedRegEventoData([
        'versao' => '1.01',
        'infPedReg' => [
            'tpAmb' => 2,
            'verAplic' => 'WHMCS-NFSE-ADDON',
            'dhEvento' => date('c'),
            'chNFSe' => $chave,
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
}

/** Consulta a NFS-e direto na Sefin (XML bruto) e mostra os campos de compra governamental. */
function mostrarCamposNfse(array $sdkConfig, string $chave): void
{
    $context = new \Nfse\Http\NfseContext(
        ambiente: $sdkConfig['ambiente'],
        certificatePath: $sdkConfig['certificatePath'],
        certificatePassword: $sdkConfig['certificatePassword'],
        codigoMunicipio: $sdkConfig['codigoMunicipio'] ?: null
    );
    $resp = (new \Nfse\Http\Client\SefinClient($context))->consultarNfse($chave);
    $xml = $resp->nfseXmlGZipB64 ? (string) gzdecode(base64_decode($resp->nfseXmlGZipB64)) : '';
    if ($xml === '') {
        echo "NFS-e: consulta sem XML\n";
        return;
    }
    foreach (['cIndOp', 'tpEnteGov', 'tpOper', 'pRedutor', 'gTribCompraGov', 'gTribSN', 'vReceitaBrutaSN', 'totCIBS'] as $tag) {
        echo 'NFS-e ', $tag, ': ', preg_match('#<' . $tag . '>.*?</' . $tag . '>#s', $xml, $t) ? $t[0] : '(ausente)', "\n";
    }
}

if ($cancelarDps !== '') {
    $consulta = $adapter->consultarDps($sdkConfig, $cancelarDps);
    $chave = trim((string) ($consulta->chaveAcesso ?? ''));
    if ($chave === '') {
        falhar('DPS não encontrada ou sem chave: ' . ($consulta->errorMessage ?? ''));
    }
    echo 'chave: ', $chave, "\n";
    mostrarCamposNfse($sdkConfig, $chave);
    cancelar($adapter, $sdkConfig, $config, $chave);
    exit(0);
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

if ($semEndereco && $dps->infDps->tomador !== null) {
    $dps->infDps->tomador->endereco = null;
}

$cServ = $dps->infDps->servico->codigoServico ?? null;
if ($cServ !== null && ($cServ->codigoNbs ?? '') === '') {
    if (strlen($nbs) !== 9) {
        falhar('A DPS saiu sem cNBS. Informe --nbs=<9 dígitos> (a Sefin exige NBS com IBS/CBS, E0322).');
    }
    $cServ->codigoNbs = $nbs;
}

$ibscbs = [
    'cIndOp' => $cIndOp,
    'indDest' => 0,
    'valores' => ['trib' => ['gIBSCBS' => ['CST' => '000', 'cClassTrib' => '000001']]],
];
if ($ente !== 0) {
    // Ordem do XML é decidida pelo DpsXmlBuilder; aqui só os dados.
    $ibscbs['tpOper'] = $oper;
    $ibscbs['tpEnteGov'] = $ente;
}
if ($finDuplicado) {
    $ibscbs = ['finNFSe' => '0'] + $ibscbs;
}
$dps->infDps->ibscbs = new \Nfse\Dto\Nfse\IbscbsData($ibscbs);

$xml = (new \Nfse\Xml\DpsXmlBuilder())->build($dps);
preg_match('#<IBSCBS>.*</IBSCBS>#s', $xml, $m);
echo 'idDps: ', $dps->infDps->id, "\n";
preg_match('#<toma>.*</toma>#s', $xml, $t);
echo 'toma tem <end>: ', isset($t[0]) && str_contains($t[0], '<end>') ? 'sim' : 'não', "\n";
preg_match('#<cServ>.*</cServ>#s', $xml, $c);
echo 'cServ: ', $c[0] ?? '(ausente)', "\n";
echo 'finNFSe no XML: ', substr_count($xml, '<finNFSe>'), "\n";
echo 'IBSCBS: ', $m[0] ?? '(ausente)', "\n";

if ($dry) {
    exit(0);
}

$result = $adapter->emitir($sdkConfig, $dps);

echo 'emissão: ', $result->success ? 'AUTORIZADA' : 'REJEITADA', ' (HTTP ', $result->httpStatus ?? '-', ")\n";
if (!$result->success) {
    echo 'erro: ', $result->errorMessage ?? '', "\n";
    exit(2);
}
$chave = trim((string) ($result->chaveAcesso ?? ''));
if ($chave === '' && $result->nfseXml) {
    $chave = trim((string) (\OpenNfse\Helpers\NfseXmlExtractor::extractChaveAcesso($result->nfseXml) ?? ''));
}
if ($chave === '') {
    falhar('Autorizada, mas sem chave na resposta. Cancele com --cancelar-dps=' . $dps->infDps->id);
}
echo 'chave: ', $chave, "\n";
if ($result->nfseXml) {
    foreach (['cIndOp', 'pRedutor', 'gTribCompraGov', 'gTribSN', 'tpEnteGov', 'tpOper'] as $tag) {
        if (preg_match('#<' . $tag . '>.*?</' . $tag . '>#s', $result->nfseXml, $t)) {
            echo 'NFS-e ', $tag, ': ', $t[0], "\n";
        } else {
            echo 'NFS-e ', $tag, ': (ausente)', "\n";
        }
    }
}
cancelar($adapter, $sdkConfig, $config, $chave);
