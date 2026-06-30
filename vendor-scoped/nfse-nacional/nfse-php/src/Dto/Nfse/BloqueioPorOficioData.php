<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class BloqueioPorOficioData extends Dto
{
    #[MapFrom('xDesc')]
    public ?string $descricao = null;
    #[MapFrom('CPFAgTrib')]
    public ?string $cpfAgenteTributario = null;
    #[MapFrom('xMotivo')]
    public ?string $motivo = null;
    #[MapFrom('codEvento')]
    public ?string $codigoEvento = null;
}
