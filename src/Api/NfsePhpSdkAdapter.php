<?php

declare(strict_types=1);

namespace OpenNfse\Api;

use OpenNfse\Dto\CancelarResult;
use OpenNfse\Dto\ConsultarDpsResult;
use OpenNfse\Dto\ConsultarNfseResult;
use OpenNfse\Dto\EmitirResult;

final class NfsePhpSdkAdapter implements SdkAdapterInterface
{
    public function emitir(array $sdkConfig, object $dpsData): EmitirResult
    {
        $this->assertSdkAvailable();

        try {
            $nfse = $this->makeSdk($sdkConfig);
            $service = $nfse->contribuinte();
            $nfseData = $service->emitir($dpsData);

            $chave = null;
            if (property_exists($nfseData, 'infNfse') && $nfseData->infNfse && property_exists($nfseData->infNfse, 'chaveAcesso')) {
                $chave = (string) $nfseData->infNfse->chaveAcesso;
            }

            $xml = null;
            if (property_exists($nfseData, 'nfseXml')) {
                $xml = (string) $nfseData->nfseXml;
            }

            $idDps = null;
            if (property_exists($dpsData, 'infDps') && $dpsData->infDps && property_exists($dpsData->infDps, 'id')) {
                $idDps = (string) $dpsData->infDps->id;
            }

            return new EmitirResult(
                success: true,
                errorType: null,
                idDps: $idDps,
                chaveAcesso: $chave,
                nfseXml: $xml
            );
        } catch (\Nfse\Http\Exceptions\NfseApiException $e) {
            return new EmitirResult(
                success: false,
                errorType: 'api',
                errorMessage: $e->getMessage(),
                rawResponse: $e->getRawResponse()
            );
        } catch (\Throwable $e) {
            return new EmitirResult(
                success: false,
                errorType: 'tech',
                errorMessage: $e->getMessage()
            );
        }
    }

    public function consultarNfse(array $sdkConfig, string $chaveAcesso): ConsultarNfseResult
    {
        $this->assertSdkAvailable();

        try {
            $nfse = $this->makeSdk($sdkConfig);
            $service = $nfse->contribuinte();
            $nfseData = $service->consultar($chaveAcesso);
            if ($nfseData === null) {
                return new ConsultarNfseResult(found: false, chaveAcesso: $chaveAcesso);
            }

            $xml = null;
            if (property_exists($nfseData, 'nfseXml')) {
                $xml = (string) $nfseData->nfseXml;
            }

            return new ConsultarNfseResult(found: true, chaveAcesso: $chaveAcesso, nfseXml: $xml);
        } catch (\Nfse\Http\Exceptions\NfseApiException $e) {
            return new ConsultarNfseResult(found: false, chaveAcesso: $chaveAcesso, errorMessage: $e->getMessage(), rawResponse: $e->getRawResponse());
        } catch (\Throwable $e) {
            return new ConsultarNfseResult(found: false, chaveAcesso: $chaveAcesso, errorMessage: $e->getMessage());
        }
    }

    public function consultarDps(array $sdkConfig, string $idDps): ConsultarDpsResult
    {
        $this->assertSdkAvailable();

        try {
            $nfse = $this->makeSdk($sdkConfig);
            $service = $nfse->contribuinte();
            $resp = $service->consultarDps($idDps);
            $chave = null;
            if (property_exists($resp, 'chaveAcesso')) {
                $chave = $resp->chaveAcesso !== null ? (string) $resp->chaveAcesso : null;
            }

            return new ConsultarDpsResult(found: true, idDps: $idDps, chaveAcesso: $chave);
        } catch (\Nfse\Http\Exceptions\NfseApiException $e) {
            return new ConsultarDpsResult(found: false, idDps: $idDps, errorMessage: $e->getMessage(), rawResponse: $e->getRawResponse());
        } catch (\Throwable $e) {
            return new ConsultarDpsResult(found: false, idDps: $idDps, errorMessage: $e->getMessage());
        }
    }

    public function cancelarNfse(array $sdkConfig, object $eventoData): CancelarResult
    {
        $this->assertSdkAvailable();

        try {
            $nfse = $this->makeSdk($sdkConfig);
            $service = $nfse->contribuinte();
            $resp = $service->cancelar($eventoData);

            $xmlB64 = null;
            if (property_exists($resp, 'eventoXmlGZipB64')) {
                $xmlB64 = $resp->eventoXmlGZipB64 !== null ? (string) $resp->eventoXmlGZipB64 : null;
            }

            return new CancelarResult(success: true, eventoXmlGZipB64: $xmlB64);
        } catch (\Nfse\Http\Exceptions\NfseApiException $e) {
            return new CancelarResult(
                success: false,
                errorType: 'api',
                errorMessage: $e->getMessage(),
                rawResponse: $e->getRawResponse()
            );
        } catch (\Throwable $e) {
            return new CancelarResult(
                success: false,
                errorType: 'tech',
                errorMessage: $e->getMessage()
            );
        }
    }

    private function makeSdk(array $sdkConfig): \Nfse\Nfse
    {
        $ambiente = $sdkConfig['ambiente'];
        $certificatePath = $sdkConfig['certificatePath'];
        $certificatePassword = $sdkConfig['certificatePassword'];

        $context = new \Nfse\Http\NfseContext(
            ambiente: $ambiente,
            certificatePath: $certificatePath,
            certificatePassword: $certificatePassword,
            codigoMunicipio: $sdkConfig['codigoMunicipio'] ?? null
        );

        return new \Nfse\Nfse($context);
    }

    private function assertSdkAvailable(): void
    {
        if (!class_exists(\Nfse\Nfse::class)) {
            throw new \RuntimeException('SDK nfse-nacional/nfse-php não encontrada. Instale a pasta vendor do módulo e garanta o autoload.');
        }
    }
}
