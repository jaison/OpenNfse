<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class IbscbsData extends Dto
{
    /**
     * Indicador da operação de fornecimento favorecido com alíquota zero de CBS.
     * 0 - Não
     * 1 - Sim
     */
    #[MapFrom('indZFMALC')]
    public ?int $indicadorZfmAlc = null;
}
