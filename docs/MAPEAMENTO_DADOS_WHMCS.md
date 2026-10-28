# Mapeamento de dados do WHMCS

Tabelas e campos do WHMCS lidos pelo OpenNfse e como cada um entra na emissão, consulta e cancelamento da NFS-e. Os campos da DPS citados estão em [LEVANTAMENTO_API_NACIONAL.md](LEVANTAMENTO_API_NACIONAL.md#campos-da-dps-preenchidos-pelo-módulo).

## Origem dos dados

| Dado | Tabelas |
|---|---|
| Configuração do emissor e do módulo | `mod_opennfse_config` |
| Tomador | `tblclients` + `tblcustomfieldsvalues` (CPF/CNPJ) |
| Fatura e itens | `tblinvoices`, `tblinvoiceitems` |
| Código de serviço e NBS por grupo de produto | `tblhosting` → `tblproducts.gid` → `mod_opennfse_group_service_codes` |
| Moeda do tomador no exterior | `tblcurrencies` |
| Gateways habilitados | `tblpaymentgateways` + `mod_opennfse_payment_gateway_settings` |

## Tomador (`tblclients`)

Código: [`WhmcsCustomerRepository`](../src/Repositories/WhmcsCustomerRepository.php) e [`DpsBuilderService`](../src/Services/DpsBuilderService.php).

| Campo | Uso |
|---|---|
| `id` | Gravado em `mod_opennfse_notas.userid`. |
| `companyname`, `firstname`, `lastname` | `toma/xNome`: usa `companyname` quando preenchido, senão `firstname lastname`. Sem nenhum dos dois, a emissão é bloqueada. |
| `email` | `toma/email` (obrigatório). |
| `country` | Obrigatório. `BR` exige CPF/CNPJ. Outro país é tratado como exterior: `toma/cNaoNIF = 2` e `toma/end/endExt`. |
| `address1`, `address2`, `city`, `state`, `postcode` | Endereço do tomador (ver regras abaixo). |
| `currency` | Só para tomador no exterior: `tblcurrencies.code` é convertido para o código BACEN em `serv/comExt/tpMoeda` (sem moeda, usa `986`, Real). |

Tomador no Brasil:

- Com CEP (`postcode`), monta `toma/end/endNac` com o `cMun` resolvido pelo catálogo IBGE (cidade/UF, com ViaCEP como apoio). Se não encontrar, usa `tomador_codigo_ibge_padrao` da configuração.
- `xLgr` = `address1`, `xCpl` = `address2`. O número é extraído do logradouro quando possível, senão usa `tomador_numero_padrao` (ou `S/N`). O bairro vem de `tomador_bairro_padrao`.
- Sem CEP, o endereço não é enviado.

Tomador no exterior:

- `address1`, `city`, `state` e `postcode` são obrigatórios e vão para `endExt` (`cPais`, `xCidade`, `xEstProvReg`, `cEndPost`).
- A NBS é obrigatória, porque o módulo monta o grupo `serv/comExt`.

### CPF/CNPJ (custom field)

Código: `WhmcsCustomerRepository::getCpfCnpjFromCustomField()`.

- Lido de `tblcustomfieldsvalues` com `relid = tblclients.id` e `fieldid = mod_opennfse_config.tomador_cpfcnpj_customfield_id`.
- A máscara é removida. O valor precisa ter 11 (CPF) ou 14 (CNPJ) dígitos.
- Obrigatório só para `country = 'BR'`.

## Fatura (`tblinvoices`)

Código: [`WhmcsInvoiceRepository`](../src/Repositories/WhmcsInvoiceRepository.php), [`EmissionEligibilityService`](../src/Services/EmissionEligibilityService.php) e [`InvoiceFinancialsService`](../src/Services/InvoiceFinancialsService.php).

| Campo | Uso |
|---|---|
| `id` | Chave de integração (botões, fila, nota) e referência na descrição do serviço ("Serviços referentes à fatura #id"). O módulo não usa `invoicenum`. |
| `userid` | Liga a fatura ao cliente e confere a posse da fatura na área do cliente. |
| `status` | Emissão só com `Paid`. Exceção: `Unpaid` enfileirada manualmente com `allow_manual_unpaid` ligado (ver [FLUXOS.md](FLUXOS.md#enfileiramento-manual-de-fatura-unpaid)). |
| `total` | `valores/vServPrest/vServ`. Precisa ser maior que zero. |
| `paymentmethod` | Regra de gateway e detecção de pagamento com crédito. |
| `credit` | Detecção de pagamento só com crédito. |
| `datepaid` | Data de pagamento exibida (e usada na ordenação) na auditoria de emissão. |
| `notes` | O módulo acrescenta linhas com os eventos da NFS-e (`InvoiceHistoryService`). |

Pagamento só com crédito (`InvoiceFinancialsService::isCreditOnlyPayment()`): `paymentmethod = 'credit'`, ou `credit > 0` com `total` igual a zero. Nesses casos a emissão é bloqueada.

### Itens (`tblinvoiceitems`)

| Campo | Uso |
|---|---|
| `invoiceid` | Liga o item à fatura. |
| `description` | Compõe `serv/cServ/xDescServ` (itens separados por ` \| `, texto limitado a 1900 caracteres). |
| `type`, `relid` | Itens `Hosting` identificam o grupo do produto (`relid` = `tblhosting.id`). |

A fatura precisa ter pelo menos um item.

## Código de serviço e NBS por grupo de produto

Código: `DpsBuilderService::resolveCodigoServicoAndNbs()`.

1. Para os itens `Hosting`, busca o grupo: `tblinvoiceitems.relid` → `tblhosting.id` → `tblhosting.packageid` → `tblproducts.id` → `tblproducts.gid`.
2. Para cada grupo, procura `mod_opennfse_group_service_codes` (`groupid`, `codigo_servico`, `nbs`).
3. Grupo sem mapeamento, ou com `codigo_servico` vazio, usa os padrões `mod_opennfse_config.codigo_servico` e `nbs_padrao`.
4. Grupo com código e sem NBS bloqueia a emissão. A NBS precisa ter 9 dígitos.
5. Se os itens resultarem em mais de um `cTribNac` ou mais de uma NBS, a emissão é bloqueada: uma nota não mistura códigos.
6. Fatura sem itens `Hosting` usa os padrões da configuração.

O catálogo `mod_opennfse_service_nbs_catalog` guarda os pares Serviço/NBS cadastrados na configuração. O padrão da configuração e o mapeamento por grupo só aceitam pares desse catálogo.

## Gateways de pagamento

- `tblpaymentgateways`: lista os gateways e o nome de exibição (`setting = 'name'` e `'visible'`). Código: [`WhmcsPaymentGatewayRepository`](../src/Repositories/WhmcsPaymentGatewayRepository.php).
- `mod_opennfse_payment_gateway_settings`: marca se o gateway pode gerar NFS-e. Código: [`PaymentGatewaySettingsRepository`](../src/Repositories/PaymentGatewaySettingsRepository.php).

Regra (`PaymentGatewaySettingsRepository::isEnabled()`): sem nenhuma linha salva, todos os gateways são aceitos. Com linhas salvas, só os gateways marcados são aceitos, e um gateway ausente da lista fica bloqueado. Vale para a emissão manual, a reemissão, a fila e a emissão automática ao pagar.

## Tabelas do módulo

Criadas e atualizadas pelos arquivos de [`migrations/`](../migrations/):

| Tabela | Conteúdo |
|---|---|
| `mod_opennfse_config` | Ambiente, leiaute da DPS (`dps_leiaute`: `auto`, `pre_nt009` ou `nt009`), certificado, prestador, tributação, padrões do tomador, fila, e-mail, DANFS-e, aviso da área do cliente, retenção e metadados de cron, IBGE e atualização. |
| `mod_opennfse_notas` | Uma linha por fatura: status, `id_dps`, chave, número, competência, `xml_path`, dados de cancelamento, erro, contador de E2404 e controle do e-mail automático. |
| `mod_opennfse_notas_history` | Histórico fiscal: cada emissão, cancelamento e snapshot. |
| `mod_opennfse_queue` | Fila: status, tentativas, consultas, `next_check_at`, `last_error`, `correlation_id` e `allow_unpaid`. |
| `mod_opennfse_logs` | Logs técnicos (tipo, request, response, `correlation_id`). |
| `mod_opennfse_sequences` | Último `nDPS` por ambiente, CNPJ e série. |
| `mod_opennfse_group_service_codes` | Código de serviço e NBS por grupo de produto. |
| `mod_opennfse_service_nbs_catalog` | Catálogo de pares Serviço/NBS. |
| `mod_opennfse_payment_gateway_settings` | Gateways habilitados para emissão. |
| `mod_opennfse_ibge_municipios` | Catálogo local de municípios IBGE. |
| `mod_opennfse_nsu_sync`, `mod_opennfse_distribuicao_dfe` | Estado e documentos da sincronização por NSU (Auditoria API). |
| `mod_opennfse_dps_audit_runs`, `mod_opennfse_dps_audit_results` | Execuções e resultados da Auditoria DPS > API. |
