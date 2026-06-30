<?php

namespace Nfse\Dto\Http;

use Nfse\Dto\Dto;
class ResultadoConsultaConfiguracoesConvenioResponse extends Dto
{
    public ?string $mensagem = null;
    public ?\Nfse\Dto\Http\ParametrosConfiguracaoConvenioDto $parametrosConvenio = null;
}
