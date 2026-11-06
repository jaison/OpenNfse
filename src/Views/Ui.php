<?php

declare(strict_types=1);

namespace OpenNfse\Views;

/**
 * Componentes de tela do admin (Fila, Relatórios, Configurações): funções puras que devolvem HTML
 * com as classes nfse- de assets/nfse-ui.css. Tudo o que vem como texto é escapado; parâmetros
 * terminados em "Html" recebem HTML já montado (e escapado) por quem chama.
 * Uso e exemplos em docs/UI.md.
 */
final class Ui
{
    public const CALLOUT_TONES = ['info', 'ok', 'warn', 'danger'];

    private const ICONS = [
        'info' => '<circle cx="12" cy="12" r="9"></circle><path d="M12 11v5M12 8v.5"></path>',
        'ok' => '<circle cx="12" cy="12" r="9"></circle><path d="M8 12.5l2.5 2.5L16 9.5"></path>',
        'warn' => '<path d="M12 3l9 16H3z"></path><path d="M12 10v4M12 16.5v.5"></path>',
        'danger' => '<path d="M12 3l9 16H3z"></path><path d="M12 10v4M12 16.5v.5"></path>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"></rect><path d="M5 15V6a2 2 0 0 1 2-2h9"></path>',
    ];

    /** Status de NFS-e e da fila → tom do badge (StatusBadge do OpenNfse UI). */
    private const STATUS_TONES = [
        'EMITIDA' => 'emitida',
        'CANCELADA' => 'cancelada',
        'SUBSTITUIDA' => 'cancelada',
        'SUBSTITUÍDA' => 'cancelada',
        'ERRO' => 'erro',
        'ERROR' => 'erro',
        'RESOLVIDO' => 'erro',
        'REJEITADA' => 'danger',
        'DONE' => 'ok',
        'OK' => 'ok',
    ];

    /** Status de NFS-e e da fila → rótulo exibido. O código continua nos filtros, parâmetros e dados. */
    public const STATUS_LABELS = [
        'PENDING' => 'Pendente',
        'RUNNING' => 'Processando',
        'WAIT_STATUS' => 'Aguardando status',
        'ERROR' => 'Com erro',
        'RESOLVIDO' => 'Resolvido',
        'DONE' => 'Concluído',
        'EMITIDA' => 'Emitida',
        'CANCELADA' => 'Cancelada',
        'SUBSTITUIDA' => 'Substituída',
        'SUBSTITUÍDA' => 'Substituída',
        'PROCESSANDO' => 'Processando',
        'REJEITADA' => 'Rejeitada',
        'ERRO' => 'Erro',
        'ENVIADO' => 'Enviado',
        'FALHOU' => 'Falhou',
        'OK' => 'OK',
    ];

    public static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Atributos HTML a partir de um array. true vira atributo sem valor; false e null são omitidos.
     *
     * @param array<string, string|int|bool|null> $attrs
     */
    public static function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $out .= $value === true ? ' ' . self::h((string) $name) : ' ' . self::h((string) $name) . '="' . self::h((string) $value) . '"';
        }

        return $out;
    }

    /**
     * @param array<string, string|int> $fields
     */
    public static function hidden(array $fields): string
    {
        $html = '';
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . self::h((string) $name) . '" value="' . self::h((string) $value) . '" />';
        }

        return $html;
    }

    /**
     * Cabeçalho de seção: h2 + resumo de uma linha à esquerda, ações (HTML) à direita.
     */
    public static function sectionHeader(string $title, string $summary = '', string $actionsHtml = '', string $id = ''): string
    {
        $html = '<div class="nfse-sechead"><div class="nfse-sechead__text">';
        $html .= '<h2 class="nfse-sechead__title"' . ($id !== '' ? ' id="' . self::h($id) . '"' : '') . '>' . self::h($title) . '</h2>';
        if ($summary !== '') {
            $html .= '<div class="nfse-sechead__summary">' . self::h($summary) . '</div>';
        }
        $html .= '</div>';
        if ($actionsHtml !== '') {
            $html .= '<div class="nfse-sechead__actions">' . $actionsHtml . '</div>';
        }

        return $html . '</div>';
    }

    /**
     * Submenu vertical de seção (Relatórios, Configurações).
     * Cada item: label, href, current (bool) e, opcionais, meta (texto discreto), pill + tone (badge)
     * ou dot (ok|warn|danger). Itens com 'group' viram um título de grupo.
     *
     * @param array<int, array{label?: string, href?: string, current?: bool, meta?: string, pill?: string, tone?: string, dot?: string, group?: string}> $items
     */
    public static function subnav(array $items, string $ariaLabel): string
    {
        $html = '<nav class="nfse-card nfse-subnav" aria-label="' . self::h($ariaLabel) . '">';
        foreach ($items as $item) {
            if (isset($item['group'])) {
                $html .= '<div class="nfse-subnav__group">' . self::h((string) $item['group']) . '</div>';
                continue;
            }
            $current = !empty($item['current']);
            $html .= '<a class="nfse-subnav__item" href="' . self::h((string) ($item['href'] ?? '#')) . '"' . ($current ? ' aria-current="page"' : '') . '>';
            $html .= '<span class="nfse-subnav__label">' . self::h((string) ($item['label'] ?? '')) . '</span>';
            if (($item['pill'] ?? '') !== '') {
                $html .= self::badge((string) $item['pill'], (string) ($item['tone'] ?? ''));
            } elseif (($item['dot'] ?? '') !== '') {
                $html .= '<span class="nfse-subnav__dot nfse-subnav__dot--' . self::h((string) $item['dot']) . '" aria-hidden="true"></span>';
            } elseif (($item['meta'] ?? '') !== '') {
                $html .= '<span class="nfse-subnav__meta">' . self::h((string) $item['meta']) . '</span>';
            }
            $html .= '</a>';
        }

        return $html . '</nav>';
    }

    /**
     * Layout com submenu à esquerda e conteúdo à direita (vira chips acima do conteúdo em área estreita).
     */
    public static function withNav(string $subnavHtml, string $contentHtml): string
    {
        return '<div class="nfse-withnav">' . $subnavHtml . '<div class="nfse-withnav__content">' . $contentHtml . '</div></div>';
    }

    /**
     * Barra de filtros: card com o form, os campos (HTML, ex.: field()) e as ações no fim.
     *
     * @param array{action?: string, method?: string, id?: string, hidden?: array<string, string|int>} $form
     */
    public static function filterBar(array $form, string $fieldsHtml, string $actionsHtml = '', string $ariaLabel = 'Filtros'): string
    {
        $html = '<section class="nfse-card nfse-filterbar" aria-label="' . self::h($ariaLabel) . '">';
        $html .= '<form class="nfse-filterbar__form"' . self::attrs([
            'id' => ($form['id'] ?? '') !== '' ? (string) $form['id'] : null,
            'method' => (string) ($form['method'] ?? 'get'),
            'action' => (string) ($form['action'] ?? 'addonmodules.php'),
        ]) . '>';
        $html .= self::hidden($form['hidden'] ?? []);
        $html .= $fieldsHtml;
        if ($actionsHtml !== '') {
            $html .= '<div class="nfse-filterbar__actions">' . $actionsHtml . '</div>';
        }

        return $html . '</form></section>';
    }

    /**
     * Campo com rótulo em cima (filtros): <label class="nfse-field">.
     */
    public static function field(string $label, string $controlHtml): string
    {
        return '<label class="nfse-field"><span class="nfse-field__label">' . self::h($label) . '</span>' . $controlHtml . '</label>';
    }

    /**
     * @param array<string, string|int|bool|null> $attrs atributos extras (placeholder, id, required…)
     */
    public static function input(string $name, string $value = '', string $type = 'text', array $attrs = [], string $class = ''): string
    {
        return '<input' . self::attrs(['type' => $type, 'name' => $name, 'value' => $value, 'class' => trim('nfse-input ' . $class)] + $attrs) . ' />';
    }

    /**
     * @param array<string|int, string> $options valor => rótulo
     * @param array<string, string|int|bool|null> $attrs
     */
    public static function select(string $name, array $options, string $selected = '', array $attrs = [], string $class = ''): string
    {
        $html = '<select' . self::attrs(['name' => $name, 'class' => trim('nfse-input ' . $class)] + $attrs) . '>';
        foreach ($options as $value => $label) {
            $value = (string) $value;
            $html .= '<option value="' . self::h($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . self::h($label) . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * Botão ou link com as variantes de .nfse-btn (primary, ghost, danger, sm, xs, icon).
     * Com 'href' em $attrs vira <a>; senão <button> (type padrão "submit").
     *
     * @param array<int, string> $variants
     * @param array<string, string|int|bool|null> $attrs
     */
    public static function button(string $label, array $variants = [], array $attrs = []): string
    {
        $class = 'nfse-btn';
        foreach ($variants as $variant) {
            $class .= ' nfse-btn--' . $variant;
        }
        if (isset($attrs['class'])) {
            $class .= ' ' . $attrs['class'];
            unset($attrs['class']);
        }
        if (isset($attrs['href'])) {
            return '<a' . self::attrs(['class' => $class] + $attrs) . '>' . self::h($label) . '</a>';
        }

        return '<button' . self::attrs(array_merge(['type' => 'submit', 'class' => $class], $attrs)) . '>' . self::h($label) . '</button>';
    }

    /**
     * Ícone SVG decorativo (aria-hidden): info, ok, warn, danger, copy. Use em botão com aria-label.
     */
    public static function icon(string $name): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' . (self::ICONS[$name] ?? '') . '</svg>';
    }

    public static function badge(string $text, string $tone = ''): string
    {
        $tone = trim($tone);

        return '<span class="nfse-badge' . ($tone !== '' ? ' nfse-badge--' . self::h($tone) : '') . '">' . self::h($text) . '</span>';
    }

    /**
     * Badge de status de NFS-e ou da fila, com o tom do StatusBadge e o rótulo em português (o código
     * fica no title). Status desconhecido fica neutro e com o texto original.
     */
    public static function statusBadge(string $status): string
    {
        $status = trim($status);
        $code = strtoupper($status);
        $tone = self::STATUS_TONES[$code] ?? '';
        $label = self::statusLabel($status);
        if ($label === $status) {
            return self::badge($status, $tone);
        }

        return '<span class="nfse-badge' . ($tone !== '' ? ' nfse-badge--' . self::h($tone) : '') . '" title="' . self::h($code) . '">' . self::h($label) . '</span>';
    }

    /**
     * Rótulo em português de um status de NFS-e ou da fila (Ui::STATUS_LABELS); desconhecido volta como veio.
     */
    public static function statusLabel(string $status): string
    {
        $status = trim($status);

        return self::STATUS_LABELS[strtoupper($status)] ?? $status;
    }

    /**
     * Opções de select com os rótulos de status, mantendo o código como valor.
     *
     * @param array<int, string> $codes
     * @return array<string, string>
     */
    public static function statusOptions(array $codes, string $allLabel = 'Todos os status'): array
    {
        $options = $allLabel !== '' ? ['' => $allLabel] : [];
        foreach ($codes as $code) {
            $options[$code] = self::statusLabel($code);
        }

        return $options;
    }

    /**
     * Aviso com ícone, título e texto. Tons: info, ok, warn, danger. $bodyHtml (opcional) entra
     * depois do texto, já em HTML (links, listas).
     */
    public static function callout(string $tone, string $title, string $text = '', string $bodyHtml = ''): string
    {
        $tone = in_array($tone, self::CALLOUT_TONES, true) ? $tone : 'info';
        $role = $tone === 'danger' || $tone === 'warn' ? 'alert' : 'status';

        $html = '<div class="nfse-callout nfse-callout--' . $tone . '" role="' . $role . '">';
        $html .= '<svg class="nfse-callout__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
            . self::ICONS[$tone] . '</svg>';
        $html .= '<div class="nfse-callout__body">';
        if ($title !== '') {
            $html .= '<div class="nfse-callout__title">' . self::h($title) . '</div>';
        }
        if ($text !== '' || $bodyHtml !== '') {
            $html .= '<div class="nfse-callout__text">' . self::h($text) . $bodyHtml . '</div>';
        }

        return $html . '</div></div>';
    }

    /**
     * Indicadores num card só, com divisórias. Cada célula: label, value e tone opcional
     * (zero, ok, warn, danger). Valor zero sem tone recebe "zero".
     *
     * @param array<int, array{label: string, value: string|int, tone?: string}> $cells
     */
    public static function kpis(array $cells, bool $small = true): string
    {
        $html = '<div class="nfse-kpis' . ($small ? ' nfse-kpis--sm' : '') . '">';
        foreach ($cells as $cell) {
            $value = $cell['value'];
            $tone = (string) ($cell['tone'] ?? '');
            if ($tone === '' && (is_int($value) || ctype_digit((string) $value)) && (int) $value === 0) {
                $tone = 'zero';
            }
            $display = is_int($value) ? number_format($value, 0, ',', '.') : (string) $value;
            $html .= '<div class="nfse-kpi"><div class="nfse-kpi__label">' . self::h($cell['label']) . '</div>'
                . '<div class="nfse-kpi__value' . ($tone !== '' ? ' nfse-kpi__value--' . self::h($tone) : '') . '">' . self::h($display) . '</div></div>';
        }

        return $html . '</div>';
    }

    /**
     * Linha vazia de tabela: uma frase discreta ocupando todas as colunas.
     */
    public static function emptyRow(int $colspan, string $text): string
    {
        return '<tr><td class="nfse-table__empty" colspan="' . max(1, $colspan) . '">' . self::h($text) . '</td></tr>';
    }

    /**
     * "Exibindo 1–50 de 120 itens" (ou "Nenhum item" quando vazio).
     */
    public static function rangeText(int $first, int $last, int $total, string $noun = 'itens'): string
    {
        if ($total <= 0) {
            return 'Nenhum resultado';
        }

        return 'Exibindo <b>' . number_format($first, 0, ',', '.') . '–' . number_format($last, 0, ',', '.') . '</b> de <b>'
            . number_format($total, 0, ',', '.') . '</b>' . ($noun !== '' ? ' ' . self::h($noun) : '');
    }

    /**
     * Rodapé da tabela, dentro do mesmo card: resumo à esquerda (HTML, ex.: rangeText) e ferramentas à direita.
     */
    public static function tableFooter(string $summaryHtml, string $toolsHtml = ''): string
    {
        $html = '<div class="nfse-tfoot"><div class="nfse-tfoot__summary">' . $summaryHtml . '</div>';
        if ($toolsHtml !== '') {
            $html .= '<div class="nfse-tfoot__tools">' . $toolsHtml . '</div>';
        }

        return $html . '</div>';
    }

    /**
     * Anterior / "Página x de y" / Próxima. Os links usam $params + page.
     *
     * @param array<string, string|int> $params
     */
    public static function pager(array $params, int $page, int $totalPages, string $url = 'addonmodules.php'): string
    {
        $totalPages = max(1, $totalPages);
        $page = min(max(1, $page), $totalPages);
        $link = static fn (int $p): string => $url . '?' . http_build_query($params + ['page' => $p], '', '&', PHP_QUERY_RFC3986);

        $html = '<span class="nfse-pager">';
        $html .= $page > 1
            ? self::button('Anterior', ['sm'], ['href' => $link($page - 1)])
            : '<span class="nfse-btn nfse-btn--sm" aria-disabled="true">Anterior</span>';
        $html .= '<span class="nfse-pager__current">Página ' . $page . ' de ' . $totalPages . '</span>';
        $html .= $page < $totalPages
            ? self::button('Próxima', ['sm'], ['href' => $link($page + 1)])
            : '<span class="nfse-btn nfse-btn--sm" aria-disabled="true">Próxima</span>';

        return $html . '</span>';
    }

    /**
     * Form GET de paginação: select de página, select de registros por página e "Aplicar".
     * Campos: page e per_page (os mesmos nomes de antes); $hidden repete os filtros.
     *
     * @param array<string, string|int> $hidden
     * @param array<int, int> $perPageOptions
     */
    public static function pageForm(array $hidden, int $page, int $totalPages, int $perPage, array $perPageOptions, string $url = 'addonmodules.php'): string
    {
        $pages = [];
        for ($p = 1; $p <= max(1, $totalPages); $p++) {
            $pages[$p] = 'Página ' . $p . ' de ' . max(1, $totalPages);
        }
        $perPages = [];
        foreach ($perPageOptions as $option) {
            $perPages[$option] = $option . ' por página';
        }

        return '<form class="nfse-tfoot__form" method="get" action="' . self::h($url) . '">'
            . self::hidden($hidden)
            . '<label class="nfse-sr-only" for="nfse-page-select">Página</label>'
            . self::select('page', $pages, (string) $page, ['id' => 'nfse-page-select'], 'nfse-input--sm')
            . '<label class="nfse-sr-only" for="nfse-per-page-select">Registros por página</label>'
            . self::select('per_page', $perPages, (string) $perPage, ['id' => 'nfse-per-page-select'], 'nfse-input--sm')
            . self::button('Aplicar', ['sm'])
            . '</form>';
    }

    /**
     * Linha de formulário (Configurações): rótulo à esquerda, campo à direita, ajuda embaixo.
     *
     * @param array{id?: string, required?: bool, error?: string} $opts id do campo (for do label),
     *     required (asterisco) e error (mensagem; marca a linha como inválida)
     */
    public static function formRow(string $label, string $fieldHtml, string $help = '', array $opts = []): string
    {
        $id = (string) ($opts['id'] ?? '');
        $error = (string) ($opts['error'] ?? '');

        $html = '<div class="nfse-form__row' . ($error !== '' ? ' nfse-form__row--invalid' : '') . '">';
        $html .= $id !== ''
            ? '<label class="nfse-form__label" for="' . self::h($id) . '">'
            : '<div class="nfse-form__label">';
        $html .= self::h($label);
        if (!empty($opts['required'])) {
            $html .= '<span class="nfse-form__req" aria-hidden="true">*</span>';
        }
        $html .= $id !== '' ? '</label>' : '</div>';
        $html .= '<div class="nfse-form__field">' . $fieldHtml;
        if ($error !== '') {
            $html .= '<div class="nfse-form__error">' . self::h($error) . '</div>';
        }
        if ($help !== '') {
            $html .= '<div class="nfse-form__help">' . self::h($help) . '</div>';
        }
        $more = (string) ($opts['more'] ?? '');
        if ($more !== '') {
            $html .= '<details class="nfse-form__more"><summary>' . self::h((string) ($opts['moreLabel'] ?? 'Como funciona')) . '</summary>'
                . '<div class="nfse-form__more-body">' . self::h($more) . '</div></details>';
        }

        return $html . '</div></div>';
    }

    /**
     * Barra de salvar no fim da seção. $sticky fixa no rodapé da tela em formulários longos.
     */
    public static function saveBar(string $buttonsHtml, string $note = '', bool $sticky = false): string
    {
        return '<div class="nfse-savebar' . ($sticky ? ' nfse-savebar--sticky' : '') . '">'
            . ($note !== '' ? '<span class="nfse-savebar__note">' . self::h($note) . '</span>' : '')
            . $buttonsHtml . '</div>';
    }

    /**
     * Atalhos em chips (ex.: meses). Cada item: label, href e current (bool).
     *
     * @param array<int, array{label: string, href: string, current?: bool}> $items
     */
    public static function chips(array $items, string $ariaLabel, bool $small = false): string
    {
        $html = '<nav class="nfse-chips' . ($small ? ' nfse-chips--sm' : '') . '" aria-label="' . self::h($ariaLabel) . '">';
        foreach ($items as $item) {
            $html .= '<a class="nfse-chip" href="' . self::h($item['href']) . '"' . (!empty($item['current']) ? ' aria-current="true"' : '') . '>'
                . self::h($item['label']) . '</a>';
        }

        return $html . '</nav>';
    }
}
