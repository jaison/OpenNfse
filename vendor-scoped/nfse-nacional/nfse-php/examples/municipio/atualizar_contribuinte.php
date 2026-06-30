<?php

namespace OpenNfseVendor;

/** @var \Nfse\Nfse $nfse */
$nfse = require_once __DIR__ . '/../bootstrap.php';
try {
    echo "Atualizando contribuinte no CNC...\n";
    $dados = ['cnpj' => '12345678000199', 'xNome' => 'Empresa de Exemplo LTDA'];
    $response = $nfse->municipio()->atualizarContribuinte($dados);
    \print_r($response);
} catch (\Exception $e) {
    echo 'Erro: ' . $e->getMessage() . "\n";
}
