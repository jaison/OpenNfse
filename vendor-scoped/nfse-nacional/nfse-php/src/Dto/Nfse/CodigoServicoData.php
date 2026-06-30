<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class CodigoServicoData extends Dto
{
    /**
     * Código de tributação nacional (LC 116/03).
     */
    #[MapFrom('cTribNac')]
    public ?string $codigoTributacaoNacional = null;
    /**
     * Código de tributação municipal.
     */
    #[MapFrom('cTribMun')]
    public ?string $codigoTributacaoMunicipal = null;
    /**
     * Descrição do serviço.
     */
    #[MapFrom('xDescServ')]
    public ?string $descricaoServico = null;
    /**
     * Código NBS (Nomenclatura Brasileira de Serviços).
     */
    #[MapFrom('cNBS')]
    public ?string $codigoNbs = null;
    /**
     * Código CNAE (Classificação Nacional de Atividades Econômicas).
     */
    #[MapFrom('cCNAE')]
    public ?string $codigoCnae = null;
    /**
     * Código interno do serviço no sistema do contribuinte.
     */
    #[MapFrom('cIntContrib')]
    public ?string $codigoInternoContribuinte = null;
}
