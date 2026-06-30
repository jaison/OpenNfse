<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class ConfirmacaoTomadorData extends Dto
{
    #[MapFrom('xDesc')]
    public ?string $descricao = null;
}
