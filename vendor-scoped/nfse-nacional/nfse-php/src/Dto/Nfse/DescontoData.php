<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class DescontoData extends Dto
{
    /**
     * Valor do desconto incondicionado.
     */
    #[MapFrom('vDescIncond')]
    public ?float $valorDescontoIncondicionado = null;
    /**
     * Valor do desconto condicionado.
     */
    #[MapFrom('vDescCond')]
    public ?float $valorDescontoCondicionado = null;
}
