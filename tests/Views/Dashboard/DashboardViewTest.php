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
            'logo_url' => 'https://whmcs.example/modules/addons/OpenNfse/assets/opennsfe_logo.png',
            'version' => ['current' => '0.1.26', 'latest' => '0.1.27', 'update_available' => true, 'href' => 'addonmodules.php?module=OpenNfse&action=config&tab=processamento'],
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

        $this->assertStringStartsWith('<div class="nfse-ui nfse-page">', $html);
        $this->assertStringContainsString('class="nfse-logo"', $html);
        $this->assertStringContainsString('v0.1.26 → 0.1.27 disponível', $html);
        $this->assertStringContainsString('>Outubro 2026<', $html);
        $this->assertStringContainsString('Operação em dia', $html);
        $this->assertStringContainsString('R$ 58.700,87', $html);
        $this->assertStringContainsString('nfse-kpi__value nfse-kpi__value--ok">100,0%', $html);
        $this->assertStringContainsString('119 no período · média 3,8/dia · pico 29 em 23/10', $html);
        $this->assertSame(4, substr_count($html, 'nfse-cal__cell nfse-cal__cell--empty'));
        $this->assertSame(1, substr_count($html, 'aria-pressed="true" aria-label="23/10/2026'));
        $this->assertStringContainsString('<span class="nfse-mark">4</span>', $html);
        $this->assertSame(3, substr_count($html, 'class="nfse-strip__label"'));
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

    public function testNeutralVersionAndZeroKpis(): void
    {
        $data = self::data([
            'version' => ['current' => '0.1.27', 'latest' => '0.1.27', 'update_available' => false],
            'metrics' => ['emitidas' => 0],
            'series' => [],
            'feed_items' => [],
            'dia' => '',
        ]);
        $html = DashboardView::render($data);

        $this->assertStringContainsString('<span class="nfse-badge nfse-badge--version">v0.1.27</span>', $html);
        $this->assertStringNotContainsString('disponível', $html);
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

    public function testCertificateInHeaderAndHealth(): void
    {
        $html = DashboardView::render(self::data());
        $this->assertStringContainsString('<span class="nfse-header__cert">· Certificado válido até 20/05/2027</span>', $html);

        $html = DashboardView::render(self::data(['certificate' => ['status' => 'missing', 'days_left' => '', 'valid_to' => '']]));
        $this->assertStringNotContainsString('nfse-header__cert', $html);
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

    public function testFutureDaysInStrips(): void
    {
        $series = [];
        for ($i = 1; $i <= 30; $i++) {
            $series[] = ['date' => sprintf('2026-11-%02d', $i), 'emitidas' => $i <= 5 ? 2 : 0, 'canceladas' => 0, 'erros_resolvidos' => 0];
        }
        $html = DashboardView::render(self::data([
            'period' => ['periodo' => 'mes_atual', 'start' => '2026-11-01', 'end' => '2026-11-30'],
            'series' => $series,
            'dia' => '',
        ]));

        // 25 dias futuros (06 a 30/11) em cada uma das 3 faixas, sem barra.
        $this->assertSame(75, substr_count($html, 'nfse-strip__bar nfse-strip__bar--future"'));
        $this->assertSame(25, substr_count($html, 'class="nfse-strip__day--future"'));
        $this->assertStringContainsString('<span class="nfse-strip__label">Erros (incl. resolvidos)</span>', $html);
        $this->assertStringContainsString('Erros (incl. resolvidos) no dia', $html);
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

    public function testQueueCardSitsBetweenHealthAndKpis(): void
    {
        $html = DashboardView::render(self::withQueue(['PENDING' => 3, 'ERROR' => 1], '2026-11-05 09:58:00'));

        $health = strpos($html, 'class="nfse-health"');
        $queue = strpos($html, '<h2 class="nfse-card__title" id="nfse-fila-agora">Fila agora</h2>');
        $kpis = strpos($html, '<div class="nfse-kpis">');
        $this->assertNotFalse($queue);
        $this->assertTrue($health < $queue && $queue < $kpis);
        $this->assertStringContainsString('Estado atual, fora do período · <span>Cron executado há 2 min (WHMCS)</span>', $html);
        $this->assertStringNotContainsString('Cron parado', $html);
    }

    public function testQueueCardValuesAndLinks(): void
    {
        $html = DashboardView::render(self::withQueue(['PENDING' => 3, 'ERROR' => 1], '2026-11-05 09:58:00'));
        $all = '&amp;updated_from=2000-01-01&amp;updated_to=2026-11-05';

        $this->assertStringContainsString(
            '<a class="nfse-kpi nfse-kpi--link" href="addonmodules.php?module=OpenNfse&amp;action=fila&amp;status=PENDING' . $all . '">'
            . '<div class="nfse-kpi__label">Pendentes</div><div class="nfse-kpi__value">3</div></a>',
            $html
        );
        $this->assertStringContainsString('status=RUNNING' . $all . '"><div class="nfse-kpi__label">Processando</div><div class="nfse-kpi__value nfse-kpi__value--zero">0</div>', $html);
        $this->assertStringContainsString('status=WAIT_STATUS' . $all . '"><div class="nfse-kpi__label">Aguardando status</div><div class="nfse-kpi__value nfse-kpi__value--zero">0</div>', $html);
        $this->assertStringContainsString('status=ERROR' . $all . '"><div class="nfse-kpi__label">Com erro</div><div class="nfse-kpi__value nfse-kpi__value--danger">1</div>', $html);
        $this->assertStringContainsString('<div class="nfse-kpi"><div class="nfse-kpi__label">Pendente mais antigo</div><div class="nfse-kpi__value">há 12 min</div></div>', $html);
        $this->assertStringContainsString('<a class="nfse-btn" href="addonmodules.php?module=OpenNfse&amp;action=fila">Abrir fila</a>', $html);
    }

    public function testQueueCardZero(): void
    {
        $html = DashboardView::render(self::withQueue([], '2026-11-05 09:58:00'));

        $this->assertSame(4, substr_count($html, 'nfse-kpi__value nfse-kpi__value--zero">0<'));
        $this->assertStringContainsString('Pendente mais antigo</div><div class="nfse-kpi__value nfse-kpi__value--zero">—</div>', $html);
        $this->assertStringNotContainsString('nfse-kpi__value--danger', $html);
    }

    public function testProcessButtonPostsToFilaProcessNowWithToken(): void
    {
        $html = DashboardView::render(self::withQueue([], '2026-11-05 09:58:00'));

        $this->assertStringContainsString(
            '<form class="nfse-queue__form" method="post" action="addonmodules.php?module=OpenNfse&amp;action=filaProcessNow">'
            . '<input type="hidden" name="token" value="tok&quot;&lt;en&gt;">'
            . '<button class="nfse-btn nfse-btn--primary" type="submit">Processar fila agora</button></form>',
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
        $this->assertStringContainsString('<span class="nfse-text-danger">Cron executado há 30 min (WHMCS)</span>', $html);

        $html = DashboardView::render(self::withQueue([], ''));
        $this->assertStringContainsString('<div class="nfse-health__title">Cron nunca executado</div>', $html);
    }

    public function testQueueCardEscapesCronSource(): void
    {
        $data = self::withQueue([], '2026-11-05 09:58:00');
        $data['cron']['source'] = '<b>x</b>';
        $html = DashboardView::render($data);

        $this->assertStringContainsString('Cron executado há 2 min (&lt;b&gt;x&lt;/b&gt;)', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function testWithoutQueueDataThereIsNoCard(): void
    {
        $html = DashboardView::render(self::data());

        $this->assertStringNotContainsString('Fila agora', $html);
        $this->assertStringNotContainsString('Cron', $html);
    }

    public function testNextArrowDisabledForFutureMonth(): void
    {
        $html = DashboardView::render(self::data(['period' => ['periodo' => 'mes_atual', 'start' => '2026-11-01', 'end' => '2026-11-30'], 'dia' => '']));

        $this->assertStringContainsString('aria-disabled="true" aria-label="Próximo mês"', $html);
        $this->assertStringContainsString('data_inicial=2026-10-01&amp;data_final=2026-10-31" aria-label="Mês anterior"', $html);
    }
}
