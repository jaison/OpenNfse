<?php

namespace OpenNfseVendor;

/** @var \Nfse\Nfse $nfse */
$nfse = require_once __DIR__ . '/../../bootstrap.php';
try {
    echo "Consultando parâmetros do convênio para o município: {$codigoMunicipio} ({$municipioSelecionado})...\n";
    $response = $nfse->contribuinte()->consultarParametrosConvenio($codigoMunicipio);
    echo 'Mensagem: ' . $response->mensagem . "\n";
    if ($response->parametrosConvenio) {
        echo 'Aderente Emissor Nacional: ' . ($response->parametrosConvenio->aderenteAmbienteNacional === 1 ? 'Sim' : 'Não') . "\n";
    }
} catch (\Exception $e) {
    echo 'Erro: ' . $e->getMessage() . "\n";
}
