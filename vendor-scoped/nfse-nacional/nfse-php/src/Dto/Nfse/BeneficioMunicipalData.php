<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class BeneficioMunicipalData extends Dto
{
    /**
     * Código de identificação do Benefício Municipal.
     */
    #[MapFrom('nBM')]
    public ?string $codigoBeneficio = null;
    /**
     * Percentual de redução da base de cálculo referente ao benefício municipal.
     */
    #[MapFrom('pRedBCBM')]
    public ?float $percentualReducaoBcBm = null;
    /**
     * Valor monetário de redução da base de cálculo referente ao benefício municipal.
     */
    #[MapFrom('vRedBCBM')]
    public ?float $valorReducaoBcBm = null;
}
