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

    public function testNextArrowDisabledForFutureMonth(): void
    {
        $html = DashboardView::render(self::data(['period' => ['periodo' => 'mes_atual', 'start' => '2026-11-01', 'end' => '2026-11-30'], 'dia' => '']));

        $this->assertStringContainsString('aria-disabled="true" aria-label="Próximo mês"', $html);
        $this->assertStringContainsString('data_inicial=2026-10-01&amp;data_final=2026-10-31" aria-label="Mês anterior"', $html);
    }
}
