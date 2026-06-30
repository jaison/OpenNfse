# OpenNFS-e

Addon para WHMCS com emissão de NFS-e via API Nacional, geração de DANFS-e em PDF, armazenamento de XML e integração com fila/processamento automático.

## Visão Geral
- Compatível com WHMCS `8.13.x`.
- Requer PHP `8.1+`.
- Usa a SDK `nfse-nacional/nfse-php`.
- Usa `vendor-scoped` para reduzir risco de conflito com dependências de outros módulos do WHMCS.
- Organiza XMLs emitidos por ambiente, série, ano e mês.

## Estrutura Do Repositório
- `src/`:
  código-fonte principal do módulo.
- `assets/`:
  CSS e recursos visuais do admin/client area.
- `templates/`:
  templates da área do cliente.
- `migrations/`:
  migrations de banco do addon.
- `docs/`:
  documentação complementar técnica e operacional.
- `vendor-scoped/`:
  dependências prontas para uso no ambiente final.

## Requisitos
- PHP `^8.1`
- WHMCS `8.13.x`
- Extensões PHP exigidas pela SDK e pelo ambiente do WHMCS
- Certificado A1 válido (`.pfx` ou `.p12`)

## Instalação
1. Copie a pasta `OpenNfse` para `modules/addons/` do WHMCS.
2. Confirme que a pasta `vendor-scoped/` está presente no módulo publicado.
3. No admin do WHMCS, acesse `Configuration > System Settings > Addon Modules`.
4. Ative o addon `OpenNFS-e`.
5. Acesse `Addons > OpenNFS-e` e preencha a configuração inicial.

## Configuração Inicial
- Configure ambiente, certificado digital e dados do prestador.
- Configure a série DPS conforme a operação desejada.
- Revise as opções de fila/processamento automático.
- Salve a configuração antes de realizar testes de emissão.

## Armazenamento De Arquivos
- XMLs são gravados em:

```text
attachments/nfse/xml/{ambiente}/{serie}/{ano}/{mes}/
```

- Exemplos:

```text
attachments/nfse/xml/homologacao/900/2026/06/
attachments/nfse/xml/producao/1/2026/06/
```

- PDFs DANFS-e continuam sendo gravados em:

```text
attachments/nfse/pdf/
```

## Cron
- O addon possui integração com o cron do próprio WHMCS.
- Quando o cron principal do WHMCS roda a cada minuto, o processamento automático do módulo é disparado junto.
- O processamento respeita a configuração interna do addon e possui proteção contra execução duplicada no mesmo minuto.
- O arquivo `cron.php` do módulo permanece disponível para compatibilidade, mas a recomendação é usar o cron principal do WHMCS como origem oficial.

## Atualização De Dependências
Se você estiver trabalhando a partir do código-fonte e precisar reconstruir as dependências escopadas:

```bash
composer install
composer run scope
```

Isso reconstrói a pasta `vendor-scoped/`.

## Desenvolvimento Local
- Instalar dependências:

```bash
composer install
```

- Rodar testes:

```bash
composer test
```

- Verificar padrão de código:

```bash
composer cs:check
```

- Aplicar correções automáticas:

```bash
composer cs:fix
```

## Segurança
- O certificado A1 deve ficar fora do webroot do WHMCS.
- O diretório `attachments/` deve ser gravável pelo PHP.
- Não publique logs, XMLs, PDFs gerados ou credenciais.
- Consulte [SEGURANCA.md](file:///Users/jaison/Playground/nfse-nacional/modules/addons/OpenNfse/docs/SEGURANCA.md) para orientações operacionais detalhadas.

## Publicação No GitHub
Este repositório está preparado para publicar uma versão pronta para uso:

- incluir `vendor-scoped/`
- não incluir `vendor/`
- não incluir caches, logs ou arquivos locais

O `.gitignore` já está ajustado para esse fluxo.

## Licença E Uso
Este projeto é disponibilizado para uso pessoal e uso interno por empresas, incluindo estudo, instalação e adaptação para uso próprio.

Sem autorização prévia e expressa do autor, não é permitido:
- vender este software
- revender este software
- sublicenciar este software
- redistribuir este software como produto comercial
- oferecer este software como serviço hospedado/SaaS
- comercializar versões modificadas deste software
- utilizar este software como base para produto concorrente com exploração comercial direta

Se você deseja uso comercial, revenda, sublicenciamento, distribuição paga ou incorporação em oferta comercial, obtenha autorização específica do autor.

Este projeto utiliza como base a Elastic License 2.0 (ELv2), acrescida de cláusulas específicas de não revenda. O texto formal e juridicamente válido está no arquivo [`LICENSE`](LICENSE).
