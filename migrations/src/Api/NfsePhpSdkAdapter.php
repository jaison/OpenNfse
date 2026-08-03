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

    public function baixarDfe(array $sdkConfig, int $nsu, ?string $cnpjConsulta = null, bool $lote = true): array
    {
        $this->assertSdkAvailable();

        try {
            $nfse = $this->makeSdk($sdkConfig);
            $service = $nfse->contribuinte();
            $resp = $service->baixarDfe($nsu, $cnpjConsulta, $lote);

            $items = [];
            foreach ((array) ($resp->lNSU ?? []) as $item) {
                $items[] = [
                    'nsu' => (int) ($item->nsu ?? 0),
                    'chave_acesso' => isset($item->chAcesso) ? (string) $item->chAcesso : null,
                    'dfe_xml_gzip_b64' => isset($item->dfeXmlGZipB64) ? (string) $item->dfeXmlGZipB64 : null,
                ];
            }

            return [
                'success' => true,
                'ultimo_nsu' => isset($resp->ultNSU) ? (int) $resp->ultNSU : $nsu,
                'maior_nsu' => isset($resp->maiorNSU) ? (int) $resp->maiorNSU : null,
                'dh_proc' => isset($resp->dhProc) ? (string) $resp->dhProc : null,
                'items' => $items,
                'alertas' => $this->mapMessages((array) ($resp->alertas ?? [])),
                'errors' => $this->mapMessages((array) ($resp->erros ?? [])),
            ];
        } catch (\Nfse\Http\Exceptions\NfseApiException $e) {
            return [
                'success' => false,
                'ultimo_nsu' => $nsu,
                'maior_nsu' => null,
                'items' => [],
                'error_message' => $e->getMessage(),
                'raw_response' => $e->getRawResponse(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'ultimo_nsu' => $nsu,
                'maior_nsu' => null,
                'items' => [],
                'error_message' => $e->getMessage(),
                'raw_response' => null,
            ];
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

    private function mapMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if (is_object($message)) {
                $out[] = [
                    'codigo' => property_exists($message, 'codigo') ? (string) ($message->codigo ?? '') : '',
                    'mensagem' => property_exists($message, 'mensagem') ? (string) ($message->mensagem ?? '') : '',
                    'descricao' => property_exists($message, 'descricao') ? (string) ($message->descricao ?? '') : '',
                    'complemento' => property_exists($message, 'complemento') ? (string) ($message->complemento ?? '') : '',
                ];
            }
        }

        return $out;
    }
}
