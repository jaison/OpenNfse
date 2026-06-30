<?php

namespace OpenNfseVendor;

/** @var \Nfse\Nfse $nfse */
$nfse = require_once __DIR__ . '/../bootstrap.php';
try {
    $chave = '35503080000000000000000000000000000000000000';
    // Substitua pela chave real
    echo "Listando eventos para a chave: {$chave}...\n";
    $eventos = $nfse->contribuinte()->listarEventos($chave);
    foreach ($eventos as $evento) {
        echo 'Evento: ' . $evento->tipoEvento . ' - Seq: ' . $evento->numeroSequencial . "\n";
    }
} catch (\Exception $e) {
    echo 'Erro: ' . $e->getMessage() . "\n";
}
