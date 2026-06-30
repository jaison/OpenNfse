<?php

namespace OpenNfseVendor;

/** @var \Nfse\Nfse $nfse */
$nfse = require_once __DIR__ . '/../bootstrap.php';
try {
    $idDps = 'DPS3550308112345678000199100000000000001';
    // Substitua pelo ID real
    echo "Verificando existência da DPS: {$idDps}...\n";
    $existe = $nfse->contribuinte()->verificarDps($idDps);
    if ($existe) {
        echo "A DPS existe na base do SEFIN.\n";
    } else {
        echo "A DPS não foi encontrada.\n";
    }
} catch (\Exception $e) {
    echo 'Erro: ' . $e->getMessage() . "\n";
}
