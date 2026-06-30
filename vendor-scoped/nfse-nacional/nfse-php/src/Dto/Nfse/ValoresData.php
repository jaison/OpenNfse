<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class ValoresData extends Dto
{
    /**
     * Valor do serviço prestado.
     */
    #[MapFrom('vServPrest')]
    public ?\Nfse\Dto\Nfse\ValorServicoPrestadoData $valorServicoPrestado = null;
    /**
     * Descontos condicionados e incondicionados.
     */
    #[MapFrom('vDescCondIncond')]
    public ?\Nfse\Dto\Nfse\DescontoData $desconto = null;
    /**
     * Deduções e reduções da base de cálculo.
     */
    #[MapFrom('vDedRed')]
    public ?\Nfse\Dto\Nfse\DeducaoReducaoData $deducaoReducao = null;
    /**
     * Informações sobre a tributação do serviço.
     */
    #[MapFrom('trib')]
    public ?\Nfse\Dto\Nfse\TributacaoData $tributacao = null;
}
