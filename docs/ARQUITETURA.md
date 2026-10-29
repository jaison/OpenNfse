# Arquitetura do OpenNfse

## Objetivo

Addon para WHMCS que emite NFS-e pela API Nacional usando a SDK oficial `nfse-nacional/nfse-php`. Hooks e telas nunca chamam a SDK diretamente: toda chamada passa por um service e pelo adapter da SDK.

```text
Hook / tela admin / área do cliente → Controller → Service → Adapter → SDK → API Nacional
```

Os fluxos (emissão, fila, cron, cancelamento etc.) estão em [FLUXOS.md](FLUXOS.md).

## Entry points

Todos ficam em `modules/addons/OpenNfse/`:

- [`OpenNfse.php`](../OpenNfse.php): registro do addon (`opennfse_config`), ativação (roda as migrations), saída do admin (`opennfse_output` → `AdminController`) e área do cliente (`opennfse_clientarea` → `ClientController`).
- [`hooks.php`](../hooks.php): registra os hooks do WHMCS:
  - `AdminInvoicesControlsOutput`: botões e status da NFS-e na fatura do admin (`InvoiceHook`).
  - `InvoicePaid`: enfileira a emissão automática (`InvoicePaidHook`).
  - `AfterCronJob`: roda o cron do módulo junto com o cron do WHMCS (`CronService::runFromWhmcsCron()`).
  - `ClientAreaPageViewInvoice`: resumo da NFS-e na fatura da área do cliente (`ClientInvoiceHook`).
  - `ClientAreaPrimaryNavbar`: item "Notas Fiscais" no menu Billing.
  - `AdminAreaHeadOutput` e `ClientAreaHeadOutput`: incluem `assets/nfse-ui.css`.
- [`cron.php`](../cron.php): execução avulsa do cron pela CLI (`CronService::run()`). Recusa acesso que não seja CLI.
- [`bootstrap.php`](../bootstrap.php): carrega `vendor-scoped/autoload.php`; se não existir, `vendor/autoload.php`; se também não existir, o autoload interno `src/Autoload.php`. Depois carrega `functions.php`.
- [`functions.php`](../functions.php): funções globais auxiliares (`normalizarNome`, `getIbgeCode`).
- [`src/Module.php`](../src/Module.php): fábrica simples dos controllers, hooks, migrator e cron.

## Camadas

### Controllers (`src/Controllers/`)

- `AdminController`: roteador do admin. Cada action declara o método HTTP (`GET`/`POST`) e se exige token CSRF. Delega às classes abaixo e renderiza o dashboard.
- `ConfigController`: configuração geral, certificado (validação e teste de conexão), DANFS-e, catálogo Serviço/NBS, mapeamento por grupo de produto, sincronização IBGE e verificação de atualização.
- `QueueController`: tela da fila e ações `filaCheckNow`, `filaRetry` e `filaProcessNow`.
- `NotasController`: emissão, reemissão, consulta de status, cancelamento, downloads e envio por e-mail a partir da fatura.
- `ReportsController`: relatórios, auditorias, logs, exportações CSV/ZIP e cancelamento de XML órfão.
- `SequenciaisController`: consulta e ajuste manual dos sequenciais de DPS.
- `ClientController`: área do cliente (lista de notas emitidas, download de XML/PDF e envio por e-mail).
- `Support/AdminHelpersTrait`: utilitários compartilhados pelos controllers do admin.

### Hooks (`src/Hooks/`)

- `InvoiceHook`: monta o bloco da NFS-e na fatura do admin.
- `InvoicePaidHook`: decide se a fatura paga entra na fila.
- `ClientInvoiceHook`: monta o resumo da NFS-e na fatura da área do cliente.

### Services (`src/Services/`)

Emissão e fila:

- `NfseService`: orquestra emissão, consulta de status e cancelamento (inclusive de XML órfão). Também reenvia a DPS após indisponibilidade da API e faz a reemissão controlada após E2404.
- `DpsBuilderService`: monta o `DpsData` da SDK a partir da fatura, do cliente e da configuração.
- `DpsLayoutService`: escolhe o leiaute da DPS (Pré-NT 009 ou NT 009) conforme a configuração **Leiaute da DPS**, o ambiente e a data, e gera a orientação para a rejeição E1235 por leiaute.
- `QueueService`: enfileira e processa a fila (emissão sequencial e consultas `WAIT_STATUS`).
- `QueueErrorClassifierService`: identifica indisponibilidade transitória da API e decide se um erro admite nova tentativa.
- `EmissionEligibilityService`: elegibilidade da fatura (status `Paid`, pagamento só com crédito, gateway habilitado).
- `EmissionPreValidationService`: validação preventiva de configuração, fatura e tomador antes de montar a DPS.
- `InvoiceFinancialsService`: total, crédito aplicado e detecção de pagamento só com crédito.
- `CronService`: ciclo do cron (fila, consulta de notas `PROCESSANDO`, recuperação de chave, novas tentativas do e-mail automático, verificação de atualização e limpeza por retenção).
- `CronLockService`: lock nomeado no MySQL (`GET_LOCK`/`RELEASE_LOCK`) que impede duas execuções simultâneas do cron.

Arquivos, PDF e e-mail:

- `StorageService`: grava e localiza XMLs em `attachments/nfse/xml/...`, valida caminhos antes de servir arquivos e, quando o `xml_path` salvo não resolve, localiza o XML da própria fatura conferindo o conteúdo com a nota.
- `DanfsePdfService`: gera o PDF do DANFS-e sob demanda a partir do XML salvo, com a personalização configurada.
- `LocalNfsePdfGenerator`: desenha o layout do DANFS-e (TCPDF).
- `InvoiceEmailService`: monta os anexos (XML salvo e PDF gerado) e envia ao cliente pelo módulo de e-mail do WHMCS (`whmcs_provider`) ou, sem ele, pelo `sendmessage()`.
- `EmailTemplateService`: monta assunto e corpo do e-mail da NFS-e.
- `AutoEmailService`: envio automático do e-mail da NFS-e, uma vez por chave de acesso, disparado quando a nota passa a `EMITIDA`, e novas tentativas depois de falha (pelo cron).
- `AutomaticEmailPolicy`: regras puras do envio automático (transição para `EMITIDA`, motivos para não enviar e agenda das novas tentativas).
- `InvoiceHistoryService`: registra eventos da NFS-e nas notas da fatura (`tblinvoices.notes`).

Auditoria e apoio:

- `ApiAuditService`: sincroniza a distribuição de documentos da API por NSU (tela `auditoria_api` do `ReportsController`, que hoje não está na lista de abas do relatório).
- `DpsApiAuditService`: confere as DPS de uma competência diretamente na API e procura lacunas na sequência (relatório "Auditoria DPS > API").
- `IbgeService`: catálogo local de municípios IBGE (sincronização a partir da fonte primária, com aprovação de hash) e resolução do código IBGE por nome/UF, com ViaCEP como apoio pelo CEP.
- `UpdateCheckService`: lê o manifesto `update.json` do repositório e informa se há versão nova. A checagem é manual ou automática, pelo cron, a cada 24 h.
- `CryptoService`: criptografa e descriptografa a senha do certificado com `encrypt`/`decrypt` do WHMCS.
- `TokenService`: token CSRF das actions.
- `UiService`: cabeçalho, rodapé e mensagens das telas do admin.

### Adapter da SDK (`src/Api/`)

- `SdkAdapterInterface` e `NfsePhpSdkAdapter` são os únicos pontos que instanciam a SDK. Convertem retornos e exceções nos DTOs de `src/Dto/`: `EmitirResult`, `ConsultarNfseResult`, `ConsultarDpsResult` e `CancelarResult`. Detalhes em [LEVANTAMENTO_SDK.md](LEVANTAMENTO_SDK.md).

### Repositories (`src/Repositories/`)

| Repository | Tabela(s) |
|---|---|
| `ConfigRepository` | `mod_opennfse_config` |
| `NotaRepository` | `mod_opennfse_notas` |
| `FiscalHistoryRepository` | `mod_opennfse_notas_history` |
| `QueueRepository` | `mod_opennfse_queue` |
| `LogRepository` | `mod_opennfse_logs` |
| `SequenceRepository` | `mod_opennfse_sequences` |
| `GroupServiceCodeRepository` | `mod_opennfse_group_service_codes` |
| `ServiceNbsCatalogRepository` | `mod_opennfse_service_nbs_catalog` |
| `PaymentGatewaySettingsRepository` | `mod_opennfse_payment_gateway_settings` |
| `IbgeMunicipioRepository` | `mod_opennfse_ibge_municipios` |
| `ApiAuditRepository` | `mod_opennfse_nsu_sync`, `mod_opennfse_distribuicao_dfe` |
| `DpsApiAuditRepository` | `mod_opennfse_dps_audit_runs`, `mod_opennfse_dps_audit_results` |
| `ReportRepository` | consultas dos relatórios e do dashboard |
| `WhmcsInvoiceRepository` | `tblinvoices`, `tblinvoiceitems` |
| `WhmcsCustomerRepository` | `tblclients`, `tblcustomfieldsvalues` |
| `WhmcsPaymentGatewayRepository` | `tblpaymentgateways` |

O uso dos campos do WHMCS está em [MAPEAMENTO_DADOS_WHMCS.md](MAPEAMENTO_DADOS_WHMCS.md).

### Helpers (`src/Helpers/`)

- `NfseXmlExtractor`: extrai número, competência, data de emissão e chave do XML da NFS-e.
- `CorrelationIdGenerator`: gera o `correlation_id` que liga fila e logs de uma mesma operação.
- `ActionFormRenderer`: formulários `POST` com token para as ações das telas.
- `NameNormalizer` e `UfNormalizer`: normalização de nomes de município e UF.

### Migrations

- [`src/Migrations/Migrator.php`](../src/Migrations/Migrator.php) executa em ordem todos os arquivos de [`migrations/`](../migrations/). Erros de coluna, tabela ou índice já existentes são ignorados, então rodar de novo é seguro.
- O migrator roda na ativação do addon e também no início do cron, da fila, da emissão, do hook `InvoicePaid` e da área do cliente. Por isso, ao atualizar os arquivos do addon, as migrations novas são aplicadas sozinhas.

## Status internos

Status da nota (`mod_opennfse_notas.status`):

| Status | Significado |
|---|---|
| `PROCESSANDO` | DPS enviada e sem resultado final, ou API indisponível com reenvio pendente. |
| `EMITIDA` | NFS-e autorizada. A chave de acesso pode chegar depois, pela consulta. |
| `REJEITADA` | A API recusou a DPS (erro de validação ou de regra). |
| `ERRO` | Falha técnica na emissão ou erro na consulta de status. |
| `CANCELADA` | Evento de cancelamento 101101 registrado na API. |

Status da fila (`mod_opennfse_queue.status`): `PENDING`, `RUNNING`, `WAIT_STATUS`, `DONE`, `ERROR` e `RESOLVIDO`. As transições estão em [FLUXOS.md](FLUXOS.md#estados-da-fila).

## Armazenamento

- XML de emissão e de cancelamento: `attachments/nfse/xml/{ambiente}/{serie}/{ano}/{mes}/`, com nomes `nfse_{numero}_{invoiceid}_{mmyyyy}.xml` e `cancelamento_nfse_{numero}_{invoiceid}_{yyyymmdd_hhmmss}.xml`. Os caminhos relativos ficam em `mod_opennfse_notas.xml_path` e `cancel_xml_path`.
- `attachments` é o diretório configurado no WHMCS (`$attachments_dir`) ou, sem essa configuração, `<whmcs>/attachments`.
- Fallback do XML: se o `xml_path` salvo não resolver na geração do PDF, `StorageService::findLatestXmlAbsolutePath()` só considera arquivos cujo nome contém o ID exato da fatura e só aceita o XML cuja chave de acesso e/ou número da NFS-e confere com a nota. Encontrado o arquivo, o `xml_path` é regravado. Sem chave nem número na nota, não há fallback.
- PDF do DANFS-e: gerado a cada download ou envio, sem cópia em disco.
- Logs técnicos: `mod_opennfse_logs` (tipo, request, response e `correlation_id`).
- Histórico fiscal: `mod_opennfse_notas_history` guarda cada emissão, cancelamento e snapshot. Já `mod_opennfse_notas` tem uma única linha por fatura, sobrescrita numa reemissão.

## Controles de segurança

- Actions que alteram dados e downloads sensíveis exigem `POST` e token CSRF.
- Arquivos persistidos só são servidos depois de o caminho ser validado pelo `StorageService`.
- O caminho do certificado A1 precisa ficar fora do webroot.
- Os anexos de e-mail são montados pelo serviço de e-mail a partir dos arquivos do módulo.
- O SVG do cabeçalho do DANFS-e é sanitizado, e o XML é lido com `LIBXML_NONET`.
- Os `.htaccess` da raiz e de `assets/` bloqueiam o acesso web direto aos arquivos do addon.

Regras operacionais e checklist de produção em [SEGURANCA.md](SEGURANCA.md).
