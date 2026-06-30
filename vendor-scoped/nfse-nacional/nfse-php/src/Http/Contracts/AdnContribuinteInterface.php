<?php

namespace Nfse\Http\Contracts;

use Nfse\Dto\Http\DistribuicaoDfeResponse;
interface AdnContribuinteInterface
{
    public function distribuirPorNsu(string $inscricaoMunicipal, int $nsu): DistribuicaoDfeResponse;
    public function distribuirPorChave(string $inscricaoMunicipal, string $chaveAcesso): DistribuicaoDfeResponse;
}
