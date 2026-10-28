# Instalação do OpenNfse

## Requisitos

- WHMCS `8.13.x` e PHP `8.1+`.
- Extensões PHP: `openssl` (certificado e assinatura), `curl` (chamadas à API Nacional, sincronização IBGE e checagem de atualização), `dom`, `simplexml`, `mbstring`, `zlib` (compactação da DPS e leitura dos documentos da API) e `zip` (exportação ZIP de XMLs).
- Pacote de certificados raiz (CAs) atualizado no servidor (`ca-certificates`, ou o bundle apontado por `curl.cainfo` no `php.ini`). As chamadas à API Nacional verificam o certificado TLS do servidor.
- Certificado A1 (`.pfx`/`.p12`) válido, fora do webroot.
- Diretório `attachments` do WHMCS gravável pelo PHP.

## Instalação a partir do release

1. Baixe o release e extraia. A pasta extraída tem nome versionado (por exemplo, `OpenNfse-0.1.23`): renomeie para `OpenNfse`.
2. Copie a pasta para `<whmcs>/modules/addons/OpenNfse/`. Confira se os arquivos ocultos `.htaccess` (raiz e `assets/`) foram copiados.
3. No admin do WHMCS, acesse **Configuration > System Settings > Addon Modules** e ative **OpenNfse**. A ativação cria as tabelas `mod_opennfse_*`.
4. Em **Configure > Access Control**, marque os grupos de administradores com acesso ao addon.
5. Acesse **Addons > OpenNfse** e preencha a configuração (ver abaixo).

O release já traz as dependências em `vendor-scoped/`, com namespaces prefixados para não conflitar com outros módulos. O `bootstrap.php` usa `vendor-scoped/` quando existe.

## Atualização

1. Apague a pasta `modules/addons/OpenNfse/` (ou, no mínimo, `modules/addons/OpenNfse/vendor-scoped/`) e envie a da nova versão, mantendo o nome da pasta. O módulo não guarda dados nela: a configuração fica no banco, e os XML e PDF ficam em `attachments/`. Se algum arquivo seu estiver lá dentro, como o certificado `.pfx` (que deve ficar fora do webroot), mova-o antes. Copiar por cima também funciona, mas deixa no servidor arquivos que a versão nova não usa mais, como dependências e arquivos de referência que saíram do `vendor-scoped/`.
2. As migrations novas são aplicadas automaticamente no próximo acesso ao addon, ciclo do cron ou emissão. Desativar e reativar o addon não é necessário.
3. Revise a configuração em **Addons > OpenNfse** e clique em **Testar conexão**. Se o teste falhar com erro de certificado (por exemplo, `unable to get local issuer certificate`), atualize o pacote `ca-certificates` do servidor ou aponte `curl.cainfo` no `php.ini` para um bundle atualizado.
4. No OpenLiteSpeed, faça um graceful restart para que os `.htaccess` sejam relidos (ver [SEGURANCA.md](SEGURANCA.md#5-acesso-web-direto-ao-diretório-do-addon)).

O dashboard do addon avisa quando há versão nova. A checagem lê o `update.json` do repositório a cada 24 h, ou na hora, pelo botão **Verificar atualizações agora**.

## Configuração inicial

Comece pelo ambiente de **homologação** e valide as notas com a contabilidade antes de usar produção.

1. **Ambiente e certificado**: ambiente (homologação/produção), **Leiaute da DPS**, caminho do certificado A1 e senha. A senha é gravada criptografada com o `encrypt` do WHMCS. Use **Revalidar certificado** e **Testar conexão**.
   - Em **Leiaute da DPS**, mantenha **Automático**: ele segue a NT 009 (homologação já usa o leiaute novo; produção passa a usá-lo em 01/12/2026 00:00, horário de Brasília). A tela mostra o leiaute ativo. Só force **Pré-NT 009** ou **NT 009** se a SEFIN rejeitar a DPS com E1235 por leiaute; a mensagem de erro da nota indica o ajuste. Detalhes em [LEVANTAMENTO_API_NACIONAL.md](LEVANTAMENTO_API_NACIONAL.md#leiaute-da-dps-nt-009).
2. **Prestador**: CNPJ, inscrição municipal (e se ela deve ser informada na DPS), código IBGE do município, regime tributário (o regime especial aceita `0` a `6` ou `9`) e dados de contato e endereço.
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

Fluxo das dependências:

1. `composer install` (ou `composer update`) instala a SDK no commit fixado no `composer.json`.
2. Na mesma etapa, o `cweagans/composer-patches` aplica os patches de [`patches/`](../patches/) na SDK em `vendor/`. Se algum patch não aplicar, o Composer para.
3. `composer run scope` gera o `vendor-scoped/` a partir do `vendor/` já com os patches.

Depois de alterar dependências ou patches, rode `composer run scope` para que `vendor-scoped/` reflita o `composer.lock`. A versão da SDK e a lista de patches estão em [LEVANTAMENTO_SDK.md](LEVANTAMENTO_SDK.md#versão-e-patches).

O script `scripts/import_municipios.php` importa o catálogo de municípios pela CLI. Aceita como argumento opcional uma URL ou um arquivo JSON local.
