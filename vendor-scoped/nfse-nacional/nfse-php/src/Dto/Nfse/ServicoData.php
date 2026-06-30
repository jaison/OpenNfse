<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class ServicoData extends Dto
{
    /**
     * Local da prestação do serviço.
     */
    #[MapFrom('locPrest')]
    public ?\Nfse\Dto\Nfse\LocalPrestacaoData $localPrestacao = null;
    /**
     * Código do serviço prestado.
     */
    #[MapFrom('cServ')]
    public ?\Nfse\Dto\Nfse\CodigoServicoData $codigoServico = null;
    /**
     * Informações de comércio exterior.
     */
    #[MapFrom('comExt')]
    public ?\Nfse\Dto\Nfse\ComercioExteriorData $comercioExterior = null;
    /**
     * Informações da obra.
     */
    #[MapFrom('obra')]
    public ?\Nfse\Dto\Nfse\ObraData $obra = null;
    /**
     * Informações de atividade/evento.
     */
    #[MapFrom('atvEvento')]
    public ?\Nfse\Dto\Nfse\AtividadeEventoData $atividadeEvento = null;
    /**
     * Informações complementares do serviço.
     */
    #[MapFrom('infoCompl')]
    public ?\Nfse\Dto\Nfse\InfoComplData $informacaoComplemento = null;
}
