<?php

declare(strict_types=1);

use OpenNfse\Services\CryptoService;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    if (PHP_SAPI !== 'cli') {
        exit('This file cannot be accessed directly');
    }
    $init = realpath(__DIR__ . '/../../../init.php');
    if ($init === false) {
        fwrite(STDERR, "init.php não encontrado.\n");
        exit(1);
    }
    require_once $init;
}

require_once __DIR__ . '/../bootstrap.php';

$fail = static function (string $msg): void {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};

$ok = static function (string $msg): void {
    fwrite(STDOUT, $msg . "\n");
};

if (!class_exists(\Nfse\Nfse::class)) {
    $fail('SDK nfse-nacional/nfse-php não encontrada no autoload do módulo.');
}
$ok('SDK OK');

$tables = [
    'mod_opennfse_config',
    'mod_opennfse_notas',
    'mod_opennfse_logs',
    'mod_opennfse_queue',
    'mod_opennfse_sequences',
];
foreach ($tables as $t) {
    $exists = false;
    try {
        $exists = Capsule::schema()->hasTable($t);
    } catch (\Throwable $e) {
        $exists = false;
    }
    if (!$exists) {
        $fail('Tabela ausente: ' . $t . ' (ative o addon para rodar migrations).');
    }
}
$ok('Tabelas OK');

$cfg = Capsule::table('mod_opennfse_config')->orderBy('id', 'desc')->first();
if (!$cfg) {
    $fail('Configuração não encontrada em mod_opennfse_config.');
}
$cfg = (array) $cfg;
$ok('Config OK');

$certPath = (string) ($cfg['certificate_path'] ?? '');
if ($certPath === '' || !file_exists($certPath)) {
    $fail('certificate_path inválido/inexistente.');
}
$ok('Certificado OK');

$enc = (string) ($cfg['certificate_password_enc'] ?? '');
if ($enc === '') {
    $fail('certificate_password_enc vazio (salve a senha ao menos uma vez).');
}
$pass = '';
try {
    $pass = (new CryptoService())->decrypt($enc);
} catch (\Throwable $e) {
    $pass = '';
}
if ($pass === '') {
    $fail('Falha ao descriptografar senha do certificado (decrypt retornou vazio).');
}
$ok('Senha do certificado OK');

$attachments = realpath(__DIR__ . '/../../../../attachments');
if ($attachments === false) {
    $fail('Diretório attachments/ não encontrado.');
}
$xmlDir = $attachments . '/nfse/xml';
if (!is_dir($xmlDir)) {
    @mkdir($xmlDir, 0775, true);
}
if (!is_dir($xmlDir) || !is_writable($xmlDir)) {
    $fail('Diretório não gravável: ' . $xmlDir);
}
$ok('Storage OK');

$ok('Smoke check finalizado com sucesso.');

