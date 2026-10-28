# API Nacional da NFS-e no OpenNfse

O que o módulo usa da API Nacional da NFS-e, no papel de **contribuinte/emissor**: emissão, consulta, cancelamento e distribuição de documentos. As chamadas passam pela SDK (ver [LEVANTAMENTO_SDK.md](LEVANTAMENTO_SDK.md)), e o comportamento do módulo diante de cada resposta está em [FLUXOS.md](FLUXOS.md).

## DPS e NFS-e

- **DPS (Declaração de Prestação de Serviços)**: documento enviado pelo emissor, assinado com o certificado A1. Leva prestador, tomador, serviço, valores e tributação. É identificada pelo `Id` (`id_dps`), formado a partir de CNPJ, município, série e número sequencial.
- **NFS-e**: documento fiscal gerado pela API a partir da DPS. Tem chave de acesso e XML próprio, que o módulo guarda e usa para listagens, relatórios e geração do DANFS-e.

No módulo:

- a DPS é montada em [`src/Services/DpsBuilderService.php`](../src/Services/DpsBuilderService.php);
- o número da DPS vem de `mod_opennfse_sequences`, por ambiente, CNPJ e série;
- o XML da NFS-e é salvo por `StorageService` em `attachments/nfse/xml/{ambiente}/{serie}/{ano}/{mes}/`.

## Ambientes

| `tpAmb` | Ambiente | Configuração (`environment`) |
|---|---|---|
| `1` | Produção | `producao` |
| `2` | Homologação (produção restrita) | `homologacao` |

O ambiente define o `tpAmb` da DPS e do evento de cancelamento e o `TipoAmbiente` passado à SDK, que resolve o endpoint. A sequência de DPS e a pasta de XMLs são separadas por ambiente. Em homologação, o DANFS-e recebe o aviso "NFS-e SEM VALIDADE JURÍDICA".

Diferenças práticas: homologação pode ter validações e tempos de resposta diferentes de produção, e as notas emitidas lá não têm valor fiscal. Comece sempre por homologação.

## Operações usadas

| Operação | Quando o módulo usa |
|---|---|
| Emitir NFS-e a partir da DPS | Emissão, reemissão, reenvio da mesma DPS após indisponibilidade e reemissão após E2404. |
| Consultar NFS-e pela chave de acesso | Consulta de status quando a nota já tem chave: obtém o XML. |
| Consultar DPS pelo `Id` | Consulta de status sem chave (obtém a chave), auditoria DPS > API, cancelamento de XML órfão sem chave e teste de conexão. |
| Registrar evento (cancelamento 101101) | Cancelamento pelo admin e de XML órfão. |
| Distribuição de DF-e por NSU | Auditoria API (`ApiAuditService::syncByNsu()`). |

### Emissão

1. O módulo valida configuração, fatura e tomador e monta a DPS.
2. A SDK envia a DPS assinada.
3. A API pode devolver a NFS-e na hora (chave e XML) ou responder sem o documento final, e então o resultado vem pelas consultas seguintes.
4. O módulo grava `id_dps`, `chave_acesso`, status interno, `xml_path`, número, competência e data de emissão, além dos logs de request e response.

### Consulta

- **Com chave de acesso**: consulta a NFS-e e salva o XML retornado.
- **Sem chave, com `id_dps`**: consulta a DPS para obter a chave. Depois disso, a próxima consulta usa a chave.

### Cancelamento (evento 101101)

Regras aplicadas pelo módulo antes de enviar:

- só para nota `EMITIDA` com chave;
- `cMotivo`: `1`, `2` ou `9` (o `3` é convertido para `9`);
- `xMotivo`: de 15 a 255 caracteres, composto como "motivo - descrição";
- `xDesc` fixo: "Cancelamento de NFS-e";
- autor do evento: o CNPJ do emissor configurado.

O XML do evento devolvido pela API (`eventoXmlGZipB64`) é salvo como `cancelamento_nfse_{numero}_{invoiceid}_{data}.xml`.

## Respostas tratadas de forma especial

- **Indisponibilidade transitória** (503, timeout, falha de conexão): na emissão, a nota fica `PROCESSANDO` e a mesma DPS é reenviada, com o mesmo `id_dps`, na consulta seguinte. Na consulta, o status atual é preservado.
- **E2404** ("não foi gerada uma NFS-e com o identificador de DPS informado"), na consulta da DPS: o módulo reenvia a mesma DPS até 2 vezes antes de marcar `ERRO`.
- Demais erros da API na emissão → `REJEITADA`. Erros técnicos → `ERRO`.

Detalhes em [FLUXOS.md](FLUXOS.md#indisponibilidade-da-api-503-e-timeout).

## Status interno x API

A API tem códigos próprios (mensagens e códigos `E...`). O módulo resume o estado em `PROCESSANDO`, `EMITIDA`, `REJEITADA`, `ERRO` e `CANCELADA` (ver [ARQUITETURA.md](ARQUITETURA.md#status-internos)). A mensagem da API fica em `mod_opennfse_notas.erro_api` (ou `cancel_erro`) e, completa, em `mod_opennfse_logs`.

## Campos da DPS preenchidos pelo módulo

Visão prática do `DpsBuilderService` atual. O leiaute oficial está nos schemas da SDK.

- **Identificação**: `tpAmb`, `dhEmi`, `verAplic`, `serie`, `nDPS`, `dCompet` (data da emissão), `tpEmit = 1` (prestador) e `cLocEmi`.
- **Prestador** (`prest`): `CNPJ`, `IM` (quando configurado para informar), `fone` e `email` (quando preenchidos) e `regTrib` (`opSimpNac`, `regApTribSN`, `regEspTrib`). Como `tpEmit = 1`, nome e endereço do prestador não são enviados.
- **Tomador** (`toma`):
  - Brasil: `CPF` ou `CNPJ` (custom field), `xNome`, `email` e, quando há CEP, `end/endNac` (`cMun` resolvido pelo catálogo IBGE ou pelo padrão configurado) com `xLgr`, `nro`, `xCpl` e `xBairro`;
  - exterior: `cNaoNIF = 2`, `xNome`, `email` e `end/endExt` (`cPais`, `cEndPost`, `xCidade`, `xEstProvReg`).
- **Serviço** (`serv`): `locPrest/cLocPrestacao`, `cServ/cTribNac`, `cServ/cNBS` e `cServ/xDescServ` (descrição montada com o número da fatura e os itens, limitada a 1900 caracteres). Para tomador no exterior, também `comExt`, com `tpMoeda` mapeado da moeda do cliente e `vServMoeda`.
- **Valores**: `vServPrest/vServ` (total da fatura), `trib/tribMun` (`tribISSQN`, `tpRetISSQN`, `pAliq` conforme o regime do Simples) e `trib/totTrib`.

Validações feitas antes do envio (`EmissionPreValidationService` e `DpsBuilderService`): certificado configurado e existente, CNPJ com 14 dígitos, código IBGE com 7 dígitos, série, código de serviço, IM quando exigida, opção do Simples Nacional, total maior que zero, fatura com itens, nome, e-mail, país e documento do tomador, endereço completo do tomador no exterior, NBS com 9 dígitos e NBS obrigatória para tomador no exterior.

## Referências

- Contratos OpenAPI (homologação e produção), schemas XSD e manuais oficiais embarcados na SDK: [`vendor-scoped/nfse-nacional/nfse-php/references/`](../vendor-scoped/nfse-nacional/nfse-php/references/)
- SDK: <https://github.com/nfse-nacional/nfse-php>
