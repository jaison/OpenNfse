<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class RejeicaoPrestadorData extends Dto
{
    #[MapFrom('xDesc')]
    public ?string $descricao = null;
    #[MapFrom('infRej.cMotivo')]
    public ?string $codigoMotivo = null;
    #[MapFrom('infRej.xMotivo')]
    public ?string $motivo = null;
}
