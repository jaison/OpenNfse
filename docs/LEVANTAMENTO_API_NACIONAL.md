# Levantamento da API Nacional (NFS-e) para o módulo WHMCS

Este documento consolida o que o módulo precisa conhecer da API Nacional da NFS-e (padrão nacional), com foco nos fluxos de **Contribuinte/Emissor**: emissão, consulta e cancelamento.

## Visão geral (DPS x NFS-e)

- **DPS (Declaração de Prestação de Serviços)**: documento “de entrada” enviado pelo emissor. É o payload assinado (via certificado) que representa a prestação e seus dados (tomador, serviço, valores, tributação etc.).
- **NFS-e**: documento fiscal resultante do processamento da DPS. Possui chave de acesso e XML próprio (`NFSe`), utilizado para:
  - download/armazenamento do XML;
  - consulta pública por chave;
  - geração do DANFSe (PDF).

No módulo:
- A DPS é montada usando DTOs da SDK em [DpsBuilderService](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Services/DpsBuilderService.php).
- A NFS-e (XML) retornada é armazenada e usada como fonte de dados para listagem e PDF.

## Ambientes e regras de homologação

- A operação inicia em homologação e não deve impactar produção.
- O ambiente é controlado pelo campo `tpAmb` na DPS:
  - `1` = Produção
  - `2` = Homologação (produção restrita)
- No módulo:
  - `tpAmb` é definido a partir da configuração em [DpsBuilderService::build()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Services/DpsBuilderService.php#L16-L17).
  - O ambiente também define o endpoint no `NfseContext` (via SDK).

## Fluxo completo de emissão (visão funcional)

1. O operador (admin) solicita emissão para uma fatura (invoice).
2. O módulo:
   - lê configuração do emissor;
   - lê invoice e itens;
   - lê dados do cliente e documento (CPF/CNPJ);
   - monta DPS (com `Id`, `tpAmb`, `dhEmi`, `dCompet`, `prest`, `toma`, `serv`, `valores` etc.).
3. A SDK envia a DPS para a API (autenticada/assinada via A1).
4. A API pode:
   - retornar imediatamente a NFS-e (com XML);
   - ou retornar um estado intermediário, exigindo consultas posteriores (via consulta de DPS e/ou consulta de NFS-e).
5. O módulo persiste:
   - `id_dps` (Id da DPS)
   - `chave_acesso` (quando disponível)
   - `status` interno
   - `xml_path` (quando houver XML)
   - logs request/response

## Fluxo completo de consulta

O módulo possui duas estratégias:

### 1) Consultar NFS-e por chave de acesso

Quando existe `chave_acesso`:
- consulta a NFS-e por chave;
- se houver XML, armazena/atualiza o XML.

### 2) Consultar DPS por Id (para obter chave)

Quando ainda não existe `chave_acesso`, mas existe `id_dps`:
- consulta a DPS para descobrir `chave_acesso`;
- quando obtém a chave, passa a consultar a NFS-e.

Referência do fluxo MVP no projeto:
- [FLUXOS.md](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/docs/FLUXOS.md)

## Fluxo completo de cancelamento

Premissas:
- Cancelamento é um **evento** associado a uma NFS-e emitida.
- O módulo usa o fluxo suportado pela SDK (que encapsula as chamadas de evento).

No módulo (regra de negócio já aplicada):
- `xDesc` do evento 101101 deve ser fixo: “Cancelamento de NFS-e”.
- `cMotivo` permitido: `1`, `2` ou `9`.
- `xMotivo` deve cumprir tamanho mínimo/máximo; o módulo compõe a partir de motivo + descrição quando necessário.

## Estados possíveis do processamento

A API possui estados/códigos próprios (por exemplo `cStat` no XML de NFS-e).
O módulo mantém um status interno padronizado para operação:

- `PROCESSANDO`: DPS enviada e ainda sem XML final/estado definitivo.
- `EMITIDA`: XML disponível e/ou chave de acesso registrada.
- `REJEITADA`: rejeição por regra/validação.
- `ERRO`: falha técnica/transitória (transporte, timeout, exceção, parsing etc.).

Definição em docs:
- [ARQUITETURA.md](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/docs/ARQUITETURA.md#L47-L53)

## Campos obrigatórios e opcionais (DPS) — visão prática do módulo

O módulo valida e preenche o mínimo para emissão conforme a configuração atual.

Campos que o módulo trata explicitamente como necessários (validações):
- CNPJ do emissor (14 dígitos)
- Código IBGE do município (7 dígitos)
- Inscrição Municipal (quando configurado para informar IM)
- Código do serviço (`cTribNac`)
- Alíquota/configuração de tributação (com regras conforme Simples Nacional)
- E-mail do tomador
- Total da fatura > 0

Campos preenchidos na DPS (principais, conforme implementação):
- Identificação:
  - `tpAmb`, `dhEmi`, `verAplic`, `serie`, `nDPS`, `dCompet`, `tpEmit`, `cLocEmi`
- Prestador:
  - `CNPJ`, opcionalmente `IM`, `email`, `fone`, `regTrib`, e endereço (dependendo do `tpEmit`)
- Tomador:
  - `CPF`/`CNPJ`, `xNome`, `email`, `end/endNac` quando CEP disponível
- Serviço:
  - `locPrest/cLocPrestacao`, `cServ/cTribNac`, `cServ/xDescServ`
- Valores/Tributação:
  - `valores/vServPrest/vServ`, `valores/trib/tribMun/*`, `totTrib` conforme regime

Fonte (implementação):
- [DpsBuilderService.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Services/DpsBuilderService.php)

## Diferenças entre homologação e produção (impactos no módulo)

- `tpAmb` muda (1/2).
- Endpoint muda (homologação vs produção), resolvido pela SDK.
- Na prática, homologação pode:
  - ter regras adicionais de validação;
  - exigir dados consistentes “de teste”;
  - apresentar respostas e tempos de processamento diferentes.

## Fontes de referência dentro do repositório

- OpenAPI (contratos) embarcados na SDK (homologação/produção):
  - `modules/addons/Nfse/vendor/nfse-nacional/nfse-php/references/api-specs/`
- Schemas XML (DPS, NFSe, Eventos):
  - `modules/addons/Nfse/vendor/nfse-nacional/nfse-php/references/schemas/`

