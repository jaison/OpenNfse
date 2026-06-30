# Levantamento da SDK `nfse-nacional/nfse-php`

Este documento descreve os pontos principais da SDK oficial utilizada pelo módulo, com foco no uso como **Contribuinte (emissor)**.

## Objetivo e premissas

- O módulo utiliza obrigatoriamente a SDK `nfse-nacional/nfse-php` e não implementa manualmente autenticação/assinatura ou HTTP direto quando a SDK já cobre o fluxo.
- Hooks e UI não chamam a SDK diretamente; toda chamada passa por Adapter:
  - [SdkAdapterInterface.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Api/SdkAdapterInterface.php)
  - [NfsePhpSdkAdapter.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Api/NfsePhpSdkAdapter.php)

## Principais namespaces e classes

### Entrada principal

- `\Nfse\Nfse`
  - Fabrica os serviços (por exemplo: `contribuinte()` e `municipio()`).

### Contexto / configuração

- `\Nfse\Http\NfseContext`
  - Concentra parâmetros de ambiente, certificado e resolução de endpoint.
  - No módulo, é instanciado no Adapter em [NfsePhpSdkAdapter::makeSdk()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Api/NfsePhpSdkAdapter.php#L138-L152).

### Serviços

- `ContribuinteService` (acessado via `\Nfse\Nfse::contribuinte()`)
  - Serviço usado pelo módulo para emissão, consulta, consulta de DPS e cancelamento.
- `MunicipioService` (acessado via `\Nfse\Nfse::municipio()`)
  - Não é utilizado pelo módulo atualmente (voltado a prefeitura/órgão gestor).

### DTOs relevantes (emissão / eventos)

- `\Nfse\Dto\Nfse\DpsData`
  - Representa a DPS a ser enviada.
  - No módulo, é montado em [DpsBuilderService::build()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Services/DpsBuilderService.php#L12-L215).
- `\Nfse\Support\IdGenerator`
  - Gera `Id` da DPS (`generateDpsId(...)`), usado pelo módulo em [DpsBuilderService](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Services/DpsBuilderService.php#L57-L58).
- `\Nfse\Dto\Nfse\PedRegEventoData` e estruturas relacionadas (evento 101101)
  - Usadas para montar o evento de cancelamento (o módulo monta o objeto e chama `ContribuinteService::cancelar()` via Adapter).

## Fluxo de autenticação e certificado (A1)

### Como a SDK autentica

- A autenticação é feita com **certificado A1 (PFX)** informado ao `NfseContext`.
- A SDK faz a assinatura/handshake conforme o contrato, e as requisições são realizadas internamente via HTTP (não há implementação manual no módulo).

### Como o módulo configura o certificado

- Admin informa caminho do `.pfx` e senha.
- O módulo armazena a senha criptografada com recursos do WHMCS (encrypt/decrypt) e só descriptografa no momento de criar o `NfseContext`.
- Ponto de criação da SDK:
  - [NfsePhpSdkAdapter::makeSdk()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Api/NfsePhpSdkAdapter.php#L138-L152)

## Ambientes (Homologação / Produção) e endpoints

- A SDK suporta `TipoAmbiente::Homologacao` e `TipoAmbiente::Producao` via `NfseContext(ambiente: ...)`.
- A resolução de endpoint pode ser:
  - endpoint padrão nacional; ou
  - endpoint específico por município; ou
  - endpoint customizado informado manualmente.
- Referência da própria SDK: [endpoints.md](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/vendor/nfse-nacional/nfse-php/endpoints.md).
- No módulo, passamos `codigoMunicipio` no context para permitir que a SDK aplique mapeamento por IBGE quando aplicável.

## Fluxo de emissão

### Entrada do módulo

- UI/Hook (admin) → Controller → Service → Adapter → SDK.
- O módulo monta a DPS (DTO da SDK) em [DpsBuilderService](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Services/DpsBuilderService.php).

### Chamada na SDK

- `\Nfse\Nfse->contribuinte()->emitir($dpsData)`
- No módulo: [NfsePhpSdkAdapter::emitir()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Api/NfsePhpSdkAdapter.php#L14-L59)

### Retorno esperado

- A SDK retorna um objeto (ex.: `NfseData`) com:
  - `infNfse->chaveAcesso` (quando disponível)
  - `nfseXml` (XML da NFS-e, quando disponível)
- O módulo normaliza isso em `EmitirResult`.

## Fluxo de consulta

### Consultar NFS-e por chave

- `\Nfse\Nfse->contribuinte()->consultar($chaveAcesso)`
- No módulo: [NfsePhpSdkAdapter::consultarNfse()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Api/NfsePhpSdkAdapter.php#L61-L84)

### Consultar DPS por Id

- `\Nfse\Nfse->contribuinte()->consultarDps($idDps)`
- No módulo: [NfsePhpSdkAdapter::consultarDps()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Api/NfsePhpSdkAdapter.php#L86-L105)

## Fluxo de cancelamento

- A SDK expõe cancelamento via método dedicado:
  - `\Nfse\Nfse->contribuinte()->cancelar($eventoData)`
- No módulo: [NfsePhpSdkAdapter::cancelarNfse()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Api/NfsePhpSdkAdapter.php#L107-L136)

## Tratamento de erros

### Exceções principais

- `\Nfse\Http\Exceptions\NfseApiException`
  - Normalmente representa erro retornado pela API/contrato.
  - A SDK fornece uma forma de obter resposta crua; o módulo captura e persiste em log quando disponível.
- Outras `\Throwable`
  - Falhas técnicas (IO, parsing, runtime, etc.).

### Como o módulo normaliza erros

- Cada método do Adapter converte exceções em DTOs de resultado (`EmitirResult`, `ConsultarNfseResult`, `ConsultarDpsResult`, `CancelarResult`) com:
  - `errorType` (`api` ou `tech`)
  - `errorMessage`
  - `rawResponse` (quando a exceção da SDK expõe)

## Dependências relevantes

- A SDK depende de bibliotecas HTTP e componentes internos; no módulo essas dependências vêm no `vendor/` após `composer install`.
- O módulo também inclui `paseto/nfse-nacional-pdf` para PDF, mas isso não faz parte da SDK de emissão.

