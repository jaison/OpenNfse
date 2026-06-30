<?php

namespace OpenNfseVendor;

/** @var \Nfse\Nfse $nfse */
$nfse = require_once __DIR__ . '/../bootstrap.php';
try {
    $idDps = 'DPS3550308112345678000199100000000000001';
    // Substitua pelo ID real
    echo "Consultando DPS: {$idDps}...\n";
    $response = $nfse->contribuinte()->consultarDps($idDps);
    echo 'Status: ' . $response->status . "\n";
    // print_r($response);
} catch (\Exception $e) {
    echo 'Erro: ' . $e->getMessage() . "\n";
}
