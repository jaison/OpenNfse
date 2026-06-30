<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class AnulacaoRejeicaoData extends Dto
{
    #[MapFrom('xDesc')]
    public ?string $descricao = null;
    #[MapFrom('infAnRej.CPFAgTrib')]
    public ?string $cpfAgenteTributario = null;
    #[MapFrom('infAnRej.idEvManifRej')]
    public ?string $idEventoManifestacaoRejeicao = null;
    #[MapFrom('infAnRej.xMotivo')]
    public ?string $motivo = null;
}
