# Plano de desenvolvimento do OpenNfse

Registro das etapas de evolução do addon e do que já foi entregue. Os princípios continuam os mesmos: uso obrigatório da SDK oficial `nfse-nacional/nfse-php`, desacoplamento por adapter e validação em homologação antes de produção.

## Princípios

- Hooks e telas nunca chamam a SDK diretamente: sempre Controller → Service → Adapter → SDK.
- Todo artefato necessário para auditoria é persistido: XML da NFS-e e do cancelamento, logs de request/response com `correlation_id`, status interno e histórico fiscal.
- Ambiente e endpoints são definidos pela configuração. Os arquivos ficam em `attachments`, separados por ambiente e série.

## Resumo

| Etapa | Tema | Status |
|---|---|---|
| 0 | Setup e ambiente | Implementado |
| 1 | Emissão manual, consulta e armazenamento | Implementado |
| 2 | Fila e cron | Implementado |
| 3 | Emissão automática pós-pagamento | Implementado |
| 4 | Cancelamento | Implementado |
| 5 | Área do cliente | Implementado |
| 6 | PDF / DANFS-e | Implementado |
| 7 | Relatórios e auditorias | Implementado |
| 8 | Hardening | Em andamento |

## Etapa 0 — Setup e ambiente

- Dependências empacotadas em `vendor-scoped/` (`composer run scope`), carregadas pelo `bootstrap.php`.
- Migrations aplicadas na ativação e automaticamente em cada ciclo, sem reativar o addon.
- Instruções em [INSTALACAO.md](INSTALACAO.md).

## Etapa 1 — Emissão manual, consulta e armazenamento

- Configuração do emissor, emissão manual pela fatura, consulta de status e download de XML.
- Persistência em `mod_opennfse_notas`, `mod_opennfse_logs` e `mod_opennfse_config`.
- XML em `attachments/nfse/xml/{ambiente}/{serie}/{ano}/{mes}/`.
- Referências: [ARQUITETURA.md](ARQUITETURA.md) e [FLUXOS.md](FLUXOS.md).

## Etapa 2 — Fila e cron

- Fila `mod_opennfse_queue` com estados `PENDING`, `RUNNING`, `WAIT_STATUS`, `DONE`, `ERROR` e `RESOLVIDO`, backoff nas consultas e processamento sequencial de DPS.
- Cron integrado ao WHMCS pelo hook `AfterCronJob`, com lock MySQL e proteção por minuto. [`cron.php`](../cron.php) continua disponível para execução manual pela CLI.
- Consulta de notas `PROCESSANDO`, recuperação de chave de notas emitidas sem chave e limpeza por retenção.
- Tela da fila com **Consultar agora**, **Reprocessar** e **Processar agora**.
- Reenvio da mesma DPS após indisponibilidade da API (503/timeout) e reemissão controlada após E2404.

## Etapa 3 — Emissão automática pós-pagamento

- Hook `InvoicePaid` enfileira a emissão quando a fila e a emissão automática estão ligadas.
- Habilitação por gateway de pagamento e bloqueio de faturas pagas só com crédito.
- Um único item ativo por fatura na fila, evitando duplicidade.
- Enfileiramento manual de faturas `Unpaid`, quando permitido na configuração.
- Envio automático de XML/PDF por e-mail após `DONE`, com proteção contra envio duplicado.

## Etapa 4 — Cancelamento

- Formulário no admin com código, motivo e descrição.
- Validações do evento 101101 (`cMotivo` 1, 2 ou 9; `xMotivo` de 15 a 255 caracteres).
- XML do evento salvo em disco e cancelamento registrado no histórico fiscal.
- Cancelamento de NFS-e órfã a partir do XML, pela Auditoria XML.

## Etapa 5 — Área do cliente

- Página **Notas Fiscais** no menu Billing com as notas `EMITIDA` do cliente e aviso configurável.
- Resumo da NFS-e na fatura (`viewinvoice`).
- Download de XML e PDF e envio por e-mail, só por `POST` com token e conferência de posse da fatura.

## Etapa 6 — PDF / DANFS-e

- Geração local do DANFS-e a partir do XML salvo (`DanfsePdfService` e `LocalNfsePdfGenerator`), sem depender do endpoint DANFS-e da API.
- PDF gerado sob demanda a cada download ou envio, sem cópia em disco.
- Personalização do cabeçalho (logo SVG sanitizado, município, secretaria, telefone e e-mail) e aviso de homologação.

Pendência opcional: ajuste fino de espaçamento e fontes para aproximar ainda mais o PDF do layout do portal nacional.

## Etapa 7 — Relatórios e auditorias

Entregue em **Addons > OpenNfse > Relatórios** (`ReportsController`):

- Dashboard com métricas do período, últimas emissões, cancelamentos e erros.
- NFS-e Emitidas (com totais e ações por nota), Falhas e Cancelamentos.
- Auditoria Emissão: faturas pagas sem NFS-e e fora da fila.
- Auditoria XML: XMLs órfãos e referências inconsistentes, com cancelamento de órfãos.
- Auditoria DPS > API e Auditoria API (distribuição por NSU).
- Histórico Fiscal da fatura.
- Logs com visualização detalhada.
- Exportação CSV (Emitidas, Falhas, Cancelamentos, Auditoria Emissão e Logs) e ZIP de XMLs, inclusive por competência, separado em `Emitidas/`, `Canceladas/` e `Emitidas para Estrangeiros/`.

Detalhes em [FLUXOS.md](FLUXOS.md#relatórios).

## Etapa 8 — Hardening

Já entregue:

- Testes automatizados (`composer test`) para `NfseService`, `QueueService`, `QueueErrorClassifierService`, `EmissionEligibilityService`, `InvoiceFinancialsService`, `StorageService` e `NfseXmlExtractor`.
- Checagem PSR-12 com `composer cs:check`.
- `correlation_id` ligando fila e logs, além de retenção configurável de logs e da fila.
- Validação preventiva antes do envio, com mensagens agrupadas.
- Controles de segurança descritos em [SEGURANCA.md](SEGURANCA.md), incluindo os `.htaccess` do addon.

Próximos passos:

- Unificar ou remover telas de auditoria que deixaram de ser necessárias.
- Ajustes visuais nas telas do admin.
- Ampliar a cobertura de testes para o `DpsBuilderService` e para os controllers.

## Referências

- [LEVANTAMENTO_SDK.md](LEVANTAMENTO_SDK.md)
- [LEVANTAMENTO_API_NACIONAL.md](LEVANTAMENTO_API_NACIONAL.md)
- [MAPEAMENTO_DADOS_WHMCS.md](MAPEAMENTO_DADOS_WHMCS.md)
