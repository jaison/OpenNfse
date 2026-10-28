# Instalação do OpenNfse

## Requisitos

- WHMCS `8.13.x` e PHP `8.1+`.
- Banco recomendado: MySQL `5.7.5+` ou MariaDB `10.0.2+`. Nessas versões a sessão pode manter vários `GET_LOCK` ao mesmo tempo, e o módulo usa o lock do cron junto com o lock por fatura. Em versões anteriores o módulo continua funcionando, mas, dentro do processamento da fila, abre mão do lock por fatura e conta só com o lock do cron (ver [Locks em FLUXOS.md](FLUXOS.md#locks)).
- Extensões PHP: `openssl` (leitura do certificado), `curl` (sincronização IBGE e checagem de atualização), `dom`, `simplexml`, `mbstring` e `zip` (exportação ZIP de XMLs), além das exigidas pela SDK.
- Certificado A1 (`.pfx`/`.p12`) válido, fora do webroot.
- Diretório `attachments` do WHMCS gravável pelo PHP.

## Instalação a partir do release

1. Baixe o release e extraia. A pasta extraída tem nome versionado (por exemplo, `OpenNfse-0.1.22`): renomeie para `OpenNfse`.
2. Copie a pasta para `<whmcs>/modules/addons/OpenNfse/`. Confira se os arquivos ocultos `.htaccess` (raiz e `assets/`) foram copiados.
3. No admin do WHMCS, acesse **Configuration > System Settings > Addon Modules** e ative **OpenNfse**. A ativação cria as tabelas `mod_opennfse_*`.
4. Em **Configure > Access Control**, marque os grupos de administradores com acesso ao addon.
5. Acesse **Addons > OpenNfse** e preencha a configuração (ver abaixo).

O release já traz as dependências em `vendor-scoped/`, com namespaces prefixados para não conflitar com outros módulos. O `bootstrap.php` usa `vendor-scoped/` quando existe.

## Atualização

1. Substitua os arquivos de `modules/addons/OpenNfse/` pelos da nova versão, mantendo o nome da pasta.
2. As migrations novas são aplicadas automaticamente no próximo acesso ao addon, ciclo do cron ou emissão. Desativar e reativar o addon não é necessário.
3. Revise a configuração em **Addons > OpenNfse**.
4. No OpenLiteSpeed, faça um graceful restart para que os `.htaccess` sejam relidos (ver [SEGURANCA.md](SEGURANCA.md#5-acesso-web-direto-ao-diretório-do-addon)).

O dashboard do addon avisa quando há versão nova. A checagem lê o `update.json` do repositório a cada 24 h, ou na hora, pelo botão **Verificar atualizações agora**.

## Configuração inicial

Comece pelo ambiente de **homologação** e valide as notas com a contabilidade antes de usar produção.

1. **Ambiente e certificado**: ambiente (homologação/produção), caminho do certificado A1 e senha. A senha é gravada criptografada com o `encrypt` do WHMCS. Use **Revalidar certificado** e **Testar conexão**.
2. **Prestador**: CNPJ, inscrição municipal (e se ela deve ser informada na DPS), código IBGE do município, regime tributário e dados de contato e endereço.
3. **Tributação**: código de serviço (`cTribNac`), NBS padrão e alíquota de ISS. Os pares Serviço/NBS podem ser cadastrados no catálogo e mapeados por grupo de produto do WHMCS (ver [MAPEAMENTO_DADOS_WHMCS.md](MAPEAMENTO_DADOS_WHMCS.md)).
4. **Tomador**: ID do custom field de cliente que guarda o CPF/CNPJ, número e bairro padrão e código IBGE padrão para quando o município não for encontrado.
5. **Série DPS** e, se necessário, ajuste dos sequenciais em **Sequenciais**.
6. **Gateways**: marque os gateways cujas faturas podem gerar NFS-e.
7. **Processamento automático**: fila, emissão automática ao pagar, intervalo de consulta, faturas `Unpaid` e envio automático de e-mail. O efeito de cada opção está em [FLUXOS.md](FLUXOS.md#configurações-que-controlam-os-fluxos).
8. **DANFS-e** (opcional): logo SVG e linhas do cabeçalho do PDF.
9. **Área do cliente** (opcional): aviso exibido na página **Notas Fiscais**.

O catálogo local de municípios IBGE é preenchido automaticamente na primeira vez que o módulo precisa dele e também pode ser sincronizado pelo botão **Sincronizar municípios IBGE agora**. Se a fonte mudar desde o último hash aprovado, a sincronização pede aprovação do novo hash.

Em produção, só ligue a emissão automática depois de validar a emissão manual.

## Cron

O módulo roda junto com o cron do WHMCS, pelo hook `AfterCronJob`. Não é preciso criar outra entrada no crontab. Como o processamento acontece a cada execução do cron do WHMCS, a frequência dele define a frequência da fila (o módulo roda no máximo uma vez por minuto).

`cron.php` continua disponível para execução manual pela CLI:

```bash
php -q /caminho/para/whmcs/modules/addons/OpenNfse/cron.php
```

As duas origens compartilham o mesmo lock e a mesma proteção por minuto (ver [FLUXOS.md](FLUXOS.md#cron)).

## Permissões e segurança

- O módulo cria `attachments/nfse/xml/...` sob o diretório de anexos do WHMCS, com permissão `0750`.
- O certificado precisa ficar fora do webroot. O módulo recusa caminhos dentro do `DOCUMENT_ROOT` ou do diretório do WHMCS.
- Em nginx, IIS e alguns cenários de LiteSpeed, os `.htaccess` não bastam. Veja [SEGURANCA.md](SEGURANCA.md) para a configuração equivalente e o checklist de produção.

## Desenvolvimento

Dentro de `modules/addons/OpenNfse/`:

```bash
composer install       # dependências, incluindo as de desenvolvimento
composer test          # PHPUnit
composer cs:check      # PSR-12 (phpcs)
composer cs:fix        # correções automáticas (phpcbf)
composer run scope     # reconstrói vendor-scoped/ com php-scoper
```

Depois de alterar dependências, rode `composer run scope` para que `vendor-scoped/` reflita o `composer.lock`.

O script `scripts/import_municipios.php` importa o catálogo de municípios pela CLI. Aceita como argumento opcional uma URL ou um arquivo JSON local.
