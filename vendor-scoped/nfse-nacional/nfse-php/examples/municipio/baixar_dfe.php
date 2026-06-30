<?php

namespace OpenNfseVendor;

/** @var \Nfse\Nfse $nfse */
$nfse = require_once __DIR__ . '/../bootstrap.php';
try {
    $nsu = 1;
    echo "Baixando DF-e para o município (NSU: {$nsu})...\n";
    $response = $nfse->municipio()->baixarDfe($nsu, \Nfse\Enums\TipoNsu::Geral);
    echo 'NSU Final: ' . $response->ultimoNsu . "\n";
    echo 'Quantidade de documentos: ' . \count($response->listaNsu) . "\n";
    // print_r($response);
} catch (\Exception $e) {
    echo 'Erro: ' . $e->getMessage() . "\n";
}
