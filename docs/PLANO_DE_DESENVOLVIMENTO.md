# Plano de desenvolvimento por etapas (WHMCS Addon NFS-e)

Este documento consolida um plano por etapas para evolução do módulo, mantendo os princípios do projeto: uso obrigatório da SDK oficial `nfse-nacional/nfse-php`, desacoplamento via Adapter e operações seguras em ambiente de homologação.

## Princípios e guardrails

- Hook/UI nunca chama SDK diretamente; sempre via Service → Adapter → SDK.
- Persistir sempre os artefatos necessários para auditoria:
  - NFS-e XML
  - logs request/response e erros
  - status interno e dados de rastreio (DPS/Chave/Eventos)
- Evitar impacto em produção:
  - ambiente e endpoints controlados por configuração
  - separar storage em `attachments` (fora do webroot quando configurado no WHMCS)

## Etapa 0 — Setup e validação de ambiente

- Instalar dependências via composer no módulo e garantir `vendor/` no servidor.
- Validar permissões do diretório de attachments.
- Rodar smoke check para validar tabelas, SDK e escrita em disco:
  - [smoke_check.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/scripts/smoke_check.php)

## Etapa 1 — MVP 0.1 (emissão manual + consulta + armazenamento)

Entregas:
- Admin: configurar emissor, emitir manualmente por invoice, consultar status, baixar XML.
- Persistência: `mod_opennfse_notas`, `mod_opennfse_logs`, `mod_opennfse_config`.
- Storage: `attachments/nfse/xml`.
- Documentação mínima de arquitetura e fluxos.

Status: implementado.

Referências:
- [ARQUITETURA.md](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/docs/ARQUITETURA.md)
- [FLUXOS.md](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/docs/FLUXOS.md)

## Etapa 2 — Estrutura de fila e cron (preparação e operação contínua)

Entregas:
- Tabela e repositório de fila (`mod_opennfse_queue`) com estados e backoff.
- Cron do módulo para:
  - consultar notas pendentes (`PROCESSANDO`)
  - reprocessar temporários
  - efetuar limpezas por retenção
- Tela admin de fila e ações (retry / consultar agora).

Status: implementado.

Entrypoint:
- [cron.php](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/cron.php)

## Etapa 3 — Emissão automática pós-pagamento

Entregas:
- Hook `InvoicePaid` para enfileirar/emitir conforme configuração.
- Controles para evitar duplicidade e corrida (lock).

Status: implementado.

## Etapa 4 — Cancelamento

Entregas:
- UI/admin para solicitar cancelamento com motivo e descrição.
- Validações conforme schema do evento 101101.
- Registro de evento e persistência do resultado.

Status: implementado.

## Etapa 5 — Área do cliente (Fase 2 do prompt)

Entregas:
- Página do addon no client area listando apenas NFS-e `EMITIDA` com XML.
- Link no menu “Billing” e no `viewinvoice`.
- Download de XML no client area com validação de ownership da invoice.

Status: implementado.

## Etapa 6 — PDF / DANFSe (versão vigente 1.0)

Entregas:
- Download de PDF no admin e client area.
- Geração local de PDF sem depender do endpoint DANFSe (descontinuação).
- Cache em `attachments/nfse/pdf` com regeneração automática se arquivo apagado/0 bytes.
- Ajustes de parsing dos dados do tomador e município.
- Ajustes de layout específicos (brasão local e cabeçalho de Itajaí).

Status: implementado.

Pendências opcionais (tuning visual):
- Ajuste fino de espaçamento e tamanhos de fonte para ficar o mais idêntico possível ao PDF do portal nacional (mantendo DANFSe 1.0).

## Etapa 7 — Relatórios (ainda não implementado)

Sugestão de entregas:
- Relatório admin por período/cliente/status:
  - total de notas emitidas, rejeitadas, canceladas
  - valores totais por competência
  - exportação CSV
- Guardrails:
  - consultas paginadas
  - filtros indexados (por invoiceid, userid, status, competência, emitida_em)

Status: pendente.

## Etapa 8 — Hardening (qualidade, segurança, operabilidade)

Sugestões:
- Padronizar códigos de erro e mensagens por tipo (validação x API x técnica).
- Incluir testes automatizados para:
  - builder de DPS
  - parser de XML (extração de competência, emissão etc.)
  - storage (resolveAbsolutePath, cache inválido)
- Adicionar checagens de estilo (PSR-12) no pipeline local/CI (opcional).
- Melhorar observabilidade:
  - correlação por invoiceid / chave / id_dps nos logs
  - níveis e retenção configurável (já existe retenção de logs e fila DONE)

## Referências

- Levantamento SDK: [LEVANTAMENTO_SDK.md](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/docs/LEVANTAMENTO_SDK.md)
- Levantamento API Nacional: [LEVANTAMENTO_API_NACIONAL.md](file:///Users/jaison/Playground/nfse-nacional/modules/addons/Nfse/docs/LEVANTAMENTO_API_NACIONAL.md)

