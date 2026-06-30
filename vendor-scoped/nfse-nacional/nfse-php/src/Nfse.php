<?php

namespace Nfse;

use Nfse\Http\NfseContext;
use Nfse\Service\ContribuinteService;
use Nfse\Service\MunicipioService;
class Nfse
{
    public function __construct(private NfseContext $context)
    {
    }
    /**
     * Retorna o serviço para operações de Contribuinte (Prestador/Tomador).
     */
    public function contribuinte(): ContribuinteService
    {
        return new ContribuinteService($this->context);
    }
    /**
     * Retorna o serviço para operações de Município (Prefeitura).
     */
    public function municipio(): MunicipioService
    {
        return new MunicipioService($this->context);
    }
}
