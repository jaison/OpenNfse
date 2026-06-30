# Mapeamento de Dados do WHMCS (Módulo NFS-e)

Este documento descreve as tabelas/campos do WHMCS consumidos pelo módulo e como cada dado é usado na emissão/consulta/cancelamento da NFS-e.

## Visão Geral (origem dos dados)

- Configurações do módulo: `mod_opennfse_config` e tabelas auxiliares do módulo
- Dados do cliente (tomador): `tblclients` + `tblcustomfieldsvalues` (CPF/CNPJ via campo customizado)
- Dados da fatura: `tblinvoices` e seus itens: `tblinvoiceitems`
- Mapeamento por grupo de produto: `tblhosting` → `tblproducts` (gid) → `mod_opennfse_group_service_codes`
- Moeda (tomador exterior/comércio exterior): `tblcurrencies`
- Gateways ativos (para regra de habilitar/desabilitar emissão): `tblpaymentgateways` + `mod_opennfse_payment_gateway_settings`

## Clientes (Tomador)

### Tabela `tblclients`

Fonte no código:
- [WhmcsCustomerRepository.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Repositories/WhmcsCustomerRepository.php)
- [DpsBuilderService.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Services/DpsBuilderService.php)

Campos utilizados:
- `id`
  - Identificador do cliente; persistido em `mod_opennfse_notas.userid`.
- `firstname`, `lastname`, `companyname`
  - Nome do tomador (`toma/xNome`): usa `companyname` quando existe, senão `firstname lastname`.
- `email`
  - Obrigatório; usado em `toma/email`.
- `country`
  - Obrigatório; determina regra de tomador:
  - `BR`: exige CPF/CNPJ via custom field.
  - Diferente de `BR`: trata como exterior (não exige CPF/CNPJ) e usa `toma/cNaoNIF=2` e `toma/end/endExt`.
- `address1`, `address2`, `city`, `state`, `postcode`
  - Endereço do tomador.
  - Para `BR`: quando `postcode` está preenchido, tenta montar `toma/end/endNac` (CEP + cMun) e campos `xLgr`, `nro`, `xCpl`, `xBairro`.
  - Para exterior: `address1`, `city`, `state`, `postcode` são obrigatórios; monta `toma/end/endExt` (`cPais`, `cEndPost`, `xCidade`, `xEstProvReg`) e também `xLgr`, `nro`, `xCpl`, `xBairro`.
- `currency`
  - Usado somente quando o tomador é exterior, para mapear `tpMoeda` (BACEN) no grupo `serv/comExt` via `tblcurrencies.code`.

Regras/validações relevantes:
- Tomador exterior:
  - Exige NBS (porque monta `serv/comExt` e `cNBS` é obrigatório nessa regra do módulo).
  - Exige endereço mínimo (`address1`, `city`, `state`, `postcode`).
- Tomador Brasil:
  - CPF/CNPJ vem do campo customizado configurado no módulo (ver seção a seguir).

### CPF/CNPJ do tomador (Custom Field)

Fonte no código:
- [WhmcsCustomerRepository::getCpfCnpjFromCustomField()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Repositories/WhmcsCustomerRepository.php#L21-L37)

Tabelas:
- `tblcustomfieldsvalues`
  - Campos usados:
    - `relid` = `tblclients.id`
    - `fieldid` = `mod_opennfse_config.tomador_cpfcnpj_customfield_id`
    - `value` = CPF/CNPJ (aceita com máscara; o módulo remove não-dígitos)

Validações:
- Obrigatório quando `tblclients.country = 'BR'`.
- Tamanho deve ser 11 (CPF) ou 14 (CNPJ).

## Faturas (Invoice)

### Tabela `tblinvoices`

Fonte no código:
- [WhmcsInvoiceRepository.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Repositories/WhmcsInvoiceRepository.php)
- [NfseService.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Services/NfseService.php)
- [InvoicePaidHook.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Hooks/InvoicePaidHook.php)

Campos utilizados:
- `id`
  - Identificador da fatura; chave principal de integração (botões no admin e emissão/consulta/cancelamento).
- `userid`
  - Relaciona com `tblclients.id` e é persistido em `mod_opennfse_notas.userid`.
- `status`
  - Regra de emissão: o módulo bloqueia emissão quando não está `Paid`.
- `total`
  - Valor base para `valores/vServPrest/vServ` (deve ser > 0).
- `paymentmethod`
  - Usado para regra de habilitar/desabilitar emissão por gateway (quando configurado).
  - Também é usado para detectar pagamento via crédito (quando `paymentmethod = 'credit'`).
- `credit`
  - Usado para detectar pagamento via crédito (quando `credit > 0`), mesmo que `paymentmethod` não seja `'credit'`.

Observação:
- O módulo não usa `invoicenum` atualmente; usa o `id` como referência textual na descrição do serviço.

### Itens da fatura (`tblinvoiceitems`)

Fonte no código:
- [WhmcsInvoiceRepository::getItems()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Repositories/WhmcsInvoiceRepository.php#L21-L29)
- [DpsBuilderService::buildDescricao()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Services/DpsBuilderService.php#L483-L500)

Campos utilizados:
- `invoiceid`
  - Relaciona com `tblinvoices.id`.
- `description`
  - Usado para compor `serv/cServ/xDescServ` (com truncagem para 1900 caracteres).
- `type`, `relid`
  - Usados para identificar o grupo do produto/serviço quando o item é do tipo `Hosting` (ver seção “Mapeamento por grupo”).

## Mapeamento por Grupo de Produto/Serviço (cTribNac + NBS)

Objetivo:
- Permitir que `serv/cServ/cTribNac` e `serv/cServ/cNBS` sejam definidos de forma automática por grupo de produto/serviço do WHMCS.

Fonte no código:
- [DpsBuilderService::resolveCodigoServicoAndNbs()](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Services/DpsBuilderService.php#L352-L428)

Regras do módulo:
- Se a invoice tiver itens `Hosting`, o módulo resolve os `groupid` (gid) desses itens.
- Para esses `groupid`, busca configuração em `mod_opennfse_group_service_codes`.
- Se houver mais de um `cTribNac` ou mais de uma NBS entre os itens, o módulo bloqueia emissão (para não emitir com códigos inconsistentes na mesma nota).
- Se não conseguir resolver grupos (ou não houver itens `Hosting`), usa o padrão da configuração do módulo (`mod_opennfse_config.codigo_servico` e `mod_opennfse_config.nbs_padrao`).

### Tabelas do WHMCS usadas para descobrir o grupo

- `tblinvoiceitems`
  - Filtra apenas itens onde:
    - `type = 'Hosting'`
    - `relid` é interpretado como `tblhosting.id`
- `tblhosting`
  - Usa:
    - `id` (comparado com `tblinvoiceitems.relid`)
    - `packageid` (produto)
- `tblproducts`
  - Usa:
    - `id` (comparado com `tblhosting.packageid`)
    - `gid` (grupo do produto)

### Tabela do módulo `mod_opennfse_group_service_codes`

- `groupid` (inteiro)
  - Deve ser o mesmo valor de `tblproducts.gid`.
- `codigo_servico`
  - Usado como `cTribNac`.
- `nbs`
  - Usado como `cNBS`.

## Moeda (tomador exterior)

Fonte no código:
- [DpsBuilderService.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Services/DpsBuilderService.php#L252-L280)

Tabela:
- `tblcurrencies`
  - Campos usados:
    - `id` (comparado com `tblclients.currency`)
    - `code` (ex.: `BRL`, `USD`, `EUR`)

Uso:
- Para tomador exterior, o módulo monta `serv/comExt` e define:
  - `tpMoeda`: mapeado de `tblcurrencies.code` (ISO) para código BACEN.
  - `vServMoeda`: valor total formatado com 2 casas (padrão `TSDec15V2`).

## Gateways de pagamento (regra de emissão por gateway)

Tabelas:
- `tblpaymentgateways`
  - Usada para listar gateways ativos (visíveis) e nome de exibição.
  - Fonte: [WhmcsPaymentGatewayRepository.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Repositories/WhmcsPaymentGatewayRepository.php)
- `mod_opennfse_payment_gateway_settings`
  - Armazena se o gateway está habilitado para emissão de NFS-e.
  - Fonte: [PaymentGatewaySettingsRepository.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/src/Nfse/Repositories/PaymentGatewaySettingsRepository.php)

Regra operacional:
- Se existir qualquer configuração salva em `mod_opennfse_payment_gateway_settings`, o módulo passa a tratar como “lista permitida” (whitelist).
- Gateways fora dessa lista são considerados desabilitados para emissão automática e manual.

## Tabelas do próprio módulo (persistência operacional)

Definição inicial (migrations):
- [001_create_tables.sql](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/migrations/001_create_tables.sql)

Principais:
- `mod_opennfse_config`
  - Configura ambiente, certificado, emissor, defaults (cTribNac, alíquota, NBS, flags de fila etc.).
- `mod_opennfse_notas`
  - Registro por invoice (status, idDPS, chave, paths de XML/PDF, erros).
- `mod_opennfse_logs`
  - Auditoria de request/response e erros.
- `mod_opennfse_queue`
  - Processamento assíncrono (PENDING/RUNNING/WAIT_STATUS/ERROR/DONE e metadados).
- `mod_opennfse_sequences`
  - Sequencial de `nDPS` por emissor/ambiente/série.

