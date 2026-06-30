<?php

namespace OpenNfseVendor;

/** @var \Nfse\Nfse $nfse */
$nfse = require_once __DIR__ . '/../bootstrap.php';
try {
    echo "Enviando lote de documentos (exemplo)...\n";
    // O XML deve ser compactado com GZIP e codificado em Base64
    $xmlContent = '<lote>...</lote>';
    $xmlZipB64 = \base64_encode(\gzencode($xmlContent));
    $response = $nfse->municipio()->enviarLote($xmlZipB64);
    \print_r($response);
} catch (\Exception $e) {
    echo 'Erro: ' . $e->getMessage() . "\n";
}
