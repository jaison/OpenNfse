# Arquitetura do Módulo NFS-e (WHMCS Addon)

## Objetivo

Implementar um Addon Module para WHMCS que emite NFS-e via API Nacional utilizando obrigatoriamente a SDK oficial `nfse-nacional/nfse-php`, sem acesso direto à SDK a partir de hooks.

## Visão Geral

Fluxo lógico:

Hook/UI (Admin) → Controller → Service → Adapter → SDK → API Nacional

## Componentes

### Entry points

- `modules/addons/Nfse/Nfse.php`: registro do addon, ativação (migrations) e roteamento do admin.
- `modules/addons/Nfse/hooks.php`: registra hook `AdminInvoicesControlsOutput` para exibir botões e status em invoice no admin.
- `modules/addons/Nfse/cron.php`: entrada para execução periódica (atualização de notas pendentes).
- `modules/addons/Nfse/bootstrap.php`: carrega `vendor/autoload.php` do módulo (prioritário) ou autoload interno.

### Camada de Abstração (SDK Adapter)

Objetivo: isolar a dependência direta da SDK e normalizar retorno/erros.

- `OpenNfse\Api\SdkAdapterInterface`
- `OpenNfse\Api\NfsePhpSdkAdapter`

### Camada de Serviços

- `NfseService`: orquestra emissão, consulta, persistência e logs.
- `DpsBuilderService`: monta `DpsData` (SDK) a partir de invoice/cliente/config.
- `StorageService`: grava XML em `attachments/nfse/xml/` e valida caminho para download.
- `CryptoService`: criptografia/descrpitografia usando recursos do WHMCS (`encrypt/decrypt`).
- `TokenService`: CSRF para actions do addon.
- `CronService`: consulta notas `PROCESSANDO` e atualiza status (MVP).

## Controles de Segurança

- Actions mutáveis e downloads sensíveis usam `POST` + token CSRF.
- O fallback de CSRF do módulo aceita token apenas via `POST`.
- XML/PDF persistidos são servidos apenas após validação de path em `StorageService`.
- O caminho do certificado A1 é validado para permanecer fora do webroot.
- O hook de e-mail só aceita PDF temporário dentro de `sys_get_temp_dir()`.
- O SVG do DANFS-e é sanitizado antes do preview/PDF.
- O XML do DANFS-e é processado com `LIBXML_NONET`.

Consulte também `docs/SEGURANCA.md` para as regras operacionais e checklist de produção.

### Repositories

- `ConfigRepository`: `mod_opennfse_config`
- `NotaRepository`: `mod_opennfse_notas`
- `LogRepository`: `mod_opennfse_logs`
- `SequenceRepository`: `mod_opennfse_sequences`
- `WhmcsInvoiceRepository`: `tblinvoices` / `tblinvoiceitems`
- `WhmcsCustomerRepository`: `tblclients` / `tblcustomfieldsvalues`

## Status Interno

- `PROCESSANDO`: DPS gerada/enviada ou aguardando chave.
- `EMITIDA`: XML disponível e/ou chave de acesso registrada.
- `REJEITADA`: rejeição de validação/regra de negócio (erro de API).
- `ERRO`: falha técnica/transitória (timeout, transporte, parsing, etc.).

## Armazenamento

- XML: `attachments/nfse/xml/` (caminho relativo persistido em `mod_opennfse_notas.xml_path`)
- Logs: `mod_opennfse_logs` (payload de request/response e exceções)
