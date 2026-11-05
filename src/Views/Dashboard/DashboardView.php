<?php

declare(strict_types=1);

namespace OpenNfse\Views\Dashboard;

/**
 * Monta o HTML da Dashboard com as classes nfse- de assets/nfse-ui.css.
 * Não acessa o banco: recebe os dados que o AdminController leu.
 */
final class DashboardView
{
    private const ICONS = [
        'check' => '<circle cx="12" cy="12" r="9"></circle><path d="M8 12.5l2.5 2.5L16 9.5"></path>',
        'alert' => '<path d="M12 3l9 16H3z"></path><path d="M12 10v4M12 16.5v.5"></path>',
        'clock' => '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>',
        'prev' => '<path d="M15 6l-6 6 6 6"></path>',
        'next' => '<path d="M9 6l6 6-6 6"></path>',
    ];

    private const FEED_ANCHOR = 'nfse-atividade';

    /**
     * @param array{
     *     today: string,
     *     logo_url?: string,
     *     version?: array<string, mixed>,
     *     period: array{periodo: string, start: string, end: string},
     *     metrics: array<string, mixed>,
     *     series: array<int, array<string, mixed>>,
     *     dia?: string,
     *     feed_filter?: string,
     *     feed_items?: array<int, array<string, mixed>>,
     *     nao_emitidas?: int,
     *     alertas_abertos?: int,
     *     certificate?: array<string, mixed>,
     *     customfield_missing?: bool
     * } $d
     */
    public static function render(array $d): string
    {
        $period = $d['period'];
        $start = $period['start'];
        $end = $period['end'];
        $metrics = $d['metrics'];
        $series = $d['series'];
        $today = $d['today'];
        $dia = DashboardPeriod::selectedDay((string) ($d['dia'] ?? ''), $start, $end);
        $feedFilter = DashboardFeed::filter((string) ($d['feed_filter'] ?? ''));
        $periodParams = DashboardPeriod::queryParams($period['periodo'], $start, $end);

        $range = ['updated_from' => $start, 'updated_to' => $end];
        $health = DashboardHealth::items(
            [
                'erros' => (int) ($metrics['com_erro_periodo'] ?? 0),
                'pendentes' => (int) ($metrics['pendentes'] ?? 0),
                'rejeitadas' => (int) ($metrics['rejeitadas'] ?? 0),
                'aguardando_status' => (int) ($metrics['aguardando_status'] ?? 0),
                'nao_emitidas' => (int) ($d['nao_emitidas'] ?? 0),
            ],
            $d['certificate'] ?? [],
            (bool) ($d['customfield_missing'] ?? false),
            (int) ($d['alertas_abertos'] ?? 0),
            [
                'fila' => self::url(['action' => 'fila'] + $range),
                'auditoria' => self::url(['action' => 'relatorios', 'tab' => 'auditoria', 'data_inicial' => $start, 'data_final' => $end]),
                'certificado' => self::url(['action' => 'config', 'tab' => 'ambiente']),
                'customfield' => self::url(['action' => 'config', 'tab' => 'processamento']),
                'alertas' => self::url(['action' => 'alertas']),
            ]
        );

        $html = '<div class="nfse-ui nfse-page">';
        $html .= self::header($d, $period, $today);
        $html .= self::health($health);
        $html .= self::kpis($metrics);
        $html .= '<div class="nfse-split">';
        $html .= self::calendar($series, $start, $end, $today, $dia, $feedFilter, $periodParams);
        $html .= self::weekdays($series, $today, $start, $end);
        $html .= '</div>';
        $html .= self::strips($series);
        $html .= self::feed($d['feed_items'] ?? [], $metrics, $series, $start, $end, $dia, $feedFilter, $periodParams, $today);
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $d
     * @param array{periodo: string, start: string, end: string} $period
     */
    private static function header(array $d, array $period, string $today): string
    {
        $version = $d['version'] ?? [];
        $current = ltrim(trim((string) ($version['current'] ?? '')), 'vV ');
        $latest = ltrim(trim((string) ($version['latest'] ?? '')), 'vV ');
        $href = trim((string) ($version['href'] ?? ''));

        $html = '<header class="nfse-header">';
        $html .= '<div class="nfse-header__brand">';
        $logo = trim((string) ($d['logo_url'] ?? ''));
        if ($logo !== '') {
            $html .= '<img class="nfse-logo" src="' . self::h($logo) . '" alt="OpenNfse" width="40" height="40">';
        }
        $html .= '<div><div class="nfse-title-row"><h1 class="nfse-title">OpenNfse</h1>';
        if (!empty($version['update_available']) && $latest !== '') {
            $label = 'v' . ($current !== '' ? $current : '—') . ' → ' . $latest . ' disponível';
            $html .= $href !== ''
                ? '<a class="nfse-badge nfse-badge--version nfse-badge--warn" href="' . self::h($href) . '">' . self::h($label) . '</a>'
                : '<span class="nfse-badge nfse-badge--version nfse-badge--warn">' . self::h($label) . '</span>';
        } elseif ($current !== '') {
            $html .= '<span class="nfse-badge nfse-badge--version">v' . self::h($current) . '</span>';
        }
        $html .= '</div><div class="nfse-subtitle">Visão geral da operação fiscal</div></div>';
        $html .= '</div>';

        $start = $period['start'];
        $end = $period['end'];
        $prev = DashboardPeriod::previousMonth($start, $end);
        $next = DashboardPeriod::nextMonth($start, $end);
        $monthParams = static fn (array $r): array => ['periodo' => 'personalizado', 'data_inicial' => $r['start'], 'data_final' => $r['end']];

        $html .= '<div class="nfse-header__tools">';
        $html .= '<a class="nfse-btn nfse-btn--icon" href="' . self::h(self::dashboardUrl($monthParams($prev))) . '" aria-label="Mês anterior">' . self::icon('prev') . '</a>';
        $html .= '<span class="nfse-period">' . self::h(DashboardPeriod::label($start, $end)) . '</span>';
        if ($next['start'] <= $today) {
            $html .= '<a class="nfse-btn nfse-btn--icon" href="' . self::h(self::dashboardUrl($monthParams($next))) . '" aria-label="Próximo mês">' . self::icon('next') . '</a>';
        } else {
            $html .= '<span class="nfse-btn nfse-btn--icon" aria-disabled="true" aria-label="Próximo mês">' . self::icon('next') . '</span>';
        }

        $html .= '<nav class="nfse-chips" aria-label="Período">';
        foreach (['hoje' => 'Hoje', '7_dias' => '7 dias'] as $key => $label) {
            $ariaCurrent = $period['periodo'] === $key ? ' aria-current="true"' : '';
            $html .= '<a class="nfse-chip" href="' . self::h(self::dashboardUrl(['periodo' => $key])) . '"' . $ariaCurrent . '>' . self::h($label) . '</a>';
        }
        $custom = $period['periodo'] === 'personalizado' && !DashboardPeriod::isFullMonth($start, $end);
        $html .= '<details class="nfse-custom">';
        $html .= '<summary class="nfse-chip"' . ($custom ? ' aria-current="true"' : '') . '>Personalizado</summary>';
        $html .= '<form class="nfse-custom__panel" method="get" action="addonmodules.php">';
        $html .= self::hidden(['module' => 'OpenNfse', 'action' => 'dashboard', 'periodo' => 'personalizado']);
        $html .= '<label class="nfse-field">Data inicial<input class="nfse-input" type="date" name="data_inicial" value="' . self::h($start) . '" required></label>';
        $html .= '<label class="nfse-field">Data final<input class="nfse-input" type="date" name="data_final" value="' . self::h($end) . '" required></label>';
        $html .= '<button class="nfse-btn nfse-btn--primary" type="submit">Aplicar</button>';
        $html .= '</form></details>';
        $html .= '</nav>';
        $html .= '</div>';
        $html .= '</header>';

        return $html;
    }

    /**
     * @param array<int, array<string, string>> $items
     */
    private static function health(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $html = '<div class="nfse-health" role="status">';
        foreach ($items as $item) {
            $html .= '<div class="nfse-health__item nfse-health__item--' . self::h($item['tone']) . '">';
            $html .= self::icon($item['icon']);
            $html .= '<div class="nfse-health__body"><div class="nfse-health__title">' . self::h($item['title']) . '</div>';
            $html .= '<div class="nfse-health__text">' . self::h($item['text']) . '</div></div>';
            if ($item['action'] !== '' && $item['href'] !== '') {
                $html .= '<a href="' . self::h($item['href']) . '">' . self::h($item['action']) . '</a>';
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private static function kpis(array $metrics): string
    {
        $emitidas = (int) ($metrics['emitidas'] ?? 0);
        $erros = (int) ($metrics['com_erro_periodo'] ?? 0);
        $valor = (float) ($metrics['valor_total'] ?? 0);
        $hasOps = $emitidas + $erros > 0;
        $taxa = (float) ($metrics['taxa_sucesso'] ?? 0);

        $cells = [
            ['Emitidas', self::number($emitidas), $emitidas === 0 ? 'zero' : ''],
            ['Valor emitido', self::money($valor), $valor == 0.0 ? 'zero' : ''],
            ['Canceladas', self::number((int) ($metrics['canceladas'] ?? 0)), (int) ($metrics['canceladas'] ?? 0) === 0 ? 'zero' : ''],
            [
                'Taxa de sucesso',
                $hasOps ? number_format($taxa, 1, ',', '.') . '%' : '—',
                !$hasOps ? 'zero' : ($taxa >= 100.0 ? 'ok' : ''),
            ],
            ['Processadas', self::number((int) ($metrics['movimentadas'] ?? 0)), (int) ($metrics['movimentadas'] ?? 0) === 0 ? 'zero' : ''],
            ['XMLs armazenados', self::number((int) ($metrics['xmls'] ?? 0)), (int) ($metrics['xmls'] ?? 0) === 0 ? 'zero' : ''],
        ];

        $html = '<div class="nfse-kpis">';
        foreach ($cells as [$label, $value, $mod]) {
            $class = 'nfse-kpi__value' . ($mod !== '' ? ' nfse-kpi__value--' . $mod : '');
            $html .= '<div class="nfse-kpi"><div class="nfse-kpi__label">' . self::h($label) . '</div>';
            $html .= '<div class="' . $class . '">' . self::h($value) . '</div></div>';
        }

        return $html . '</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $series
     * @param array<string, string> $periodParams
     */
    private static function calendar(array $series, string $start, string $end, string $today, string $dia, string $feedFilter, array $periodParams): string
    {
        $summary = DashboardCalendar::summary($series, $today);
        if ($summary['total'] === 0) {
            $summaryText = 'Nenhuma emissão no período';
        } else {
            $summaryText = self::number($summary['total']) . ' no período · média '
                . number_format($summary['average'], 1, ',', '.') . '/dia · pico '
                . self::number($summary['peak']) . ' em ' . substr($summary['peak_date'], 8, 2) . '/' . substr($summary['peak_date'], 5, 2);
        }

        $html = '<section class="nfse-card nfse-card--pad nfse-split__main">';
        $html .= '<div class="nfse-chart-head"><div><h2 class="nfse-section-title">Emissões por dia</h2>';
        $html .= '<div class="nfse-card__summary">' . self::h($summaryText) . '</div></div>';
        $html .= '<div class="nfse-heat-scale" aria-hidden="true">menos <span class="nfse-heat-1"></span><span class="nfse-heat-2"></span><span class="nfse-heat-3"></span><span class="nfse-heat-4"></span><span class="nfse-heat-5"></span> mais</div>';
        $html .= '</div>';

        $html .= '<div class="nfse-cal nfse-cal--head" aria-hidden="true">';
        foreach (DashboardCalendar::WEEKDAYS as $name) {
            $html .= '<span class="nfse-cal__dow">' . self::h($name) . '</span>';
        }
        $html .= '</div>';

        $html .= '<form class="nfse-cal" method="get" action="addonmodules.php#' . self::FEED_ANCHOR . '">';
        $html .= self::hidden(['module' => 'OpenNfse', 'action' => 'dashboard'] + $periodParams + ($feedFilter !== 'tudo' ? ['feed' => $feedFilter] : []));
        foreach (DashboardCalendar::grid($series, $start, $end, $today, $dia) as $cell) {
            if ($cell['empty']) {
                $html .= '<span class="nfse-cal__cell nfse-cal__cell--empty"></span>';
                continue;
            }
            $marks = '';
            if ($cell['canceladas'] > 0) {
                $marks .= '<span class="nfse-mark">' . self::h((string) $cell['canceladas']) . '</span>';
            }
            if ($cell['erros'] > 0) {
                $marks .= '<span class="nfse-mark nfse-mark--erro">' . self::h((string) $cell['erros']) . '</span>';
            }
            $dayLabel = $cell['day'] . ($cell['month'] !== '' ? ' ' . $cell['month'] : '');
            $aria = self::dateLabel($cell['date']) . ': ' . self::plural($cell['count'], 'emitida', 'emitidas');
            if ($cell['canceladas'] > 0) {
                $aria .= ', ' . self::plural($cell['canceladas'], 'cancelada', 'canceladas');
            }
            if ($cell['erros'] > 0) {
                $aria .= ', ' . self::plural($cell['erros'], 'erro', 'erros');
            }
            $inner = '<span class="nfse-cal__top"><span' . ($cell['month'] !== '' ? ' class="nfse-cal__month"' : '') . '>' . self::h($dayLabel) . '</span>'
                . '<span class="nfse-cal__marks">' . $marks . '</span></span>'
                . '<span class="nfse-cal__count">' . ($cell['future'] ? '' : self::h((string) $cell['count'])) . '</span>';
            $class = 'nfse-cal__cell nfse-heat-' . ($cell['future'] ? 1 : $cell['band']);
            if ($cell['future']) {
                $html .= '<span class="' . $class . ' nfse-cal__cell--future" aria-label="' . self::h(self::dateLabel($cell['date'])) . '">' . $inner . '</span>';
                continue;
            }
            $html .= '<button type="submit" class="' . $class . '" name="dia" value="' . self::h($cell['selected'] ? '' : $cell['date']) . '"'
                . ' aria-pressed="' . ($cell['selected'] ? 'true' : 'false') . '" aria-label="' . self::h($aria) . '">' . $inner . '</button>';
        }
        $html .= '</form>';

        $html .= '<div class="nfse-legend nfse-legend--below">';
        $html .= '<span class="nfse-legend__item"><span class="nfse-swatch nfse-swatch--cancelada"></span>Canceladas no dia</span>';
        $html .= '<span class="nfse-legend__item"><span class="nfse-swatch nfse-swatch--erro"></span>Erros no dia</span>';
        $html .= '<span>Clique num dia para filtrar a atividade recente</span>';
        $html .= '</div>';

        return $html . '</section>';
    }

    /**
     * @param array<int, array<string, mixed>> $series
     */
    private static function weekdays(array $series, string $today, string $start, string $end): string
    {
        $html = '<section class="nfse-card nfse-card--pad nfse-split__side nfse-stack">';
        $html .= '<div><h2 class="nfse-section-title">Média por dia da semana</h2>';
        $html .= '<div class="nfse-card__summary">' . self::h(DashboardPeriod::label($start, $end)) . ' · ajuda a prever a carga da fila</div></div>';
        $html .= '<div class="nfse-hbars">';
        foreach (DashboardCalendar::weekdayAverages($series, $today) as $row) {
            $value = $row['average'] === null ? '—' : number_format($row['average'], 1, ',', '.');
            $html .= '<div class="nfse-hbar"><span>' . self::h($row['name']) . '</span>';
            $html .= '<div class="nfse-hbar__track"><div class="nfse-hbar__fill' . ($row['weekend'] ? ' nfse-hbar__fill--weekend' : '') . '"'
                . ' style="width:' . self::h(self::pct($row['percent'])) . '"></div></div>';
            $html .= '<span class="nfse-hbar__value">' . self::h($value) . '</span></div>';
        }

        return $html . '</div></section>';
    }

    /**
     * @param array<int, array<string, mixed>> $series
     */
    private static function strips(array $series): string
    {
        $data = DashboardStrips::build($series);

        $html = '<section class="nfse-card nfse-card--pad">';
        $html .= '<div class="nfse-chart-head"><h2 class="nfse-section-title">Linha do tempo</h2>';
        $html .= '<span class="nfse-card__summary">cada faixa tem escala própria · mesmo eixo de datas</span></div>';

        if ($data['days'] === []) {
            return $html . '<div class="nfse-card__summary">Sem dias no período.</div></section>';
        }

        $html .= '<div class="nfse-scroll-x"><div class="nfse-strips' . ($data['long'] ? ' nfse-strips--long' : '') . '">';
        foreach ($data['strips'] as $strip) {
            $main = $strip['key'] === 'emitidas';
            $barClass = match ($strip['key']) {
                'canceladas' => ' nfse-strip__bar--cancelada',
                'erros_resolvidos' => ' nfse-strip__bar--erro',
                default => '',
            };
            $noun = strtolower($strip['label']);
            $html .= '<span class="nfse-strip__label">' . self::h($strip['label']) . '</span>';
            $html .= '<div class="nfse-strip ' . ($main ? 'nfse-strip--main' : 'nfse-strip--minor') . '" role="img" aria-label="'
                . self::h($strip['label'] . ': ' . self::number($strip['total']) . ' no período, escala até ' . $strip['scale']) . '">';
            foreach ($strip['bars'] as $i => $bar) {
                $day = $data['days'][$i];
                $class = 'nfse-strip__bar' . $barClass . ($main && $day['weekend'] ? ' nfse-strip__bar--weekend' : '');
                $html .= '<div class="' . $class . '" style="height:' . self::h(self::pct($bar['percent'])) . '"'
                    . ' title="' . self::h($day['label'] . ': ' . $bar['value'] . ' ' . $noun) . '"></div>';
            }
            $html .= '</div>';
            $html .= '<span class="nfse-strip__total">' . self::h(self::number($strip['total'])) . '</span>';
        }
        $html .= '<span></span><div class="nfse-strip__axis" aria-hidden="true">';
        foreach ($data['days'] as $day) {
            $html .= '<span>' . self::h($day['axis']) . '</span>';
        }
        $html .= '</div><span></span>';

        return $html . '</div></div></section>';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $metrics
     * @param array<int, array<string, mixed>> $series
     * @param array<string, string> $periodParams
     */
    private static function feed(
        array $items,
        array $metrics,
        array $series,
        string $start,
        string $end,
        string $dia,
        string $filter,
        array $periodParams,
        string $today
    ): string {
        if ($dia !== '') {
            $row = DashboardCalendar::indexByDate($series)[$dia] ?? [];
            $counts = [
                'emitidas' => (int) ($row['emitidas'] ?? 0),
                'canceladas' => (int) ($row['canceladas'] ?? 0),
                'erros' => (int) ($row['erros_resolvidos'] ?? 0),
            ];
        } else {
            $counts = [
                'emitidas' => (int) ($metrics['emitidas'] ?? 0),
                'canceladas' => (int) ($metrics['canceladas'] ?? 0),
                'erros' => (int) ($metrics['erros_resolvidos_periodo'] ?? 0),
            ];
        }
        $counts['tudo'] = array_sum($counts);

        $html = '<section class="nfse-card nfse-card--clip" id="' . self::FEED_ANCHOR . '">';
        $html .= '<div class="nfse-card__head"><h2 class="nfse-card__title">Atividade recente';
        if ($dia !== '') {
            $html .= ' <span class="nfse-card__summary">em ' . self::h(self::dateLabel($dia)) . '</span>';
        }
        $html .= '</h2>';
        $html .= '<form class="nfse-chips" method="get" action="addonmodules.php#' . self::FEED_ANCHOR . '" role="group" aria-label="Filtrar atividade">';
        $html .= self::hidden(['module' => 'OpenNfse', 'action' => 'dashboard'] + $periodParams + ($dia !== '' ? ['dia' => $dia] : []));
        foreach (['tudo' => 'Tudo', 'emitidas' => 'Emitidas', 'canceladas' => 'Canceladas', 'erros' => 'Erros'] as $key => $label) {
            $html .= '<button class="nfse-chip" type="submit" name="feed" value="' . self::h($key) . '" aria-pressed="' . ($filter === $key ? 'true' : 'false') . '">'
                . self::h($label) . '<span class="nfse-chip__count">' . self::h(self::number($counts[$key])) . '</span></button>';
        }
        if ($dia !== '') {
            $html .= '<a class="nfse-chip" href="' . self::h(self::dashboardUrl($periodParams + ($filter !== 'tudo' ? ['feed' => $filter] : [])) . '#' . self::FEED_ANCHOR) . '">Todo o período</a>';
        }
        $html .= '</form></div>';

        $html .= '<div class="nfse-feed">';
        if ($items === []) {
            $html .= '<div class="nfse-feed__empty">Nenhuma atividade ' . ($dia !== '' ? 'neste dia' : 'no período') . '.</div>';
        }
        $year = substr($today, 0, 4);
        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? 'emitida');
            $dot = 'nfse-feed__dot' . ($type === 'cancelada' ? ' nfse-feed__dot--cancelada' : ($type === 'erro' ? ' nfse-feed__dot--erro' : ''));
            $invoiceId = (int) ($item['invoiceid'] ?? 0);
            $data = (string) ($item['data'] ?? '');
            $when = '';
            if ($data !== '' && ($ts = strtotime($data)) !== false) {
                $when = date(substr($data, 0, 4) === $year ? 'd/m H:i' : 'd/m/Y H:i', $ts);
            }
            $fatura = $invoiceId > 0
                ? '<a href="' . self::h('invoices.php?action=edit&id=' . $invoiceId) . '">Fatura #' . self::h((string) $invoiceId) . '</a>'
                : 'Registro operacional';
            $html .= '<div class="nfse-feed__row"><span class="' . $dot . '" aria-hidden="true"></span>';
            $html .= '<div><span class="nfse-feed__title">' . self::h((string) ($item['title'] ?? '')) . '</span> ';
            $html .= '<span class="nfse-feed__meta">· ' . $fatura . ' · ' . self::h((string) ($item['client'] ?? '-')) . '</span></div>';
            $html .= '<span class="nfse-feed__when">' . self::h($when) . '</span></div>';
        }
        $html .= '</div>';

        $tab = match ($filter) {
            'canceladas' => 'cancelamentos',
            'erros' => 'falhas',
            default => 'emitidas',
        };
        $from = $dia !== '' ? $dia : $start;
        $to = $dia !== '' ? $dia : $end;
        $html .= '<div class="nfse-feed__foot"><a href="' . self::h(self::url(['action' => 'relatorios', 'tab' => $tab, 'data_inicial' => $from, 'data_final' => $to])) . '">Ver relatório completo</a></div>';

        return $html . '</section>';
    }

    /**
     * @param array<string, string> $params
     */
    public static function dashboardUrl(array $params = []): string
    {
        return self::url(['action' => 'dashboard'] + $params);
    }

    /**
     * @param array<string, string> $params
     */
    private static function url(array $params): string
    {
        return 'addonmodules.php?' . http_build_query(['module' => 'OpenNfse'] + $params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, string> $fields
     */
    private static function hidden(array $fields): string
    {
        $html = '';
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . self::h((string) $name) . '" value="' . self::h((string) $value) . '">';
        }

        return $html;
    }

    private static function icon(string $name): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' . (self::ICONS[$name] ?? '') . '</svg>';
    }

    private static function dateLabel(string $date): string
    {
        return DashboardPeriod::isDate($date) ? substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4) : '';
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return DashboardHealth::plural($n, $one, $many);
    }

    private static function number(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    public static function money(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    private static function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . '%';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
