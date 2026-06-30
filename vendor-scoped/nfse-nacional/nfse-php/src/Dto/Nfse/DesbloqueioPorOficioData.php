<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class DesbloqueioPorOficioData extends Dto
{
    #[MapFrom('xDesc')]
    public ?string $descricao = null;
    #[MapFrom('CPFAgTrib')]
    public ?string $cpfAgenteTributario = null;
    #[MapFrom('idBloqOfic')]
    public ?string $idBloqueioOficio = null;
}
