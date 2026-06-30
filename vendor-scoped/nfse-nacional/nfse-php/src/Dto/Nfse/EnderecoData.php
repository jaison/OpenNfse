<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class EnderecoData extends Dto
{
    /**
     * Código do município (IBGE).
     */
    #[MapFrom('endNac.cMun')]
    public ?string $codigoMunicipio = null;
    /**
     * CEP.
     */
    #[MapFrom('endNac.CEP')]
    public ?string $cep = null;
    /**
     * Logradouro.
     */
    #[MapFrom('xLgr')]
    public ?string $logradouro = null;
    /**
     * Número.
     */
    #[MapFrom('nro')]
    public ?string $numero = null;
    /**
     * Bairro.
     */
    #[MapFrom('xBairro')]
    public ?string $bairro = null;
    /**
     * Complemento.
     */
    #[MapFrom('xCpl')]
    public ?string $complemento = null;
    /**
     * Endereço no exterior.
     */
    #[MapFrom('endExt')]
    public ?\Nfse\Dto\Nfse\EnderecoExteriorData $enderecoExterior = null;
}
