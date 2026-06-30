<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class DpsData extends Dto
{
    #[MapFrom('@attributes.versao')]
    public ?string $versao = null;
    #[MapFrom('infDPS')]
    public ?\Nfse\Dto\Nfse\InfDpsData $infDps = null;
}
