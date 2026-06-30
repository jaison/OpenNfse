# Instalação (Ambiente de Desenvolvimento)

## Estrutura

- Copiar `modules/addons/Nfse/` para o WHMCS.
- Garantir `modules/addons/Nfse/vendor/autoload.php` com a SDK instalada.

## Composer (recomendado em build/local)

Executar dentro de `modules/addons/Nfse/`:

```bash
composer install --no-dev
```

Depois sincronizar a pasta `vendor/` para o servidor.

## PSR-12 (opcional, recomendado em dev)

Para validar PSR-12 localmente, instale dependências de desenvolvimento e rode o checker:

```bash
composer install
composer cs:check
```

Para aplicar correções automáticas onde possível:

```bash
composer cs:fix
```

## Ativação no WHMCS

1. Admin → Configuration → System Settings → Addon Modules
2. Ativar “Nfse”
3. Admin → Addons → OpenNFS-e → salvar configuração

## Permissões

- O diretório `attachments/` deve ser gravável pelo PHP para criar `attachments/nfse/xml/`.

## Requisitos de Segurança

- O certificado A1 (`.pfx`/`.p12`) deve ficar fora do webroot do WHMCS.
- Não armazenar certificado em `public_html`, `www`, `htdocs` ou diretórios equivalentes.
- Validar que o usuário do PHP consegue ler o certificado no caminho configurado.
- Manter `attachments/` fora de acesso público direto, conforme a recomendação padrão do WHMCS.

Consulte `docs/SEGURANCA.md` para o checklist completo de segurança e diretrizes de manutenção.

## Cron

Executar via CLI (recomendado):

```bash
php -q /caminho/para/whmcs/modules/addons/Nfse/cron.php
```
