# Fluxos (MVP 0.1)

## Emissão Manual (Admin → Invoice)

1. Admin clica em “Emitir NFS-e”.
2. `AdminController` chama `NfseService::emitir(invoiceId)`.
3. `NfseService`:
   - lê configuração (`mod_opennfse_config`)
   - lê invoice e itens (`tblinvoices`, `tblinvoiceitems`)
   - lê cliente e CPF/CNPJ (custom field) (`tblclients`, `tblcustomfieldsvalues`)
   - obtém número DPS sequencial (`mod_opennfse_sequences`)
   - monta DPS (`DpsBuilderService`)
   - chama SDK via Adapter (`NfsePhpSdkAdapter`)
   - persiste status, chave e XML (`mod_opennfse_notas`, `attachments/nfse/xml/`)
   - registra logs (`mod_opennfse_logs`)

## Consulta de Status (Admin → Invoice)

1. Admin clica em “Consultar Status”.
2. `AdminController` chama `NfseService::consultarStatus(invoiceId)`.
3. Se existe `chave_acesso`:
   - consulta NFS-e (SDK) e salva XML se retornado.
4. Se não existe `chave_acesso` mas existe `id_dps`:
   - consulta DPS para obter `chave_acesso`.

## Atualização via Cron (Preparação para Fila)

1. Executar `modules/addons/Nfse/cron.php` via cron do servidor/WHMCS.
2. `CronService` busca notas `PROCESSANDO`.
3. Para cada invoice pendente, chama `consultarStatus(invoiceId)`.

