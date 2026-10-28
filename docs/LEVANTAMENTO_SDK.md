# Uso da SDK `nfse-nacional/nfse-php`

Como o OpenNfse usa a SDK oficial da NFS-e Nacional, no papel de **contribuinte (emissor)**. A versão exigida está em [`composer.json`](../composer.json).

## Premissas

- O módulo não implementa HTTP, assinatura ou autenticação próprios para a API: tudo passa pela SDK.
- Só o adapter instancia a SDK. Hooks, controllers e services chamam o adapter:
  - [`src/Api/SdkAdapterInterface.php`](../src/Api/SdkAdapterInterface.php)
  - [`src/Api/NfsePhpSdkAdapter.php`](../src/Api/NfsePhpSdkAdapter.php)

## Empacotamento (`vendor-scoped/`)

O release traz as dependências em [`vendor-scoped/`](../vendor-scoped/), geradas pelo `php-scoper` ([`scoper.inc.php`](../scoper.inc.php) e [`tools/build-scoped-vendor.php`](../tools/build-scoped-vendor.php)). As bibliotecas de terceiros recebem o prefixo `OpenNfseVendor\`, para não conflitar com outros módulos do WHMCS. Ficam sem prefixo os namespaces da SDK (`Nfse\`), do gerador de PDF (`NfsePdf\`), do próprio módulo (`OpenNfse\`), as interfaces PSR e as classes globais usadas pelo nome completo, como `\TCPDF`. Por isso o código do módulo usa a SDK com os nomes originais (`\Nfse\Nfse`, `\Nfse\Http\NfseContext` etc.).

## Classes da SDK usadas pelo módulo

| Classe | Uso no módulo |
|---|---|
| `\Nfse\Nfse` | Ponto de entrada. O adapter chama `contribuinte()`. |
| `\Nfse\Http\NfseContext` | Ambiente, certificado e município. Criado em `NfsePhpSdkAdapter::makeSdk()`. |
| `\Nfse\Enums\TipoAmbiente` | `Producao` ou `Homologacao`, conforme `mod_opennfse_config.environment`. |
| `\Nfse\Dto\Nfse\DpsData` | DPS montada por `DpsBuilderService::build()`. |
| `\Nfse\Support\IdGenerator` | Gera o `Id` da DPS (`generateDpsId`). |
| `\Nfse\Dto\Nfse\PedRegEventoData` | Pedido de evento de cancelamento 101101, montado em `NfseService::cancelarNfse()`. |
| `\Nfse\Xml\DpsXmlBuilder` e `\Nfse\Xml\EventosXmlBuilder` | Só para registrar nos logs o XML enviado (`EMISSAO_REQUEST`, `CANCELAMENTO_REQUEST`). |
| `\Nfse\Http\Exceptions\NfseApiException` | Erro devolvido pela API, com `getRawResponse()`. |

O `MunicipioService` da SDK (voltado a prefeituras) não é usado.

## Contexto e certificado

`NfseService::buildSdkConfig()` monta a configuração, e `NfsePhpSdkAdapter::makeSdk()` cria o contexto:

```php
new \Nfse\Http\NfseContext(
    ambiente: $ambiente,               // TipoAmbiente::Producao ou ::Homologacao
    certificatePath: $certificatePath, // caminho do .pfx/.p12
    certificatePassword: $password,    // descriptografada só neste momento
    codigoMunicipio: $codigoIbge       // permite à SDK aplicar endpoint por município
);
```

- A senha do certificado fica criptografada em `mod_opennfse_config.certificate_password_enc` (`CryptoService`, com `encrypt`/`decrypt` do WHMCS).
- A resolução de endpoint (padrão nacional ou específico por município) é feita pela SDK.

## Métodos do adapter

| Adapter | Chamada na SDK | Retorno |
|---|---|---|
| `emitir($config, $dps)` | `contribuinte()->emitir($dps)` | `EmitirResult`: `idDps`, `chaveAcesso`, `nfseXml` |
| `consultarNfse($config, $chave)` | `contribuinte()->consultar($chave)` | `ConsultarNfseResult`: `found`, `nfseXml` |
| `consultarDps($config, $idDps)` | `contribuinte()->consultarDps($idDps)` | `ConsultarDpsResult`: `found`, `chaveAcesso` |
| `cancelarNfse($config, $evento)` | `contribuinte()->cancelar($evento)` | `CancelarResult`: `eventoXmlGZipB64` |
| `baixarDfe($config, $nsu, $cnpj, $lote)` | `contribuinte()->baixarDfe(...)` | array com `ultimo_nsu`, `maior_nsu` e documentos (auditoria por NSU) |

Quem chama cada método:

- `emitir`: `NfseService::emitir()` (emissão, reemissão e reenvio da mesma DPS).
- `consultarNfse`: `NfseService::consultarStatus()`.
- `consultarDps`: `NfseService::consultarStatus()`, cancelamento de XML órfão sem chave no XML, `DpsApiAuditService` e o botão **Testar conexão** da configuração.
- `cancelarNfse`: `NfseService::cancelarNfse()` e `cancelarNfseOrfaPorXmlPath()`.
- `baixarDfe`: `ApiAuditService::syncByNsu()`.

## Tratamento de erros

Cada método do adapter captura as exceções e devolve um DTO (`src/Dto/`), sem deixar a exceção subir:

- `NfseApiException` → `errorType = 'api'`, com `errorMessage` e `rawResponse`.
- Qualquer outro `Throwable` → `errorType = 'tech'`, com `errorMessage`.

Na emissão, `NfseService` converte o resultado em status da nota: erro transitório (503, timeout, falha de conexão) → `PROCESSANDO`; `api` → `REJEITADA`; `tech` → `ERRO`. Ver [FLUXOS.md](FLUXOS.md#nfseserviceemitir).

Se a SDK não estiver carregada, o adapter lança `RuntimeException` com a orientação de instalar as dependências.

## Geração de PDF

O DANFS-e não usa a SDK de emissão. O PDF é gerado localmente a partir do XML salvo, por `DanfsePdfService` e `LocalNfsePdfGenerator` (TCPDF). O pacote `paseto/nfse-nacional-pdf` também é dependência, e [`src/PasetoOverrides/`](../src/PasetoOverrides/) sobrepõe sua classe `NfsePdf\NfsePdfGenerator`.

## Referências da SDK

- Repositório: <https://github.com/nfse-nacional/nfse-php>
- Contratos OpenAPI e schemas XSD embarcados: [`vendor-scoped/nfse-nacional/nfse-php/references/`](../vendor-scoped/nfse-nacional/nfse-php/references/)
- Exemplos de uso: [`vendor-scoped/nfse-nacional/nfse-php/examples/`](../vendor-scoped/nfse-nacional/nfse-php/examples/)
