<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class InfoComplData extends Dto
{
    /**
     * Oc. 0-1
     * Tam 1-40
     * Identificador do documento técnico.
     * Identificador de Documento de Responsabilidade Técnica:
     * ART, RRT, DRT, Outros.
     */
    #[MapFrom('idDocTec')]
    public ?string $idDocumentoTecnico = null;
    /**
     * Oc. 0-1
     * Tam 255
     * Chave da nota, número identificador da nota, número do contrato ou outro
     * identificador de documento emitido pelo prestador de serviços, que subsidia a
     * emissão dessa nota pelo tomador do serviço ou intermediário (preenchimento obrigatório
     * caso a nota esteja sendo emitida pelo Tomador ou intermediário do serviço).
     */
    #[MapFrom('docRef')]
    public ?string $documentoReferencia = null;
    /**
     * Oc. 0-1
     * Tam 255
     * Campo livre para preenchimento pelo contribuinte.
     */
    #[MapFrom('xInfComp')]
    public ?string $informacoesComplementares = null;
}
