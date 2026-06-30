<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class NfseData extends Dto
{
    /**
     * Versão do leiaute.
     */
    #[MapFrom('@attributes.versao')]
    public ?string $versao = null;
    /**
     * Informações da NFS-e.
     */
    #[MapFrom('infNFSe')]
    public ?\Nfse\Dto\Nfse\InfNfseData $infNfse = null;
    /**
     * Informações do Evento.
     */
    #[MapFrom('infEvento')]
    public ?\Nfse\Dto\Nfse\InfEventoData $infEvento = null;
    /**
     * XML original retornado pela API da SEFIN Nacional.
     */
    public ?string $nfseXml = null;
}
