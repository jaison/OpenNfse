<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Views\Dashboard;

use OpenNfse\Views\Dashboard\DashboardView;
use PHPUnit\Framework\TestCase;

final class DashboardViewTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function data(array $overrides = []): array
    {
        $series = [];
        $d = new \DateTimeImmutable('2026-10-01');
        for ($i = 0; $i < 31; $i++) {
            $series[] = ['date' => $d->format('Y-m-d'), 'label' => $d->format('d/m'), 'emitidas' => $i === 22 ? 29 : 3, 'canceladas' => $i === 7 ? 4 : 0, 'erros_resolvidos' => 0];
            $d = $d->modify('+1 day');
        }

        return $overrides + [
            'today' => '2026-11-05',
            'period' => ['periodo' => 'personalizado', 'start' => '2026-10-01', 'end' => '2026-10-31'],
            'metrics' => [
                'emitidas' => 119, 'canceladas' => 4, 'rejeitadas' => 0, 'pendentes' => 0, 'aguardando_status' => 0,
                'com_erro_periodo' => 0, 'erros_resolvidos_periodo' => 0, 'movimentadas' => 123, 'xmls' => 119,
                'valor_total' => 58700.87, 'taxa_sucesso' => 100.0,
            ],
            'series' => $series,
            'dia' => '2026-10-23',
            'feed_filter' => 'tudo',
            'feed_items' => [
                ['type' => 'emitida', 'data' => '2026-10-23 23:10:00', 'invoiceid' => 151836, 'title' => 'NFS-e 1867 emitida', 'client' => '[Nome do cliente] <script>'],
            ],
            'nao_emitidas' => 0,
            'alertas_abertos' => 0,
            'certificate' => ['status' => 'ok', 'days_left' => 200, 'valid_to' => '20/05/2027 10:00'],
            'customfield_missing' => false,
        ];
    }

    public function testRendersComponents(): void
    {
        $html = DashboardView::render(self::data());

        // Sem linha só com o período: a página abre com a barra de saúde, e o título fica na barra de navegação.
        $this->assertStringStartsWith('<div class="nfse-ui nfse-page"><div class="nfse-health"', $html);
        $this->assertStringNotContainsString('nfse-toolbar', $html);
        $this->assertStringNotContainsString('<h1', $html, 'o h1 da página fica na barra');
        $this->assertStringNotContainsString('nfse-titlerow', $html);
        $this->assertStringNotContainsString('nfse-navbar', $html);
        $this->assertStringNotContainsString('nfse-badge--version', $html);
        $this->assertStringNotContainsString('opennsfe_logo', $html);
        $this->assertStringContainsString('<span class="nfse-periodnav__label">Outubro 2026</span>', $html);
        $this->assertStringContainsString('Operação em dia', $html);
        $this->assertStringContainsString('R$ 58.700,87', $html);
        $this->assertStringContainsString('nfse-kpi__value nfse-kpi__value--ok">100,0%', $html);
        $this->assertStringContainsString('119 no período · média 3,8/dia · pico 29 em 23/10', $html);
        $this->assertSame(4, substr_count($html, 'nfse-cal__cell nfse-cal__cell--empty'));
        $this->assertSame(1, substr_count($html, 'aria-pressed="true" aria-label="23/10/2026'));
        $this->assertStringContainsString('<span class="nfse-cal__marks" aria-hidden="true"><span class="nfse-cal__dot nfse-cal__dot--cancelada"></span></span>', $html);
        $this->assertStringContainsString('aria-label="08/10/2026: 3 emitidas, 4 canceladas" title="08/10: 3 emitidas, 4 canceladas"', $html);
        $this->assertStringContainsString('<div class="nfse-tl__plot" role="img"', $html);
        $this->assertStringContainsString('Atividade recente <span class="nfse-card__summary">em 23/10/2026</span>', $html);
        $this->assertStringContainsString('name="dia" value="2026-10-23"', $html, 'o filtro de atividade mantém o dia');
        $this->assertStringContainsString('tab=emitidas&amp;data_inicial=2026-10-23&amp;data_final=2026-10-23', $html);
    }

    public function testEscapesValues(): void
    {
        $html = DashboardView::render(self::data());

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('[Nome do cliente] &lt;script&gt;', $html);
    }

    public function testZeroKpis(): void
    {
        $data = self::data([
            'metrics' => ['emitidas' => 0],
            'series' => [],
            'feed_items' => [],
            'dia' => '',
        ]);
        $html = DashboardView::render($data);

        $this->assertStringContainsString('nfse-kpi__value nfse-kpi__value--zero">0<', $html);
        $this->assertStringContainsString('nfse-kpi__value nfse-kpi__value--zero">—<', $html);
        $this->assertStringContainsString('Nenhuma atividade no período.', $html);
    }

    public function testHealthLinksPerCounter(): void
    {
        $data = self::data(['metrics' => [
            'com_erro_periodo' => 3, 'rejeitadas' => 1, 'pendentes' => 4, 'aguardando_status' => 2,
        ] + self::data()['metrics']]);
        $html = DashboardView::render($data);

        $range = 'data_inicial=2026-10-01&amp;data_final=2026-10-31';
        $fila = 'updated_from=2026-10-01&amp;updated_to=2026-10-31';
        $this->assertStringContainsString('<a href="addonmodules.php?module=OpenNfse&amp;action=relatorios&amp;tab=falhas&amp;' . $range . '">2 erros</a>', $html);
        $this->assertStringContainsString('<a href="addonmodules.php?module=OpenNfse&amp;action=relatorios&amp;tab=emitidas&amp;' . $range . '&amp;status=REJEITADA">1 rejeitada</a>', $html);
        $this->assertStringContainsString('<a href="addonmodules.php?module=OpenNfse&amp;action=fila&amp;' . $fila . '">2 pendentes</a>', $html);
        $this->assertStringContainsString('<a href="addonmodules.php?module=OpenNfse&amp;action=fila&amp;status=WAIT_STATUS&amp;' . $fila . '">2 aguardando status</a>', $html);
        $this->assertStringNotContainsString('Operação em dia', $html);
    }

    public function testTitlePartsAndCertificateHealth(): void
    {
        $period = ['start' => '2026-10-01', 'end' => '2026-10-31'];
        $this->assertSame(
            ['subtitle' => 'Operação fiscal de Outubro 2026', 'detail' => 'Certificado válido até 20/05/2027'],
            DashboardView::titleParts($period, ['status' => 'ok', 'days_left' => 200, 'valid_to' => '20/05/2027 10:00'])
        );
        $this->assertSame(
            ['subtitle' => 'Operação fiscal de 30/10 – 05/11/2026', 'detail' => ''],
            DashboardView::titleParts(['start' => '2026-10-30', 'end' => '2026-11-05'], ['status' => 'missing', 'days_left' => ''])
        );
        $this->assertStringNotContainsString('Certificado válido até', DashboardView::render(self::data()), 'a validade vai só na dica do título, na barra');

        $html = DashboardView::render(self::data(['certificate' => ['status' => 'missing', 'days_left' => '', 'valid_to' => '']]));
        $this->assertStringContainsString('Certificado não configurado', $html);
        $this->assertStringContainsString('href="addonmodules.php?module=OpenNfse&amp;action=config&amp;tab=ambiente">Abrir Configurações</a>', $html);
    }

    public function testDayAndFeedKeepEachOther(): void
    {
        $html = DashboardView::render(self::data(['feed_filter' => 'canceladas']));
        $period = '<input type="hidden" name="periodo" value="personalizado"><input type="hidden" name="data_inicial" value="2026-10-01"><input type="hidden" name="data_final" value="2026-10-31">';

        // Formulário do calendário: período e filtro do feed.
        $this->assertStringContainsString('<input type="hidden" name="action" value="dashboard">' . $period . '<input type="hidden" name="feed" value="canceladas">', $html);
        // Formulário dos chips: período e dia.
        $this->assertStringContainsString($period . '<input type="hidden" name="dia" value="2026-10-23">', $html);
        // O dia escolhido desmarca ao clicar de novo; os outros marcam.
        $this->assertStringContainsString('name="dia" value="" aria-pressed="true" aria-label="23/10/2026', $html);
        $this->assertStringContainsString('name="dia" value="2026-10-22" aria-pressed="false"', $html);
        $this->assertStringContainsString('name="feed" value="canceladas" aria-pressed="true"', $html);
        $this->assertStringContainsString('name="feed" value="tudo" aria-pressed="false"', $html);
        $this->assertStringContainsString('feed=canceladas#nfse-atividade">Todo o período</a>', $html);
        $this->assertStringContainsString('tab=cancelamentos&amp;data_inicial=2026-10-23&amp;data_final=2026-10-23', $html);
    }

    public function testChipCountsFollowSelectedDay(): void
    {
        // 23/10 tem 29 emitidas; 08/10 tem 4 canceladas. O mês tem 119 emitidas e 4 canceladas.
        $html = DashboardView::render(self::data());
        $this->assertStringContainsString('>Tudo<span class="nfse-chip__count">29</span>', $html);
        $this->assertStringContainsString('>Emitidas<span class="nfse-chip__count">29</span>', $html);
        $this->assertStringContainsString('>Canceladas<span class="nfse-chip__count">0</span>', $html);

        $html = DashboardView::render(self::data(['dia' => '']));
        $this->assertStringContainsString('>Tudo<span class="nfse-chip__count">123</span>', $html);
        $this->assertStringContainsString('>Canceladas<span class="nfse-chip__count">4</span>', $html);
    }

    public function testDaysRowHasThreeCompactCards(): void
    {
        $html = DashboardView::render(self::data());

        $row = strpos($html, '<div class="nfse-days"><section class="nfse-card nfse-dcard" aria-labelledby="nfse-emissoes-dia">');
        $weekday = strpos($html, '<section class="nfse-card nfse-dcard" aria-labelledby="nfse-media-semana">');
        $timeline = strpos($html, '<section class="nfse-card nfse-dcard nfse-dcard--timeline" aria-labelledby="nfse-linha-tempo">');
        $legend = strpos($html, '<div class="nfse-days__legend">');
        $feed = strpos($html, 'id="nfse-atividade"');
        $this->assertNotFalse($row);
        $this->assertTrue($row < $weekday && $weekday < $timeline && $timeline < $legend && $legend < $feed);
        $this->assertStringNotContainsString('nfse-split', $html);
        $this->assertStringNotContainsString('nfse-strip', $html, 'as faixas separadas deram lugar ao gráfico sobreposto');

        // Calendário: iniciais no cabeçalho, com o nome no title.
        $this->assertStringContainsString('<span class="nfse-cal__dow" title="Dom">D</span><span class="nfse-cal__dow" title="Seg">S</span>', $html);
        $this->assertStringContainsString('<span class="nfse-dcard__summary">119 no período · média 3,8/dia · pico 29 em 23/10</span>', $html);

        // Média por dia da semana em colunas (altura da barra vem do dado).
        $this->assertSame(7, substr_count($html, '<div class="nfse-vbar" title='));
        $this->assertStringContainsString('<span class="nfse-vbar__fill nfse-vbar__fill--weekend" style="height:36.6%"></span>', $html);
        $this->assertStringContainsString('<span class="nfse-vbar__name">Sex</span>', $html);

        // Legenda abaixo da linha.
        $this->assertStringContainsString('<span class="nfse-legend__item"><span class="nfse-cal__dot nfse-cal__dot--erro"></span>Erros (incl. resolvidos)</span><span>Clique num dia para filtrar a atividade recente</span>', $html);
    }

    public function testOverlaidTimeline(): void
    {
        $series = [];
        for ($i = 1; $i <= 30; $i++) {
            $series[] = ['date' => sprintf('2026-11-%02d', $i), 'emitidas' => $i <= 5 ? 12 : 0, 'canceladas' => $i === 5 ? 2 : 0, 'erros_resolvidos' => $i === 5 ? 1 : 0];
        }
        $html = DashboardView::render(self::data([
            'period' => ['periodo' => 'mes_atual', 'start' => '2026-11-01', 'end' => '2026-11-30'],
            'series' => $series,
            'dia' => '',
        ]));

        // Uma escala só (12 → 15), com as marcas 0, metade e máximo.
        $this->assertStringContainsString(
            '<div class="nfse-tl__y" aria-hidden="true"><span class="nfse-tl__tick nfse-tl__tick--max">15</span>'
            . '<span class="nfse-tl__tick nfse-tl__tick--half">7,5</span><span class="nfse-tl__tick nfse-tl__tick--zero">0</span></div>',
            $html
        );
        $this->assertStringContainsString(
            'role="img" aria-label="Linha do tempo, mesma escala até 15: 60 emitidas, 2 canceladas e 1 erro (incl. resolvidos) no período"',
            $html
        );
        $this->assertStringContainsString(
            'Emitidas <b>60</b></span><span class="nfse-tl__key"><span class="nfse-tl__swatch nfse-tl__swatch--cancelada"></span>Canceladas <b>2</b></span>'
            . '<span class="nfse-tl__key"><span class="nfse-tl__swatch nfse-tl__swatch--erro"></span>Erros <b>1</b></span>',
            $html
        );

        // Hoje (05/11): barra larga de emitidas e, na frente, as duas finas, na mesma escala.
        $this->assertStringContainsString(
            '<div class="nfse-tl__day nfse-tl__day--today" title="05/11: 12 emitidas · 2 canceladas · 1 erro (incl. resolvidos)">'
            . '<span class="nfse-tl__bar nfse-tl__bar--emitidas" style="height:80%"></span>'
            . '<span class="nfse-tl__minor"><span class="nfse-tl__bar nfse-tl__bar--cancelada" style="height:13.3%"></span>'
            . '<span class="nfse-tl__bar nfse-tl__bar--erro" style="height:6.7%"></span></span></div>',
            $html
        );
        // Fim de semana mais claro; dia sem canceladas nem erros não tem barras finas.
        $this->assertStringContainsString(
            '<div class="nfse-tl__day nfse-tl__day--weekend" title="01/11: 12 emitidas · 0 canceladas · 0 erros (incl. resolvidos)">'
            . '<span class="nfse-tl__bar nfse-tl__bar--emitidas" style="height:80%"></span></div>',
            $html
        );
        // 25 dias futuros (06 a 30/11): só o marcador plano, com a data esmaecida no eixo.
        $this->assertSame(25, substr_count($html, '<span class="nfse-tl__placeholder"></span>'));
        $this->assertSame(25, substr_count($html, 'class="nfse-tl__axis-future"'));
        // Hoje: só a classe da coluna (fundo leve no CSS) e a data em negrito no eixo, uma vez.
        $this->assertSame(1, substr_count($html, 'nfse-tl__day--today'));
        $this->assertSame(1, substr_count($html, 'class="nfse-tl__axis-today"'));
        $this->assertStringContainsString('<span class="nfse-tl__axis-today">05</span>', $html);

        // Hoje fora da sequência de rótulos do eixo (06/11 num mês de 30 dias): a data aparece mesmo assim.
        $html = DashboardView::render(self::data([
            'today' => '2026-11-06',
            'period' => ['periodo' => 'mes_atual', 'start' => '2026-11-01', 'end' => '2026-11-30'],
            'series' => $series,
            'dia' => '',
        ]));
        $this->assertStringContainsString('<span class="nfse-tl__axis-today">06</span>', $html);
        $this->assertStringNotContainsString('nfse-tl--long', $html);
    }

    public function testLongPeriodKeepsHorizontalScroll(): void
    {
        $series = [];
        $d = new \DateTimeImmutable('2026-08-08');
        for ($i = 0; $i < 90; $i++) {
            $series[] = ['date' => $d->format('Y-m-d'), 'emitidas' => 3, 'canceladas' => 0, 'erros_resolvidos' => 0];
            $d = $d->modify('+1 day');
        }
        $html = DashboardView::render(self::data([
            'period' => ['periodo' => 'personalizado', 'start' => '2026-08-08', 'end' => '2026-11-05'],
            'series' => $series,
            'dia' => '',
        ]));

        $this->assertStringContainsString('<div class="nfse-scroll-x"><div class="nfse-tl nfse-tl--long">', $html);
        $this->assertSame(90, preg_match_all('/<div class="nfse-tl__day[ "]/', $html));
    }

    /**
     * @return array<string, mixed>
     */
    private static function withQueue(array $queueCounts, string $cronAt, array $overrides = []): array
    {
        return self::data($overrides + [
            'now' => (int) strtotime('2026-11-05 10:00:00'),
            'queue' => ['counts' => $queueCounts, 'oldest_pending' => '2026-11-05 09:48:00'],
            'cron' => ['last_run_at' => $cronAt, 'source' => 'whmcs_cron'],
            'token' => 'tok"<en>',
        ]);
    }

    public function testSingleBarHoldsPeriodAndQueueBelowHealth(): void
    {
        $html = DashboardView::render(self::withQueue(['PENDING' => 3, 'ERROR' => 1], '2026-11-05 09:58:00'));

        $health = strpos($html, 'class="nfse-health"');
        $bar = strpos($html, '<div class="nfse-bar"><section class="nfse-bar__period" aria-labelledby="nfse-no-periodo">');
        $controls = strpos($html, '<div class="nfse-periodnav" role="group" aria-label="Período da Dashboard">');
        $queue = strpos($html, '<section class="nfse-bar__queue" aria-labelledby="nfse-fila-agora">');
        $calendar = strpos($html, 'Emissões por dia');
        $this->assertNotFalse($bar, 'um card só, com o período primeiro');
        $this->assertNotFalse($queue);
        $this->assertTrue($health < $bar && $bar < $controls && $controls < $queue && $queue < $calendar);
        $this->assertSame(1, substr_count($html, 'class="nfse-periodnav"'), 'as ferramentas do período ficam só no cabeçalho da barra');

        // Cabeçalho do lado do período: "No período", setas, rótulo e os chips, depois os indicadores.
        $prev = 'addonmodules.php?module=OpenNfse&amp;action=dashboard&amp;periodo=personalizado&amp;data_inicial=2026-09-01&amp;data_final=2026-09-30';
        $next = 'addonmodules.php?module=OpenNfse&amp;action=dashboard&amp;periodo=personalizado&amp;data_inicial=2026-11-01&amp;data_final=2026-11-30';
        $this->assertStringContainsString(
            '<div class="nfse-bar__head"><span class="nfse-bar__head-title" id="nfse-no-periodo">No período</span>'
            . '<div class="nfse-periodnav" role="group" aria-label="Período da Dashboard">'
            . '<a class="nfse-btn nfse-btn--icon nfse-btn--xs" href="' . $prev . '" aria-label="Mês anterior">',
            $html
        );
        $this->assertStringContainsString(
            '<span class="nfse-periodnav__label">Outubro 2026</span>'
            . '<a class="nfse-btn nfse-btn--icon nfse-btn--xs" href="' . $next . '" aria-label="Próximo mês">',
            $html
        );
        $this->assertStringContainsString(
            '<nav class="nfse-chips nfse-chips--sm" aria-label="Período">'
            . '<a class="nfse-chip" href="addonmodules.php?module=OpenNfse&amp;action=dashboard&amp;periodo=hoje">Hoje</a>'
            . '<a class="nfse-chip" href="addonmodules.php?module=OpenNfse&amp;action=dashboard&amp;periodo=7_dias">7 dias</a>'
            . '<details class="nfse-custom"><summary class="nfse-chip">Personalizado</summary>'
            . '<form class="nfse-custom__panel" method="get" action="addonmodules.php">',
            $html
        );
        $this->assertStringContainsString('</form></details></nav></div></div><div class="nfse-bar__kpis"><div class="nfse-kpi"><div class="nfse-kpi__label">Emitidas</div>', $html);
        $this->assertStringContainsString('<div class="nfse-kpi__value nfse-kpi__value--ok">100,0%</div></div><div class="nfse-kpi"><div class="nfse-kpi__label">Processadas', $html);
        $this->assertStringContainsString('</section></div><div class="nfse-days">', $html, 'a barra fecha logo depois da fila');
        $this->assertStringNotContainsString('<div class="nfse-kpis">', $html);
        $this->assertStringNotContainsString('Cron parado', $html);
    }

    public function testQueueHeaderHasOnlyDotTitleAndButton(): void
    {
        $html = DashboardView::render(self::withQueue(['PENDING' => 3], '2026-11-05 09:58:00'));

        $this->assertStringContainsString(
            '<div class="nfse-queue__head">'
            . '<span class="nfse-dot nfse-dot--ok" aria-hidden="true"></span>'
            . '<h2 class="nfse-queue__title" id="nfse-fila-agora" title="Estado atual, fora do período">Fila agora</h2>'
            . '<form class="nfse-queue__form" method="post"',
            $html
        );
        $this->assertStringContainsString('Processar agora</button></form></div><div class="nfse-queue__grid">', $html, 'o cron não fica mais no cabeçalho');
    }

    public function testQueueCardValuesAndLinks(): void
    {
        $html = DashboardView::render(self::withQueue(['PENDING' => 3, 'ERROR' => 1], '2026-11-05 09:58:00'));
        $fila = 'addonmodules.php?module=OpenNfse&amp;action=fila&amp;status=';
        $all = '&amp;updated_from=2000-01-01&amp;updated_to=2026-11-05';

        $this->assertStringContainsString(
            '<div class="nfse-queue__grid">'
            . '<a class="nfse-queue__row" href="' . $fila . 'PENDING' . $all . '"><span>Pendentes</span><span class="nfse-queue__n">3</span></a>'
            . '<a class="nfse-queue__row nfse-queue__row--zero" href="' . $fila . 'RUNNING' . $all . '"><span>Processando</span><span class="nfse-queue__n">0</span></a>'
            . '<a class="nfse-queue__row nfse-queue__row--zero" href="' . $fila . 'WAIT_STATUS' . $all . '" title="Aguardando status"><span>Aguard. status</span><span class="nfse-queue__n">0</span></a>'
            . '<a class="nfse-queue__row nfse-queue__row--danger" href="' . $fila . 'ERROR' . $all . '"><span>Com erro</span><span class="nfse-queue__n">1</span></a>'
            . '</div>',
            $html
        );
        $this->assertStringContainsString(
            '<div class="nfse-queue__foot"><span class="nfse-queue__meta">'
            . '<span class="nfse-queue__cron" title="Cron executado há 2 min (WHMCS)">Cron há 2 min</span> · mais antigo há 12 min</span>'
            . '<a href="addonmodules.php?module=OpenNfse&amp;action=fila">Abrir fila</a></div>',
            $html
        );
    }

    public function testQueueCardZero(): void
    {
        $html = DashboardView::render(self::withQueue([], '2026-11-05 09:58:00'));

        $this->assertSame(4, substr_count($html, 'class="nfse-queue__row nfse-queue__row--zero"'));
        $this->assertStringNotContainsString('nfse-queue__row--danger', $html);
        $this->assertStringContainsString(
            '<div class="nfse-queue__foot"><span class="nfse-queue__meta">'
            . '<span class="nfse-queue__cron" title="Cron executado há 2 min (WHMCS)">Cron há 2 min</span></span>'
            . '<a href="addonmodules.php?module=OpenNfse&amp;action=fila">Abrir fila</a></div>',
            $html,
            'sem pendentes, o rodapé mostra só o cron e o link'
        );

        // Sem dados do cron, "Mais antigo" abre o rodapé com maiúscula.
        $data = self::withQueue(['PENDING' => 2], '2026-11-05 09:58:00');
        unset($data['cron']);
        $this->assertStringContainsString(
            '<span class="nfse-queue__meta">Mais antigo há 12 min</span>',
            DashboardView::render($data)
        );
    }

    public function testProcessButtonPostsToFilaProcessNowWithToken(): void
    {
        $html = DashboardView::render(self::withQueue([], '2026-11-05 09:58:00'));

        $this->assertStringContainsString(
            '<form class="nfse-queue__form" method="post" action="addonmodules.php?module=OpenNfse&amp;action=filaProcessNow">'
            . '<input type="hidden" name="token" value="tok&quot;&lt;en&gt;">'
            . '<button class="nfse-btn nfse-btn--sm" type="submit">Processar agora</button></form>',
            $html
        );
        $this->assertStringNotContainsString('tok"<en>', $html, 'o token sai escapado');
    }

    public function testStaleCronAddsCriticalHealthItem(): void
    {
        $html = DashboardView::render(self::withQueue([], '2026-11-05 09:30:00'));

        $this->assertStringContainsString('nfse-health__item nfse-health__item--danger', $html);
        $this->assertStringContainsString('<div class="nfse-health__title">Cron parado há 30 min</div>', $html);
        $this->assertStringContainsString('href="addonmodules.php?module=OpenNfse&amp;action=config&amp;tab=processamento">Ver configuração do cron</a>', $html);
        $this->assertStringContainsString('<span class="nfse-dot nfse-dot--danger" aria-hidden="true"></span>', $html);
        $this->assertStringContainsString('<span class="nfse-queue__cron nfse-text-danger" title="Cron executado há 30 min (WHMCS)">Cron há 30 min</span>', $html);

        $html = DashboardView::render(self::withQueue([], ''));
        $this->assertStringContainsString('<div class="nfse-health__title">Cron nunca executado</div>', $html);
        $this->assertStringContainsString('<span class="nfse-dot nfse-dot--danger" aria-hidden="true"></span>', $html);
        $this->assertStringContainsString('<span class="nfse-queue__cron nfse-text-danger" title="Cron nunca executado">Cron nunca executado</span>', $html);
    }

    public function testQueueCardEscapesCronSource(): void
    {
        $data = self::withQueue([], '2026-11-05 09:58:00');
        $data['cron']['source'] = '<b>x</b>';
        $html = DashboardView::render($data);

        $this->assertStringContainsString('title="Cron executado há 2 min (&lt;b&gt;x&lt;/b&gt;)"', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function testWithoutQueueDataThereIsNoQueuePanel(): void
    {
        $html = DashboardView::render(self::data());

        $this->assertStringNotContainsString('Fila agora', $html);
        $this->assertStringNotContainsString('Cron', $html);
        $this->assertStringNotContainsString('nfse-bar__queue', $html);
        $this->assertStringContainsString(
            '<div class="nfse-bar nfse-bar--solo"><section class="nfse-bar__period" aria-labelledby="nfse-no-periodo">',
            $html,
            'sem a fila, o lado do período ocupa a barra inteira, com as ferramentas do período'
        );
        $this->assertStringContainsString('<div class="nfse-periodnav"', $html);
    }

    public function testNextArrowDisabledForFutureMonth(): void
    {
        $html = DashboardView::render(self::data(['period' => ['periodo' => 'mes_atual', 'start' => '2026-11-01', 'end' => '2026-11-30'], 'dia' => '']));

        $this->assertStringContainsString('aria-disabled="true" aria-label="Próximo mês"', $html);
        $this->assertStringContainsString('data_inicial=2026-10-01&amp;data_final=2026-10-31" aria-label="Mês anterior"', $html);
    }
}
