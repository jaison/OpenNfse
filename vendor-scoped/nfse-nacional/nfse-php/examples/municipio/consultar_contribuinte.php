<?php

namespace OpenNfseVendor;

/** @var \Nfse\Nfse $nfse */
$nfse = require_once __DIR__ . '/../bootstrap.php';
try {
    $cpfCnpj = '12345678000199';
    echo "Consultando contribuinte no CNC: {$cpfCnpj}...\n";
    $dados = $nfse->municipio()->consultarContribuinte($cpfCnpj);
    \print_r($dados);
} catch (\Exception $e) {
    echo 'Erro: ' . $e->getMessage() . "\n";
}
