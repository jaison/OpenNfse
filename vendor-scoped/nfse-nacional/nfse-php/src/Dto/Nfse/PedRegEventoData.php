<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class PedRegEventoData extends Dto
{
    #[MapFrom('@attributes.Id')]
    public ?string $id = null;
    #[MapFrom('infPedReg')]
    public ?\Nfse\Dto\Nfse\InfPedRegData $infPedReg = null;
    #[MapFrom('versao')]
    public string $versao = '1.01';
}
