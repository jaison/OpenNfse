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

### 5. Acesso web direto ao diretório do addon

O addon fica em `<whmcs>/modules/addons/OpenNfse`, dentro do webroot, mas o WHMCS o carrega por `include` (`addonmodules.php` e `index.php?m=OpenNfse`). `cron.php` roda só pela CLI. A web só precisa acessar diretamente:

- `assets/nfse-ui.css` (incluído pelos hooks de admin e área do cliente);
- `assets/opennsfe_logo.png` (logo da tela administrativa).

Sem proteção, qualquer arquivo responde pela web: `README.md`, `composer.lock`, `docs/*.md` expõem conteúdo, e arquivos PHP de `src/` e `vendor-scoped/` são **executados** fora do WHMCS.

O addon traz dois `.htaccess`:

- `.htaccess` (raiz): nega tudo.
- `assets/.htaccess`: libera só `css`, `png`, `jpg`/`jpeg`, `gif`, `webp` e `ico`. Continuam negados `.svg` (o brasão é lido só pelo PHP), dotfiles e qualquer outra extensão.

Cada arquivo tem três camadas, para funcionar em servidores diferentes:

| Camada | Servidor |
|---|---|
| `Require all denied` (`<IfModule mod_authz_core.c>`) | Apache 2.4+, LiteSpeed Enterprise |
| `Order`/`Deny` (`<IfModule !mod_authz_core.c>`) | Apache 2.2 ou 2.4 só com `mod_access_compat` |
| `RewriteRule ... [F]` (`<IfModule mod_rewrite.c>`) | OpenLiteSpeed, que só lê regras de rewrite do `.htaccess` |

`Options -Indexes` foi omitido de propósito: em servidores onde o `AllowOverride` não inclui `Options`, ele gera erro 500. A negação já impede a listagem do diretório.

Limitações:

- Com `AllowOverride None`, o Apache ignora os `.htaccess` e nada é bloqueado. Nesse caso, use a configuração do servidor (exemplos abaixo).
- Se o `AllowOverride` não incluir `AuthConfig`, `Limit` e `FileInfo`, o Apache responde 500 para tudo dentro de `modules/addons/OpenNfse/`. Os arquivos continuam protegidos, mas o CSS e o logo deixam de carregar. O resto do WHMCS não é afetado.
- Ao copiar o addon por FTP ou pelo Finder, confira se os arquivos ocultos `.htaccess` foram copiados.

#### Apache: configuração do servidor (preferencial)

Se você tem acesso à configuração do Apache (vhost ou `httpd.conf`), prefira um bloco `<Directory>`. Ele casa pelo caminho físico, não pela URL, e vale mesmo com `AllowOverride None`:

```apache
<Directory "/caminho/do/whmcs/modules/addons/OpenNfse">
    Require all denied
</Directory>
<Directory "/caminho/do/whmcs/modules/addons/OpenNfse/assets">
    <FilesMatch "^[^.][^/]*\.(css|png|jpe?g|gif|webp|ico)$">
        Require all granted
    </FilesMatch>
</Directory>
```

Use o mesmo caminho que aparece no `DocumentRoot`. Em Linux, que diferencia maiúsculas de minúsculas no sistema de arquivos, não há outra forma de chegar ao diretório. Em servidores macOS, mantenha também os `.htaccess`: lá, uma URL com outra caixa (`/Modules/Addons/...`) não casa com o `<Directory>`, mas ainda é barrada pelo `.htaccess`.

#### LiteSpeed e OpenLiteSpeed

- **LiteSpeed Enterprise** lê o `.htaccess` como o Apache (acesso, `FilesMatch` e rewrite).
- **OpenLiteSpeed** só usa as regras de rewrite do `.htaccess` e ignora `Require`, `Order`/`Deny`, `FilesMatch` e `Options`. A proteção depende da camada de rewrite e de duas condições:
  - O vhost precisa ter **Enable Rewrite** e **Auto Load from .htaccess** ativados (WebAdmin > Virtual Hosts > vhost > Rewrite; no arquivo do vhost: `rewrite { enable 1  autoLoadHtaccess 1 }`).
  - O OpenLiteSpeed carrega o `.htaccess` na primeira leitura e não percebe alterações depois. Após instalar ou atualizar o addon, faça um **graceful restart** (`/usr/local/lsws/bin/lswsctrl restart` ou WebAdmin > Graceful Restart).
- Em qualquer LiteSpeed, se a validação abaixo ainda mostrar 200 depois do restart, aplique a regra no nível do servidor (vhost > Rewrite > Rewrite Rules), que não depende do `.htaccess`:

```apache
RewriteCond %{REQUEST_URI} ^/modules/addons/OpenNfse/ [NC]
RewriteCond %{REQUEST_URI} !^/modules/addons/OpenNfse/assets/[^./][^/]*\.(css|png|jpe?g|gif|webp|ico)$ [NC]
RewriteRule .* - [F]
```

Essa regra casa pela URL, por isso no Apache prefira o `<Directory>` acima. O `[NC]` impede o contorno com outra caixa (`/Modules/Addons/...`) em sistemas de arquivos que não diferenciam maiúsculas. Ajuste o prefixo se o WHMCS estiver em um subdiretório (por exemplo, `/whmcs/modules/addons/OpenNfse/`).

#### nginx

O nginx ignora `.htaccess`. Adicione no `server {}` do WHMCS, **antes** do `location ~ \.php$`:

```nginx
location ^~ /modules/addons/OpenNfse/ {
    deny all;

    location ~ ^/modules/addons/OpenNfse/assets/[^/.][^/]*\.(css|png|jpe?g|gif|webp|ico)$ {
        allow all;
    }
}
```

O `^~` impede que o `location` de PHP seja avaliado para esse caminho. O `allow all` do bloco interno substitui o `deny all` só para os assets estáticos.

#### IIS

O IIS também ignora `.htaccess`. O equivalente é um `web.config` com regra de negação (por exemplo, `<requestFiltering>` com `hiddenSegments` ou regra do URL Rewrite) e uma exceção para `assets/`. O addon não traz `web.config`; configure no site, se for o caso.

#### Como validar

Rode a partir de qualquer máquina, trocando o domínio. O parâmetro `?t=` evita respostas antigas em cache de CDN:

```bash
B=https://seu-whmcs.com.br/modules/addons/OpenNfse
for p in README.md composer.lock .gitignore docs/SEGURANCA.md \
         vendor-scoped/autoload.php src/Module.php assets/brasao_itajai.svg \
         %52EADME.md src/Module.php/x \
         assets/nfse-ui.css assets/opennsfe_logo.png; do
  printf '%-30s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' "$B/$p?t=$(date +%s)")"
done
```

Resultado esperado: `403` (ou `404`) para todos, exceto `assets/nfse-ui.css` e `assets/opennsfe_logo.png`, que devem responder `200`.

Atrás do Cloudflare, confira também o cabeçalho `cf-cache-status` (`curl -sI`). Se um arquivo bloqueado ainda responder 200 com `HIT`, a resposta veio do cache: faça um purge no Cloudflare e teste de novo.

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
- Confirmar que o acesso web direto ao diretório do addon está bloqueado (seção 5).
- Confirmar permissões corretas em `attachments/`.
- Validar emissão, consulta, download XML, download PDF e envio por e-mail.
- Confirmar que não existem links com `token=` expostos nas telas do módulo.
- Revisar logs para garantir que não há vazamento desnecessário de dados sensíveis.

## Itens ainda recomendados

- Reduzir exposição de mensagens internas em erros administrativos.
- Revisar o conteúdo persistido em logs para mascaramento adicional, se necessário.
- Adicionar testes automatizados cobrindo POST-only, validação de paths e sanitização de SVG/XML.
