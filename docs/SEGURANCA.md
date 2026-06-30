# Segurança do Módulo NFS-e

## Objetivo

Documentar as regras de segurança operacionais do addon `Nfse`, os controles já implementados e os cuidados que devem ser mantidos em novas evoluções.

## Princípios adotados

- Toda ação mutável ou sensível deve exigir `POST`.
- Toda ação mutável ou sensível deve validar token CSRF.
- Tokens não devem ser enviados em querystring.
- Arquivos persistidos pelo módulo devem ser servidos apenas por caminhos validados.
- Certificados A1 (`.pfx`/`.p12`) não podem ficar dentro do webroot.
- SVG/XML usados na geração de PDF devem passar por sanitização e parsing endurecido.

## Controles implementados

### 1. CSRF e método HTTP

- O fallback do token CSRF em `TokenService` aceita apenas `$_POST['token']`.
- Downloads de XML/PDF na área do cliente e no admin usam `POST`, não links com token em URL.
- Actions mutáveis do admin exigem `POST`, incluindo:
  - emissão
  - reemissão
  - cancelamento
  - fila (`filaCheckNow`, `filaRetry`)
  - salvamento de configuração
  - CRUD do catálogo Serviço/NBS
  - sequenciais
  - downloads e envio por e-mail

### 2. Proteção de arquivos

- XML/PDF persistidos pelo módulo são resolvidos via `StorageService::resolveAbsolutePath()`.
- O caminho relativo persistido é limitado às áreas controladas do módulo em `attachments/nfse/...`.
- O hook de e-mail só aceita `nfse_pdf_temp_path` dentro de `sys_get_temp_dir()`.

### 3. Certificado digital

- O `certificate_path` continua livre, mas deve apontar para um arquivo real e legível.
- O módulo bloqueia certificados localizados dentro do webroot.
- Regra prática:
  - permitido: `/home/usuario/certs/certificado.pfx`
  - proibido: `/home/usuario/public_html/certificado.pfx`
  - proibido: `/var/www/html/whmcs/certificado.pfx`

### 4. Geração de DANFS-e

- O SVG do cabeçalho é normalizado antes de salvar e antes de renderizar preview/PDF.
- O sanitizador remove:
  - `<script>`
  - `<foreignObject>`
  - elementos `<image>` e `<use>`
  - atributos `href`/`xlink:href` com `http:`, `https:`, `file:` ou `data:`
- O XML usado no PDF é carregado com `LIBXML_NONET`, evitando resolução de recursos externos.

## Diretrizes para desenvolvimento

### Novas actions

Sempre que adicionar uma action em `AdminController` ou `ClientController`:

1. Definir se ela é leitura ou mutação.
2. Se for mutação ou download sensível, exigir `POST`.
3. Validar token CSRF antes de executar a regra de negócio.
4. Evitar depender de `$_REQUEST` para parâmetros sensíveis; preferir `$_POST`.

### Novos caminhos de arquivo

Sempre que uma feature usar path vindo de config, banco ou mergefield:

1. Resolver com `realpath()`.
2. Validar existência e legibilidade.
3. Validar que o arquivo está dentro de uma base permitida, ou fora de uma base proibida.
4. Nunca usar diretamente paths vindos de querystring sem validação.

### Novos documentos XML/SVG

1. Não confiar em conteúdo colado pelo usuário sem sanitização.
2. Bloquear referências externas em SVG.
3. Usar parsing XML com `LIBXML_NONET`.
4. Evitar qualquer fluxo que permita entidades externas ou carregamento remoto implícito.

## Checklist operacional

Antes de colocar o módulo em produção:

- Confirmar que o certificado A1 está fora do webroot.
- Confirmar permissões corretas em `attachments/`.
- Validar emissão, consulta, download XML, download PDF e envio por e-mail.
- Confirmar que não existem links com `token=` expostos nas telas do módulo.
- Revisar logs para garantir que não há vazamento desnecessário de dados sensíveis.

## Itens ainda recomendados

- Reduzir exposição de mensagens internas em erros administrativos.
- Revisar o conteúdo persistido em logs para mascaramento adicional, se necessário.
- Adicionar testes automatizados cobrindo POST-only, validação de paths e sanitização de SVG/XML.
