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
     *     period: array{periodo: string, start: string, end: string},
     *     metrics: array<string, mixed>,
     *     series: array<int, array<string, mixed>>,
     *     dia?: string,
     *     feed_filter?: string,
     *     feed_items?: array<int, array<string, mixed>>,
     *     nao_emitidas?: int,
     *     alertas_abertos?: int,
     *     certificate?: array<string, mixed>,
     *     customfield_missing?: bool,
     *     now?: int,
     *     queue?: array{counts: array<string, int>, oldest_pending: string},
     *     cron?: array{last_run_at: string, source: string},
     *     token?: string
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

        $filaRange = ['updated_from' => $start, 'updated_to' => $end];
        $reportRange = ['data_inicial' => $start, 'data_final' => $end];
        $health = DashboardHealth::items(
            DashboardHealth::counters($metrics, (int) ($d['nao_emitidas'] ?? 0)),
            $d['certificate'] ?? [],
            (bool) ($d['customfield_missing'] ?? false),
            (int) ($d['alertas_abertos'] ?? 0),
            [
                'erros' => self::url(['action' => 'relatorios', 'tab' => 'falhas'] + $reportRange),
                'rejeitadas' => self::url(['action' => 'relatorios', 'tab' => 'emitidas'] + $reportRange + ['status' => 'REJEITADA']),
                'aguardando_status' => self::url(['action' => 'fila', 'status' => 'WAIT_STATUS'] + $filaRange),
                'pendentes' => self::url(['action' => 'fila'] + $filaRange),
                'auditoria' => self::url(['action' => 'relatorios', 'tab' => 'auditoria'] + $reportRange),
                'certificado' => self::url(['action' => 'config', 'tab' => 'ambiente']),
                'customfield' => self::url(['action' => 'config', 'tab' => 'processamento']),
                'alertas' => self::url(['action' => 'alertas']),
            ]
        );

        $now = (int) ($d['now'] ?? time());
        $cron = null;
        if (isset($d['cron'])) {
            $cron = DashboardQueue::cronState((string) ($d['cron']['last_run_at'] ?? ''), (string) ($d['cron']['source'] ?? ''), $now);
            $cronItem = DashboardQueue::cronHealthItem($cron, self::url(['action' => 'config', 'tab' => 'processamento']));
            if ($cronItem !== null) {
                $health[] = $cronItem;
            }
        }

        $html = '<div class="nfse-ui nfse-page">';
        $html .= self::health($health);
        // Barra única: indicadores do período à esquerda, com as ferramentas do período no cabeçalho,
        // e o estado atual da fila à direita, no mesmo card. Sem os dados da fila, só o período.
        $html .= '<div class="nfse-bar' . (isset($d['queue']) ? '' : ' nfse-bar--solo') . '">';
        $html .= '<section class="nfse-bar__period" aria-labelledby="nfse-no-periodo">';
        $html .= '<div class="nfse-bar__head"><span class="nfse-bar__head-title" id="nfse-no-periodo">No período</span>'
            . self::periodControls($period, $today) . '</div>';
        $html .= self::kpis($metrics, 'nfse-bar__kpis') . '</section>';
        if (isset($d['queue'])) {
            $html .= self::queue($d['queue'], $cron, $now, $today, (string) ($d['token'] ?? ''));
        }
        $html .= '</div>';
        // Uma linha com três cards compactos: calendário, média por dia da semana e linha do tempo.
        $html .= '<div class="nfse-days">';
        $html .= self::calendar($series, $start, $end, $today, $dia, $feedFilter, $periodParams);
        $html .= self::weekdays($series, $today);
        $html .= self::timeline($series, $today);
        $html .= '</div>';
        $html .= self::daysLegend();
        $html .= self::feed($d['feed_items'] ?? [], $metrics, $series, $start, $end, $dia, $feedFilter, $periodParams, $today);
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array{periodo: string, start: string, end: string} $period
     */
    private static function periodControls(array $period, string $today): string
    {
        // Ferramentas do período, compactas, no cabeçalho do lado do período da barra única.

        $start = $period['start'];
        $end = $period['end'];
        $prev = DashboardPeriod::previousMonth($start, $end);
        $next = DashboardPeriod::nextMonth($start, $end);
        $monthParams = static fn (array $r): array => ['periodo' => 'personalizado', 'data_inicial' => $r['start'], 'data_final' => $r['end']];

        $html = '<a class="nfse-btn nfse-btn--icon nfse-btn--xs" href="' . self::h(self::dashboardUrl($monthParams($prev))) . '" aria-label="Mês anterior">' . self::icon('prev') . '</a>';
        $html .= '<span class="nfse-periodnav__label">' . self::h(DashboardPeriod::label($start, $end)) . '</span>';
        if ($next['start'] <= $today) {
            $html .= '<a class="nfse-btn nfse-btn--icon nfse-btn--xs" href="' . self::h(self::dashboardUrl($monthParams($next))) . '" aria-label="Próximo mês">' . self::icon('next') . '</a>';
        } else {
            $html .= '<span class="nfse-btn nfse-btn--icon nfse-btn--xs" aria-disabled="true" aria-label="Próximo mês">' . self::icon('next') . '</span>';
        }

        $html .= '<nav class="nfse-chips nfse-chips--sm" aria-label="Período">';
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

        return '<div class="nfse-periodnav" role="group" aria-label="Período da Dashboard">' . $html . '</div>';
    }

    /**
     * Subtítulo da tela na barra de navegação ("Operação fiscal de Novembro 2026") e o complemento que vai
     * só na dica do título ("Certificado válido até 03/12/2026"), para não alongar a barra.
     *
     * @param array{start: string, end: string} $period
     * @param array<string, mixed> $certificate
     * @return array{subtitle: string, detail: string}
     */
    public static function titleParts(array $period, array $certificate): array
    {
        return [
            'subtitle' => 'Operação fiscal de ' . DashboardPeriod::label($period['start'], $period['end']),
            'detail' => DashboardHealth::certificateValidLine($certificate),
        ];
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
            $title = self::h($item['title']);
            if (($item['parts'] ?? []) !== []) {
                $links = [];
                foreach ($item['parts'] as $part) {
                    $links[] = $part['href'] !== ''
                        ? '<a href="' . self::h($part['href']) . '">' . self::h($part['label']) . '</a>'
                        : self::h($part['label']);
                }
                $title = implode(' · ', $links);
            }
            $html .= '<div class="nfse-health__body"><div class="nfse-health__title">' . $title . '</div>';
            $html .= '<div class="nfse-health__text">' . self::h($item['text']) . '</div></div>';
            if ($item['action'] !== '' && $item['href'] !== '') {
                $html .= '<a href="' . self::h($item['href']) . '">' . self::h($item['action']) . '</a>';
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /**
     * Lado direito da barra única: "Fila agora", estado atual, fora do filtro de período.
     *
     * @param array{counts?: array<string, int>, oldest_pending?: string} $queue
     * @param array{ran: bool, seconds: int, stale: bool, text: string, short: string}|null $cron
     */
    private static function queue(array $queue, ?array $cron, int $now, string $today, string $token): string
    {
        // A Fila filtra por updated_at e, sem datas, mostra só os últimos 7 dias: o link abre tudo.
        $allTime = ['updated_from' => '2000-01-01', 'updated_to' => $today];
        $urls = [];
        foreach (array_keys(DashboardQueue::STATUSES) as $status) {
            $urls[$status] = self::url(['action' => 'fila', 'status' => $status] + $allTime);
        }
        $counts = $queue['counts'] ?? [];

        $html = '<section class="nfse-bar__queue" aria-labelledby="nfse-fila-agora">';
        $html .= '<div class="nfse-queue__head">';
        if ($cron !== null) {
            $html .= '<span class="nfse-dot nfse-dot--' . ($cron['stale'] ? 'danger' : 'ok') . '" aria-hidden="true"></span>';
        }
        $html .= '<h2 class="nfse-queue__title" id="nfse-fila-agora" title="Estado atual, fora do período">Fila agora</h2>';
        $html .= '<form class="nfse-queue__form" method="post" action="addonmodules.php?module=OpenNfse&amp;action=filaProcessNow">';
        if ($token !== '') {
            $html .= self::hidden(['token' => $token]);
        }
        $html .= '<button class="nfse-btn nfse-btn--sm" type="submit">Processar agora</button></form>';
        $html .= '</div>';

        $html .= '<div class="nfse-queue__grid">';
        foreach (DashboardQueue::cells($counts, $urls) as $cell) {
            $class = 'nfse-queue__row' . ($cell['tone'] !== '' ? ' nfse-queue__row--' . $cell['tone'] : '');
            $title = $cell['short'] !== $cell['label'] ? ' title="' . self::h($cell['label']) . '"' : '';
            $inner = '<span>' . self::h($cell['short']) . '</span><span class="nfse-queue__n">' . self::h($cell['value']) . '</span>';
            $html .= $cell['href'] !== ''
                ? '<a class="' . $class . '" href="' . self::h($cell['href']) . '"' . $title . '>' . $inner . '</a>'
                : '<span class="' . $class . '"' . $title . '>' . $inner . '</span>';
        }
        $html .= '</div>';

        $oldest = DashboardQueue::oldestPendingText($counts, (string) ($queue['oldest_pending'] ?? ''), $now);
        // Rodapé: "Cron há 1 min · mais antigo há 12 min" à esquerda, "Abrir fila" à direita.
        $meta = [];
        if ($cron !== null) {
            $meta[] = '<span class="nfse-queue__cron' . ($cron['stale'] ? ' nfse-text-danger' : '') . '" title="' . self::h($cron['text']) . '">'
                . self::h($cron['short']) . '</span>';
        }
        if ($oldest !== '') {
            $meta[] = self::h($meta === [] ? ucfirst($oldest) : $oldest);
        }
        $html .= '<div class="nfse-queue__foot">';
        if ($meta !== []) {
            $html .= '<span class="nfse-queue__meta">' . implode(' · ', $meta) . '</span>';
        }
        $html .= '<a href="' . self::h(self::url(['action' => 'fila'])) . '">Abrir fila</a></div>';

        return $html . '</section>';
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private static function kpis(array $metrics, string $class = 'nfse-kpis'): string
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

        $html = '<div class="' . self::h($class) . '">';
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

        $html = '<section class="nfse-card nfse-dcard" aria-labelledby="nfse-emissoes-dia">';
        $html .= '<div class="nfse-dcard__head"><h2 class="nfse-dcard__title" id="nfse-emissoes-dia">Emissões por dia</h2>';
        $html .= '<span class="nfse-dcard__summary">' . self::h($summaryText) . '</span></div>';

        $html .= '<div class="nfse-cal nfse-cal--head" aria-hidden="true">';
        foreach (DashboardCalendar::WEEKDAY_LETTERS as $i => $letter) {
            $html .= '<span class="nfse-cal__dow" title="' . self::h(DashboardCalendar::WEEKDAYS[$i]) . '">' . self::h($letter) . '</span>';
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
                $marks .= '<span class="nfse-cal__dot nfse-cal__dot--cancelada"></span>';
            }
            if ($cell['erros'] > 0) {
                $marks .= '<span class="nfse-cal__dot nfse-cal__dot--erro"></span>';
            }
            $dayLabel = (string) (int) $cell['day'] . ($cell['month'] !== '' ? ' ' . $cell['month'] : '');
            $counts = self::plural($cell['count'], 'emitida', 'emitidas');
            if ($cell['canceladas'] > 0) {
                $counts .= ', ' . self::plural($cell['canceladas'], 'cancelada', 'canceladas');
            }
            if ($cell['erros'] > 0) {
                $counts .= ', ' . self::plural($cell['erros'], 'erro', 'erros');
            }
            $short = substr($cell['date'], 8, 2) . '/' . substr($cell['date'], 5, 2);
            $inner = '<span class="nfse-cal__day' . ($cell['month'] !== '' ? ' nfse-cal__month' : '') . '">' . self::h($dayLabel) . '</span>'
                . ($marks !== '' ? '<span class="nfse-cal__marks" aria-hidden="true">' . $marks . '</span>' : '')
                . '<span class="nfse-cal__count">' . ($cell['future'] ? '' : self::h((string) $cell['count'])) . '</span>';
            $class = 'nfse-cal__cell nfse-heat-' . ($cell['future'] ? 1 : $cell['band']);
            if ($cell['future']) {
                $html .= '<span class="' . $class . ' nfse-cal__cell--future" aria-label="' . self::h(self::dateLabel($cell['date'])) . '"'
                    . ' title="' . self::h($short . ': dia ainda não chegou') . '">' . $inner . '</span>';
                continue;
            }
            $html .= '<button type="submit" class="' . $class . '" name="dia" value="' . self::h($cell['selected'] ? '' : $cell['date']) . '"'
                . ' aria-pressed="' . ($cell['selected'] ? 'true' : 'false') . '" aria-label="' . self::h(self::dateLabel($cell['date']) . ': ' . $counts) . '"'
                . ' title="' . self::h($short . ': ' . $counts) . '">' . $inner . '</button>';
        }
        $html .= '</form>';

        return $html . '</section>';
    }

    /**
     * Média por dia da semana em colunas verticais (valor, barra e nome).
     *
     * @param array<int, array<string, mixed>> $series
     */
    private static function weekdays(array $series, string $today): string
    {
        $html = '<section class="nfse-card nfse-dcard" aria-labelledby="nfse-media-semana">';
        $html .= '<div class="nfse-dcard__head"><h2 class="nfse-dcard__title" id="nfse-media-semana">Média por dia da semana</h2>';
        $html .= '<span class="nfse-dcard__summary">prevê a carga da fila</span></div>';
        $html .= '<div class="nfse-vbars">';
        foreach (DashboardCalendar::weekdayAverages($series, $today) as $row) {
            $value = $row['average'] === null ? '—' : number_format($row['average'], 1, ',', '.');
            $html .= '<div class="nfse-vbar" title="' . self::h($row['name'] . ': ' . ($row['average'] === null ? 'sem dias no período' : $value . ' por dia')) . '">';
            $html .= '<span class="nfse-vbar__value">' . self::h($value) . '</span>';
            $html .= '<span class="nfse-vbar__track"><span class="nfse-vbar__fill' . ($row['weekend'] ? ' nfse-vbar__fill--weekend' : '') . '"'
                . ' style="height:' . self::h(self::pct($row['percent'])) . '"></span></span>';
            $html .= '<span class="nfse-vbar__name">' . self::h($row['name']) . '</span></div>';
        }

        return $html . '</div></section>';
    }

    /**
     * Linha do tempo: as três séries num gráfico só, na mesma escala (DashboardStrips::build).
     *
     * @param array<int, array<string, mixed>> $series
     */
    private static function timeline(array $series, string $today): string
    {
        $data = DashboardStrips::build($series, $today);
        $totals = $data['totals'];

        $html = '<section class="nfse-card nfse-dcard nfse-dcard--timeline" aria-labelledby="nfse-linha-tempo">';
        $html .= '<div class="nfse-dcard__head"><h2 class="nfse-dcard__title" id="nfse-linha-tempo">Linha do tempo</h2>';
        $html .= '<span class="nfse-dcard__summary">mesma escala</span>';
        $html .= '<span class="nfse-tl__legend">'
            . '<span class="nfse-tl__key"><span class="nfse-tl__swatch nfse-tl__swatch--emitidas"></span>Emitidas <b>' . self::h(self::number($totals['emitidas'])) . '</b></span>'
            . '<span class="nfse-tl__key"><span class="nfse-tl__swatch nfse-tl__swatch--cancelada"></span>Canceladas <b>' . self::h(self::number($totals['canceladas'])) . '</b></span>'
            . '<span class="nfse-tl__key"><span class="nfse-tl__swatch nfse-tl__swatch--erro"></span>Erros <b>' . self::h(self::number($totals['erros'])) . '</b></span>'
            . '</span></div>';

        if ($data['days'] === []) {
            return $html . '<div class="nfse-dcard__summary">Sem dias no período.</div></section>';
        }

        $scale = $data['scale'];
        $half = $scale / 2;
        $halfLabel = fmod((float) $scale, 2.0) === 0.0 ? self::number((int) $half) : number_format($half, 1, ',', '.');
        $aria = 'Linha do tempo, mesma escala até ' . self::number($scale) . ': '
            . self::plural($totals['emitidas'], 'emitida', 'emitidas') . ', '
            . self::plural($totals['canceladas'], 'cancelada', 'canceladas') . ' e '
            . self::plural($totals['erros'], 'erro', 'erros') . ' (incl. resolvidos) no período';

        $html .= '<div class="nfse-scroll-x"><div class="nfse-tl' . ($data['long'] ? ' nfse-tl--long' : '') . '">';
        $html .= '<div class="nfse-tl__y" aria-hidden="true"><span class="nfse-tl__tick nfse-tl__tick--max">' . self::h(self::number($scale)) . '</span>'
            . '<span class="nfse-tl__tick nfse-tl__tick--half">' . self::h($halfLabel) . '</span>'
            . '<span class="nfse-tl__tick nfse-tl__tick--zero">0</span></div>';
        $html .= '<div class="nfse-tl__plot" role="img" aria-label="' . self::h($aria) . '">';
        $html .= '<span class="nfse-tl__grid nfse-tl__grid--max"></span><span class="nfse-tl__grid nfse-tl__grid--half"></span><span class="nfse-tl__grid nfse-tl__grid--zero"></span>';
        $html .= '<div class="nfse-tl__days">';
        foreach ($data['days'] as $day) {
            $class = 'nfse-tl__day' . ($day['weekend'] ? ' nfse-tl__day--weekend' : '') . ($day['future'] ? ' nfse-tl__day--future' : '')
                . ($day['today'] ? ' nfse-tl__day--today' : '');
            if ($day['future']) {
                $html .= '<div class="' . $class . '" title="' . self::h($day['label'] . ': dia ainda não chegou') . '"><span class="nfse-tl__placeholder"></span></div>';
                continue;
            }
            $title = $day['label'] . ': ' . self::plural($day['emitidas'], 'emitida', 'emitidas') . ' · '
                . self::plural($day['canceladas'], 'cancelada', 'canceladas') . ' · '
                . self::plural($day['erros'], 'erro', 'erros') . ' (incl. resolvidos)';
            $html .= '<div class="' . $class . '" title="' . self::h($title) . '">';
            if ($day['emitidas'] > 0) {
                $html .= '<span class="nfse-tl__bar nfse-tl__bar--emitidas" style="height:' . self::h(self::pct($day['pct_emitidas'])) . '"></span>';
            }
            if ($day['canceladas'] > 0 || $day['erros'] > 0) {
                $html .= '<span class="nfse-tl__minor">';
                if ($day['canceladas'] > 0) {
                    $html .= '<span class="nfse-tl__bar nfse-tl__bar--cancelada" style="height:' . self::h(self::pct($day['pct_canceladas'])) . '"></span>';
                }
                if ($day['erros'] > 0) {
                    $html .= '<span class="nfse-tl__bar nfse-tl__bar--erro" style="height:' . self::h(self::pct($day['pct_erros'])) . '"></span>';
                }
                $html .= '</span>';
            }
            $html .= '</div>';
        }
        $html .= '</div></div>';
        $html .= '<span></span><div class="nfse-tl__axis" aria-hidden="true">';
        foreach ($data['days'] as $day) {
            if ($day['today']) {
                // Hoje sempre tem a data no eixo, mesmo quando o rótulo do dia cairia fora da sequência.
                $axis = $day['axis'] !== '' ? $day['axis'] : substr($day['label'], 0, 2);
                $html .= '<span class="nfse-tl__axis-today">' . self::h($axis) . '</span>';
                continue;
            }
            $html .= '<span' . ($day['future'] ? ' class="nfse-tl__axis-future"' : '') . '>' . self::h($day['axis']) . '</span>';
        }
        $html .= '</div>';

        return $html . '</div></div></section>';
    }

    /**
     * Legenda abaixo da linha de cards: faixas de calor, marcas do calendário e a dica do clique.
     */
    private static function daysLegend(): string
    {
        return '<div class="nfse-days__legend">'
            . '<span class="nfse-heat-scale" aria-hidden="true">menos <span class="nfse-heat-1"></span><span class="nfse-heat-2"></span><span class="nfse-heat-3"></span><span class="nfse-heat-4"></span><span class="nfse-heat-5"></span> mais</span>'
            . '<span class="nfse-legend__item"><span class="nfse-cal__dot nfse-cal__dot--cancelada"></span>Canceladas</span>'
            . '<span class="nfse-legend__item"><span class="nfse-cal__dot nfse-cal__dot--erro"></span>Erros (incl. resolvidos)</span>'
            . '<span>Clique num dia para filtrar a atividade recente</span>'
            . '</div>';
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
