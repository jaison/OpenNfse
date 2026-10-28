# Uso da SDK `nfse-nacional/nfse-php`

Como o OpenNfse usa a SDK oficial da NFS-e Nacional, no papel de **contribuinte (emissor)**. A versão exigida está em [`composer.json`](../composer.json).

## Versão e patches

- A SDK está fixada no commit `b89bf93` da `main` (`dev-main#b89bf93591e52671ddffb245c5d1e1100eea7198`), que traz o `finNFSe` da NT 009 (PR #65) e ainda não tem release.
- O plugin `cweagans/composer-patches` (1.7.3) aplica os patches de [`patches/`](../patches/) no `vendor/` durante o `composer install`/`composer update`. A lista fica em `extra.patches` do `composer.json`, na ordem em que os patches são aplicados. Com `composer-exit-on-patch-failure`, o Composer para se algum patch não aplicar.

| Patch | O que faz |
|---|---|
| `nfse-php-01-vservmoeda-duas-casas.patch` | Grava `vServMoeda` com duas casas decimais, como exige o tipo `TSDec15V2` do XSD. |
| `nfse-php-02-tls-verificacao.patch` | Verificação TLS de cadeia e nome nos clients SEFIN, ADN e CNC. O endpoint de produção de Catanduva é acessado pelo nome do certificado, com o IP fixado via `CURLOPT_RESOLVE`. |
| `nfse-php-03-connect-timeout.patch` | Connect timeout de 10s nos clients. |
| `nfse-php-04-nt009-ordem-campos.patch` | Ordem de `xDescServ` e `finNFSe` no leiaute da NT 009, conferida com o XSD da produção restrita. |

- O `vendor-scoped/` é gerado depois dos patches (`composer run scope`), e o `vendor-scoped/nfse-nacional/nfse-php/PATCHES.txt` lista os patches aplicados. O plugin fica fora do `vendor-scoped/` ([`scoper.inc.php`](../scoper.inc.php)).
- **Voltar para uma tag estável**: quando sair a release que inclua o PR #65, troque a versão no `composer.json` (ex.: `^1.24`), confira se cada patch ainda se aplica ou já foi incorporado (remova de `extra.patches` os que não forem mais necessários), rode `composer update nfse-nacional/nfse-php`, `composer test` e `composer run scope`.

## Rede, TLS e timeouts

- Os clients da SDK (SEFIN, ADN e CNC) usam Guzzle com cURL, apresentam o certificado A1 do emissor e verificam o certificado TLS do servidor (cadeia e nome) com o pacote de CAs do sistema ou o `curl.cainfo` do `php.ini`.
- Timeouts: connect de 10s e total de 60s por requisição. O total de 60s não corta um POST de emissão que ainda está em processamento.
- Retry da SDK: só em `GET`/`HEAD`, até 2 novas tentativas em falha de conexão ou HTTP 502, 503 ou 504. O `POST` de emissão não é repetido pela SDK. O reenvio da mesma DPS após indisponibilidade é feito pelo módulo (ver [FLUXOS.md](FLUXOS.md#indisponibilidade-da-api-503-e-timeout)).
- O botão **Testar conexão** da configuração faz uma consulta real à API e é a forma de conferir certificado, rede e CAs depois de instalar ou atualizar.

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
| `\Nfse\Dto\Nfse\DpsData` | DPS montada por `DpsBuilderService::build()`, no leiaute escolhido pelo `DpsLayoutService` (ver [LEVANTAMENTO_API_NACIONAL.md](LEVANTAMENTO_API_NACIONAL.md#leiaute-da-dps-nt-009)). |
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
