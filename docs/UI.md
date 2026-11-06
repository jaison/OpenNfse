# Componentes de tela do admin (OpenNfse UI)

Guia para montar e migrar as telas do addon (Fila, Relatórios, Configurações) no padrão da Dashboard.
O CSS está em `assets/nfse-ui.css`, na seção **"Componentes de tela"**, e os helpers PHP em
[`src/Views/Ui.php`](../src/Views/Ui.php) (funções puras, testadas em `tests/Views/UiTest.php`).
Os tokens (cores, espaços, raios) são as variáveis CSS de `.nfse-ui`; a Fila
(`QueueController::showFila`) é o exemplo completo.

## Regras

- **Comportamento congelado.** URLs, parâmetros GET/POST, nomes de campos, actions, token CSRF,
  ids e classes usados por JS ficam iguais. Só mudam o HTML, as classes e o CSS (e textos).
- **Sem `style=""` novo**, exceto tamanhos que vêm do dado (largura/altura de barra). Sem cor em hex no PHP.
- **Escape.** Os helpers escapam todo texto. Parâmetros terminados em `Html` recebem HTML pronto:
  quem chama escapa (`Ui::h()`).
- **Só `.nfse-btn`**: nada de `btn btn-success`/`btn-primary` do Bootstrap. Uma ação principal
  (`primary`) por área.
- Textos em pt-BR, rótulos em caixa normal ("Data inicial", não "DATA INICIAL").
- Avisos `successbox`/`errorbox` viram `Ui::callout()`. `Module::ui()->renderError()` e
  `renderSuccess()` já usam o callout.

## Estrutura da página

```php
Module::ui()->renderHeader('OpenNfse');
$this->renderTabs('fila');              // barra de navegação com o título da tela
echo '<div class="nfse-ui nfse-page">'; // tokens + coluna com espaço de 16px entre os blocos
// … callouts, barra de filtros, indicadores, cabeçalho de seção, card com a tabela …
echo '</div>';
Module::ui()->renderFooter();
```

Dentro de `.nfse-page` ficam os cards (`.nfse-card`: fundo branco, borda, raio 12px). O fundo da
página é o do WHMCS. As quebras de layout usam container queries sobre `.nfse-page` (a largura
da área da tela, com o menu lateral do WHMCS aberto ou fechado).

## Submenu de seção (Relatórios, Configurações)

Lista vertical à esquerda (`.nfse-subnav`), conteúdo à direita. Abaixo de 1000px de área vira uma
linha de chips com rolagem horizontal acima do conteúdo. A descrição da seção vai para o
cabeçalho da seção (`Ui::sectionHeader`), não para o item do menu.

```php
echo Ui::withNav(
    Ui::subnav([
        ['group' => 'Notas'],
        ['label' => 'Emitidas', 'href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas', 'current' => true],
        ['label' => 'Logs', 'href' => '…', 'meta' => 'Técnico'],                    // texto discreto
        ['label' => 'Certificado', 'href' => '…', 'pill' => 'Expira 27d', 'tone' => 'warn'], // pílula de status
        ['label' => 'Fila', 'href' => '…', 'dot' => 'danger'],                     // ponto (ok|warn|danger)
    ], 'Seções do relatório'),
    $conteudoHtml
);
```

```html
<div class="nfse-withnav">
  <nav class="nfse-card nfse-subnav" aria-label="Seções do relatório">
    <div class="nfse-subnav__group">Notas</div>
    <a class="nfse-subnav__item" href="…" aria-current="page"><span class="nfse-subnav__label">Emitidas</span></a>
    <a class="nfse-subnav__item" href="…"><span class="nfse-subnav__label">Certificado</span><span class="nfse-badge nfse-badge--warn">Expira 27d</span></a>
  </nav>
  <div class="nfse-withnav__content">…</div>
</div>
```

O item ativo usa o mesmo destaque da aba ativa da barra (fundo azul-claro, texto azul, 600).

## Cabeçalho de seção

h2 de 16px + resumo de uma linha (12px, discreto) à esquerda; botões à direita, na mesma linha.

```php
echo Ui::sectionHeader('Notas emitidas', 'Notas emitidas no período, com XML e PDF.', Ui::button('Exportar CSV', [], ['href' => $csvUrl]), 'sec-emitidas');
```

```html
<div class="nfse-sechead">
  <div class="nfse-sechead__text">
    <h2 class="nfse-sechead__title" id="sec-emitidas">Notas emitidas</h2>
    <div class="nfse-sechead__summary">Notas emitidas no período, com XML e PDF.</div>
  </div>
  <div class="nfse-sechead__actions"><a class="nfse-btn" href="…">Exportar CSV</a></div>
</div>
```

O `id` serve para o `aria-labelledby` do card logo abaixo.

## Barra de filtros

Um card, campos numa linha onde couber (inputs de 32px), "Filtrar" (primary) e "Limpar" (ghost)
no fim. Os campos ocultos de sempre (`module`, `action`, `tab`…) vão em `hidden`.

```php
echo Ui::filterBar(
    ['id' => 'nfse-fila-filters', 'method' => 'get', 'action' => 'addonmodules.php', 'hidden' => ['module' => 'OpenNfse', 'action' => 'fila']],
    Ui::field('Fatura', Ui::input('invoiceid', $invoice, 'text', ['placeholder' => 'ID da fatura'], 'nfse-input--w-sm'))
    . Ui::field('Status', Ui::select('status', ['' => 'Todos os status', 'ERROR' => 'ERROR'], $status, [], 'nfse-input--w-md'))
    . Ui::field('Data inicial', Ui::input('data_inicial', $from, 'date')),
    Ui::button('Filtrar', ['primary']) . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=fila']),
    'Filtros da fila'
);
```

```html
<section class="nfse-card nfse-filterbar" aria-label="Filtros da fila">
  <form class="nfse-filterbar__form" id="nfse-fila-filters" method="get" action="addonmodules.php">
    <input type="hidden" name="module" value="OpenNfse" /> …
    <label class="nfse-field"><span class="nfse-field__label">Fatura</span><input type="text" name="invoiceid" value="" class="nfse-input nfse-input--w-sm" placeholder="ID da fatura" /></label>
    <div class="nfse-filterbar__actions"><button type="submit" class="nfse-btn nfse-btn--primary">Filtrar</button><a class="nfse-btn nfse-btn--ghost" href="…">Limpar</a></div>
  </form>
</section>
```

Larguras de campo: `nfse-input--w-xs` (96px), `--w-sm` (140px), `--w-md` (200px), `--w-lg`
(320px), `--full`. Fora da barra de filtros, `nfse-input--sm` deixa o campo com 32px.

## Indicadores

Uma linha de indicadores num card só, com divisórias (o visual da Dashboard), sem caixas separadas.

```php
echo Ui::kpis([
    ['label' => 'Itens filtrados', 'value' => 120],             // int: formatado com ponto de milhar
    ['label' => 'Erros', 'value' => 3, 'tone' => 'danger'],      // tons: zero, ok, warn, danger
    ['label' => 'Pendentes', 'value' => 0],                      // zero sem tom → esmaecido
    ['label' => 'Valor emitido', 'value' => 'R$ 58.700,87'],
]);
```

```html
<div class="nfse-kpis nfse-kpis--sm">
  <div class="nfse-kpi"><div class="nfse-kpi__label">Erros</div><div class="nfse-kpi__value nfse-kpi__value--danger">3</div></div>
</div>
```

`Ui::kpis($cells, false)` usa o tamanho cheio (24px) da Dashboard.

## Tabela

Dentro de `.nfse-card.nfse-card--table` e `.nfse-table-wrap` (rola na horizontal sem rolar a
página). Cabeçalho claro, linhas de 13px, números à direita, status com badge, rodapé com
contagem e paginação no mesmo card.

```php
echo '<section class="nfse-card nfse-card--table" aria-labelledby="sec-emitidas">';
echo '<div class="nfse-table-wrap"><table class="nfse-table"><thead><tr>'
    . '<th>Quando</th><th>Status</th><th class="nfse-num">NFS-e</th><th>Fatura</th><th class="nfse-num">Valor</th><th>Ações</th>'
    . '</tr></thead><tbody>';
if ($rows === []) {
    echo Ui::emptyRow(6, 'Nenhuma nota no período.');
}
foreach ($rows as $r) {
    echo '<tr><td class="nfse-nowrap">05/11/2026<div class="nfse-cell-meta">10:32</div></td>'
        . '<td>' . Ui::statusBadge($r['status']) . '</td>'
        . '<td class="nfse-num">' . Ui::h($r['numero']) . '</td>'
        . '<td><a href="invoices.php?action=edit&amp;id=12">12</a></td>'
        . '<td class="nfse-num">R$ 10,00</td>'
        . '<td><div class="nfse-actions">' . Ui::button('Baixar XML', ['sm'], ['href' => $xmlUrl]) . '</div></td></tr>';
}
echo '</tbody></table></div>';
echo Ui::tableFooter(
    Ui::rangeText($first, $last, $total, 'notas'),                           // "Exibindo 1–50 de 120 notas"
    Ui::pager($filtros, $page, $totalPages)                                   // Anterior · Página x de y · Próxima
    . Ui::pageForm($filtros, $page, $totalPages, $perPage, [50, 100, 500])    // selects page e per_page + Aplicar
    . Ui::button('Exportar CSV', ['sm'], ['href' => $csvUrl])
);
echo '</section>';
```

- Classes de célula: `nfse-num` (direita, números tabulares), `nfse-center`, `nfse-nowrap`,
  `nfse-break` (quebra textos longos), `nfse-mono` (ids e chaves), `nfse-muted`.
- Valor vazio: `<span class="nfse-muted">-</span>`.
- Célula com mais de uma linha: `<div class="nfse-cell-stack">…</div>`; texto secundário:
  `<div class="nfse-cell-meta">…</div>`.
- Ações da linha: `<div class="nfse-actions">` com `nfse-btn nfse-btn--sm`; formulários POST da
  linha com `<form class="nfse-inline-form">`.
- Linha destacada: `<tr class="nfse-row--danger">` (erro), `nfse-row--warn` (atenção),
  `nfse-row--info` (aguardando).
- `Ui::pager` e `Ui::pageForm` montam os links e o form com os **mesmos parâmetros** de antes
  (`page`, `per_page`, mais os filtros que você passar).

### Status

`Ui::statusBadge($status)` mostra o rótulo em português (`Ui::STATUS_LABELS`, o código fica no
`title`) com o tom do StatusBadge do OpenNfse UI. O código continua nos filtros, parâmetros e
dados; para selects use `Ui::statusOptions(['PENDING', 'DONE'])` (valor = código, texto = rótulo)
e, para texto solto, `Ui::statusLabel($status)`. Status fora do mapa aparece como veio.

| Status | Rótulo | Tom |
|---|---|---|
| PENDING | Pendente | neutro |
| RUNNING | Processando | neutro |
| WAIT_STATUS | Aguardando status | neutro |
| ERROR | Com erro | `nfse-badge--erro` |
| RESOLVIDO | Resolvido | `nfse-badge--erro` |
| DONE | Concluído | `nfse-badge--ok` |
| EMITIDA | Emitida | `nfse-badge--emitida` |
| CANCELADA, SUBSTITUÍDA | Cancelada, Substituída | `nfse-badge--cancelada` |
| PROCESSANDO | Processando | neutro |
| REJEITADA | Rejeitada | `nfse-badge--danger` |
| ERRO | Erro | `nfse-badge--erro` |
| OK | OK | `nfse-badge--ok` |

Para outros rótulos: `Ui::badge('Expira 27d', 'warn')` (tons: emitida, cancelada, erro, ok,
danger, warn, neutro).

## Avisos (callout)

Ícone + título (13px, 600) + texto (12px), nos tons `info`, `ok`, `warn`, `danger`. warn e danger
saem com `role="alert"`, os outros com `role="status"`.

```php
echo Ui::callout('ok', 'Configuração salva');
echo Ui::callout('danger', 'Erro ao consultar status', 'Verifique os logs do módulo.');
echo Ui::callout('warn', 'Certificado perto do vencimento', 'Vence em 27 dias.', ' <a href="…">Renovar</a>'); // HTML extra no fim
```

```html
<div class="nfse-callout nfse-callout--warn" role="alert">
  <svg class="nfse-callout__icon" …></svg>
  <div class="nfse-callout__body"><div class="nfse-callout__title">…</div><div class="nfse-callout__text">…</div></div>
</div>
```

## Botões

Use `Ui::button($label, $variants, $attrs)` ou as classes direto:

| Classe | Uso |
|---|---|
| `nfse-btn` | ação secundária |
| `nfse-btn--primary` | a ação principal da área (uma só) |
| `nfse-btn--ghost` | ação discreta: "Limpar", "Cancelar" |
| `nfse-btn--danger` | ação destrutiva |
| `nfse-btn--sm` | 28px, para tabelas, rodapés e cabeçalhos de card |
| `nfse-btn--icon` (+ `--xs`) | só ícone, exige `aria-label` |

Botão só com ícone numa célula, logo depois do texto (ex.: copiar o erro na Fila):

```php
echo '<span class="nfse-cell-inline"><span class="nfse-break">' . Ui::h($erroCurto) . '</span>'
    . '<button type="button" class="nfse-btn nfse-btn--icon nfse-btn--xs nfse-copy" data-copy="' . Ui::h($erro) . '"'
    . ' aria-label="Copiar erro" title="Copiar erro">' . Ui::icon('copy') . '</button></span>';
```

`Ui::button` gera `<button type="submit">` (troque com `['type' => 'button']`) ou `<a>` quando
recebe `href`. Desativado: `aria-disabled="true"` num `<span class="nfse-btn">` ou `disabled` no botão.

## Blocos de conteúdo

- **Respiro do card:** `.nfse-card--pad` (o card inteiro, 20px) ou `.nfse-card__body` (16px, abaixo
  de um `.nfse-card__head`).
- **Rótulo/valor:** `<dl class="nfse-dl">` com `<div class="nfse-dl__item"><dt>…</dt><dd>…</dd></div>`,
  numa grade que se ajusta à largura. Helper: `ReportView::keyValues([['Ambiente', 'producao'], …])`
  (valores escapados; `[$rótulo, $html, true]` para HTML) e `ReportView::infoCard($título, $pares, $notaHtml)`.
- **Progresso:** `ReportView::progress($percent, $id)` gera `.nfse-progress` com `.nfse-progress__fill`;
  a largura é o único `style` (vem do dado).
- **Código:** `<pre class="nfse-code">` ou `<textarea class="nfse-code" readonly>` para request/response;
  em tabela, `<textarea class="nfse-code-preview" readonly rows="1" aria-label="…">` (uma linha, abre ao focar).
- **Duas colunas:** `.nfse-split2` com `.nfse-split2__item` e `.nfse-split2__title`; vira uma coluna
  abaixo de 900px de área.

```php
echo '<section class="nfse-card"><div class="nfse-card__head"><h3 class="nfse-card__title">EMISSAO</h3></div>';
echo '<div class="nfse-card__body nfse-split2">'
    . '<div class="nfse-split2__item"><div class="nfse-split2__title">Request</div><pre class="nfse-code">' . Ui::h($req) . '</pre></div>'
    . '<div class="nfse-split2__item"><div class="nfse-split2__title">Response</div><pre class="nfse-code">' . Ui::h($resp) . '</pre></div>'
    . '</div></section>';
```

## Formulários

Fora das Configurações: cards por subseção, linhas com rótulo à esquerda (~240px, 13px 600) e campo
à direita, ajuda embaixo (12px). Abaixo de 700px de área o rótulo fica em cima. Nas Configurações
vale o padrão E3 (seção abaixo): rótulo sempre em cima, dentro do card do bloco. A ação de salvar é um botão
primary no fim da seção, numa `savebar` (fixa no rodapé em formulários longos).

```php
echo '<section class="nfse-card" aria-labelledby="sec-prestador">';
echo '<div class="nfse-card__head"><h2 class="nfse-card__title" id="sec-prestador">Dados do prestador</h2></div>';
echo '<div class="nfse-form">';
echo Ui::formRow('CNPJ', Ui::input('cnpj', $cnpj, 'text', ['id' => 'cnpj', 'required' => true], 'nfse-input--w-md'), 'Só números.', ['id' => 'cnpj', 'required' => true]);
echo Ui::formRow('Série', Ui::input('serie', $serie, 'text', ['id' => 'serie', 'aria-invalid' => 'true'], 'nfse-input--w-xs'), '', ['id' => 'serie', 'error' => 'Informe a série.']);
echo '</div>';
echo Ui::saveBar(Ui::button('Salvar configurações', ['primary']), '', true);
echo '</section>';
```

```html
<div class="nfse-form__row nfse-form__row--invalid">
  <label class="nfse-form__label" for="serie">Série</label>
  <div class="nfse-form__field"><input … class="nfse-input" /><div class="nfse-form__error">Informe a série.</div><div class="nfse-form__help">…</div></div>
</div>
```

Campos: `.nfse-input` serve para input, select e textarea. Inválido: `aria-invalid="true"` no
campo ou `nfse-form__row--invalid` na linha. Obrigatório: `required` no campo e
`'required' => true` no `formRow` (asterisco). Checkbox com texto: `<label class="nfse-check">`.

## Telas de texto e formulário (padrão E3)

Vale para Configurações e para qualquer tela com muitos campos ou explicações. O objetivo é dar
respiro: menos texto visível, blocos bem separados e um lugar fixo para cada coisa.

**Submenu**: card com grupos (título 11px, 600) e itens de 36px. O estado vai num ponto à direita
(verde = ok, cinza = neutro). Texto de status (pílula, sem maiúsculas) só no que pede atenção,
ex.: "Expira em 27 dias". Use `['group' => 'Nome']` e `'dot' => 'ok'|'neutral'` no `Ui::subnav`.

**Largura**: conteúdo até 900px; texto corrido até ~70 caracteres por linha (ajuda até 520px).

**Hierarquia**: título da seção 20px, título do bloco 15px, rótulo do campo 13px peso 500 em cima do
campo, ajuda 12px em cinza.

**Espaços**: 24px entre blocos (com uma linha divisória), 32px entre a explicação e o card,
20px de recuo no card, 14px acima e abaixo de cada campo.

**Bloco**: título e uma frase de explicação à esquerda (240px), ações pequenas embaixo dela;
o card com os campos à direita. Tabelas e grades de status usam o bloco largo: texto em cima,
card na largura toda.

```php
$this->renderConfigSectionStart('Automação da fila', 'Liga a fila e define a regra da emissão manual.');
$this->renderConfigFormTableStart();
$this->renderSelectRow('queue_enabled', 'Habilitar fila/cron?', ['1' => 'Sim', '0' => 'Não'], $valor);
$this->renderConfigFormTableEnd();
$this->renderConfigSectionEnd();

// Tabela: bloco largo (4º parâmetro).
$this->renderConfigSectionStart('Gateways habilitados', 'Meios de pagamento que disparam a emissão.', $acoes, true);
```

**Ajuda**: uma linha. O detalhe vai em "Como funciona", recolhido:

```php
echo Ui::formRow('Leiaute da DPS', $select, 'Deixe em Automático.', ['id' => $id, 'more' => 'O modo Automático segue a NT 009…']);
```

Nas Configurações, a entrada da ajuda em `configFieldHelpMap()` pode ser `[curta, detalhe]`.

**Sem repetição**: cada informação aparece uma vez (ex.: notas da versão). Faixa de indicadores
só onde ajuda a decidir algo; o estado das seções já está no submenu.

**Textos**: frases curtas, sem "Configure…", "Defina…" ou "Use esta opção para…".

## Chips de atalho

Atalhos como meses (Agosto, Setembro, Outubro) viram chips:

```php
echo Ui::chips([
    ['label' => 'Agosto', 'href' => $urlAgosto],
    ['label' => 'Setembro', 'href' => $urlSetembro, 'current' => true],
], 'Atalhos de mês', true); // true = chips pequenos (26px)
```

## Menu suspenso

Ex.: escolha de colunas da Fila. O painel abre e fecha pelo atributo `hidden`.

```html
<div class="nfse-menu">
  <button type="button" class="nfse-btn nfse-btn--sm" aria-haspopup="true" aria-expanded="false" aria-controls="m1">Colunas</button>
  <div id="m1" class="nfse-menu__panel" hidden>
    <div class="nfse-menu__list"><label class="nfse-check"><input type="checkbox" /> <span>Rastreio</span></label></div>
    <div class="nfse-menu__foot">…</div>
  </div>
</div>
```

## Outros utilitários

`Ui::h()` (escape), `Ui::icon()` (SVG decorativo: info, ok, warn, danger, copy), `Ui::attrs()` (atributos a partir de array), `Ui::hidden()` (campos ocultos),
`.nfse-sr-only` (texto só para leitor de tela), `.nfse-text-danger`.
