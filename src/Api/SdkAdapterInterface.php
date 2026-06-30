<?php

declare(strict_types=1);

namespace OpenNfse\Api;

use OpenNfse\Dto\EmitirResult;
use OpenNfse\Dto\CancelarResult;
use OpenNfse\Dto\ConsultarDpsResult;
use OpenNfse\Dto\ConsultarNfseResult;

interface SdkAdapterInterface
{
    public function emitir(array $sdkConfig, object $dpsData): EmitirResult;

    public function consultarNfse(array $sdkConfig, string $chaveAcesso): ConsultarNfseResult;

    public function consultarDps(array $sdkConfig, string $idDps): ConsultarDpsResult;

    public function cancelarNfse(array $sdkConfig, object $eventoData): CancelarResult;
}
