<?php

declare(strict_types=1);

namespace OpenNfse\Views;

/**
 * Peças de tela dos Relatórios (e das telas que saem deles: alertas, logs, cancelamento) sobre os
 * componentes de Ui. Funções puras que devolvem HTML com escape; parâmetros terminados em "Html"
 * recebem HTML já montado.
 */
final class ReportView
{
    /**
     * Botão que envia um POST para addonmodules.php com module, action, token e os parâmetros como
     * campos ocultos (os mesmos campos de AdminHelpersTrait::renderPostActionButton), sem estilo inline.
     *
     * @param array<string, string|int> $params
     */
    public static function postButton(string $action, string $label, array $params, string $token, string $class = 'nfse-btn nfse-btn--sm', string $confirm = ''): string
    {
        $html = '<form class="nfse-inline-form" method="post" action="addonmodules.php">';
        $html .= Ui::hidden(['module' => 'OpenNfse', 'action' => $action]);
        if ($token !== '') {
            $html .= Ui::hidden(['token' => $token]);
        }
        $html .= Ui::hidden($params);
        $html .= '<button type="submit" class="' . Ui::h($class) . '"'
            . ($confirm !== '' ? ' onclick="return confirm(' . Ui::h(json_encode($confirm, JSON_UNESCAPED_UNICODE) ?: '""') . ');"' : '')
            . '>' . Ui::h($label) . '</button>';

        return $html . '</form>';
    }

    /**
     * Cabeçalho da tabela. Cada coluna: rótulo ou [rótulo, classe] (ex.: ['Valor', 'nfse-num']).
     *
     * @param array<int, string|array{0: string, 1?: string}> $columns
     */
    public static function thead(array $columns): string
    {
        $html = '<thead><tr>';
        foreach ($columns as $column) {
            [$label, $class] = is_array($column) ? [$column[0], $column[1] ?? ''] : [$column, ''];
            $html .= '<th' . ($class !== '' ? ' class="' . Ui::h($class) . '"' : '') . '>' . Ui::h($label) . '</th>';
        }

        return $html . '</tr></thead>';
    }

    /**
     * Card com tabela: título opcional no cabeçalho do card (com resumo à direita), tabela que rola
     * dentro do card e rodapé opcional (Ui::tableFooter).
     *
     * @param array<int, string|array{0: string, 1?: string}> $columns
     * @param string $rowsHtml linhas <tr> já montadas; vazio mostra $emptyText
     */
    public static function tableCard(array $columns, string $rowsHtml, string $emptyText, string $title = '', string $summary = '', string $footerHtml = '', string $labelledBy = ''): string
    {
        $headingId = $labelledBy;
        $html = '<section class="nfse-card nfse-card--table"' . ($headingId !== '' ? ' aria-labelledby="' . Ui::h($headingId) . '"' : '') . '>';
        if ($title !== '') {
            $html .= '<div class="nfse-card__head"><h3 class="nfse-card__title"' . ($headingId !== '' ? ' id="' . Ui::h($headingId) . '"' : '') . '>' . Ui::h($title) . '</h3>';
            if ($summary !== '') {
                $html .= '<span class="nfse-card__summary">' . Ui::h($summary) . '</span>';
            }
            $html .= '</div>';
        }
        $html .= '<div class="nfse-table-wrap"><table class="nfse-table">' . self::thead($columns) . '<tbody>';
        $html .= $rowsHtml !== '' ? $rowsHtml : Ui::emptyRow(count($columns), $emptyText);
        $html .= '</tbody></table></div>';

        return $html . $footerHtml . '</section>';
    }

    /**
     * Card de informação: título (h3) e pares rótulo/valor numa grade. Valores são texto (escapados);
     * para HTML no valor, use [$label, $html, true].
     *
     * @param array<int, array{0: string, 1: string|int, 2?: bool}> $pairs
     */
    public static function infoCard(string $title, array $pairs, string $noteHtml = ''): string
    {
        $html = '<section class="nfse-card nfse-card--pad nfse-rel-info">';
        if ($title !== '') {
            $html .= '<h3 class="nfse-card__title">' . Ui::h($title) . '</h3>';
        }
        if ($pairs !== []) {
            $html .= self::keyValues($pairs);
        }
        if ($noteHtml !== '') {
            $html .= '<div class="nfse-rel-note">' . $noteHtml . '</div>';
        }

        return $html . '</section>';
    }

    /**
     * Pares rótulo/valor (<dl>).
     *
     * @param array<int, array{0: string, 1: string|int, 2?: bool}> $pairs
     */
    public static function keyValues(array $pairs): string
    {
        $html = '<dl class="nfse-dl">';
        foreach ($pairs as $pair) {
            $value = (string) $pair[1];
            $html .= '<div class="nfse-dl__item"><dt>' . Ui::h($pair[0]) . '</dt><dd>' . (!empty($pair[2]) ? $value : Ui::h($value !== '' ? $value : '-')) . '</dd></div>';
        }

        return $html . '</dl>';
    }

    /**
     * Valor ou traço discreto quando vazio.
     */
    public static function orDash(string $value): string
    {
        $value = trim($value);

        return $value !== '' ? Ui::h($value) : '<span class="nfse-muted">-</span>';
    }

    public static function invoiceLink(int $invoiceId): string
    {
        if ($invoiceId <= 0) {
            return '<span class="nfse-muted">-</span>';
        }

        return '<a href="' . Ui::h('invoices.php?action=edit&id=' . $invoiceId) . '">' . $invoiceId . '</a>';
    }

    /**
     * Barra de progresso; a largura vem do dado (único estilo inline permitido).
     */
    public static function progress(int $percent, string $id = ''): string
    {
        $percent = max(0, min(100, $percent));

        return '<div class="nfse-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $percent . '">'
            . '<div class="nfse-progress__fill"' . ($id !== '' ? ' id="' . Ui::h($id) . '"' : '') . ' style="width:' . $percent . '%"></div></div>';
    }
}
