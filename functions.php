<?php

declare(strict_types=1);

use OpenNfse\Services\IbgeService;

function normalizarNome($nome)
{
    return \OpenNfse\Helpers\NameNormalizer::normalize((string) $nome);
}

function getIbgeCode($municipio, $uf, $cep = null): ?string
{
    $service = new IbgeService();
    return $service->getIbgeCode((string) $municipio, (string) $uf, $cep !== null ? (string) $cep : null);
}

