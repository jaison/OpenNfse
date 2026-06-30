<?php

declare(strict_types=1);

use OpenNfse\Helpers\NameNormalizer;
use OpenNfse\Migrations\Migrator;
use OpenNfse\Repositories\IbgeMunicipioRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Somente CLI.\n");
    exit(1);
}

$init = realpath(__DIR__ . '/../../../../init.php');
if ($init === false) {
    fwrite(STDERR, "init.php não encontrado.\n");
    exit(1);
}
require_once $init;

require_once __DIR__ . '/../bootstrap.php';

(new Migrator())->up();

$source = $argv[1] ?? 'https://raw.githubusercontent.com/kelvins/municipios-brasileiros/main/json/municipios.json';
$json = null;

if (preg_match('#^https?://#i', $source)) {
    $json = httpGet($source, 30);
    if ($json === null) {
        fwrite(STDERR, "Falha ao baixar JSON.\n");
        exit(1);
    }
} else {
    $json = @file_get_contents($source);
    if ($json === false) {
        fwrite(STDERR, "Falha ao ler arquivo local: {$source}\n");
        exit(1);
    }
}

$json = stripUtf8Bom($json);

$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
if (!is_array($data)) {
    $err = json_last_error_msg();
    fwrite(STDERR, "JSON inválido: {$err}\n");
    fwrite(STDERR, "Tamanho (bytes): " . strlen($json) . "\n");
    fwrite(STDERR, "Início: " . substr($json, 0, 200) . "\n");
    fwrite(STDERR, "Fim: " . substr($json, -200) . "\n");
    exit(1);
}

$repo = new IbgeMunicipioRepository();
$count = 0;

$estadosUrl = 'https://raw.githubusercontent.com/kelvins/municipios-brasileiros/main/json/estados.json';
$estadosJson = httpGet($estadosUrl, 30);
$codigoUfToUf = [];
if ($estadosJson !== null) {
    $estadosJson = stripUtf8Bom($estadosJson);
    $estados = json_decode($estadosJson, true);
    if (is_array($estados)) {
        foreach ($estados as $e) {
            if (!is_array($e)) {
                continue;
            }
            $codigoUf = isset($e['codigo_uf']) ? (int) $e['codigo_uf'] : 0;
            $ufSigla = (string) ($e['uf'] ?? '');
            if ($codigoUf > 0 && $ufSigla !== '') {
                $codigoUfToUf[$codigoUf] = $ufSigla;
            }
        }
    }
}

foreach ($data as $item) {
    if (!is_array($item)) {
        continue;
    }
    $ibge = isset($item['codigo_ibge']) ? preg_replace('/\D/', '', (string) $item['codigo_ibge']) : '';
    $uf = (string) ($item['uf'] ?? '');
    if ($uf === '' && isset($item['codigo_uf'])) {
        $codigoUf = (int) $item['codigo_uf'];
        $uf = $codigoUfToUf[$codigoUf] ?? '';
    }
    $nome = (string) ($item['nome_municipio'] ?? $item['nome'] ?? '');

    if ($ibge === '' || strlen($ibge) !== 7 || $uf === '' || $nome === '') {
        continue;
    }

    $normalized = NameNormalizer::normalize($nome);
    if ($normalized === '') {
        continue;
    }

    $repo->upsert($ibge, $normalized, $nome, $uf);
    $count++;
}

fwrite(STDOUT, "Importados: {$count}\n");

function stripUtf8Bom(string $s): string
{
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
        return substr($s, 3);
    }
    return $s;
}

function httpGet(string $url, int $timeoutSeconds): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: whmcs-nfse-import/0.1',
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code >= 400) {
            return null;
        }
        return (string) $resp;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "Accept: application/json\r\nUser-Agent: whmcs-nfse-import/0.1\r\n",
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    return $data !== false ? $data : null;
}
