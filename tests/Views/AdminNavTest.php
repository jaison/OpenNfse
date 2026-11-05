<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Views;

use OpenNfse\Controllers\Support\AdminHelpersTrait;
use OpenNfse\Views\AdminNav;
use OpenNfse\Views\Dashboard\DashboardQueue;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class AdminNavTest extends TestCase
{
    private const NOW = '2026-11-05 10:00:00';

    protected function tearDown(): void
    {
        Capsule::reset();
    }

    /**
     * @return array{ran: bool, seconds: int, stale: bool, text: string, short: string}
     */
    private static function cron(string $lastRun): array
    {
        return DashboardQueue::cronState($lastRun, 'whmcs_cron', (int) strtotime(self::NOW));
    }

    /**
     * @param array<string, int> $counts
     * @return array{counts: array<string, int>, oldest_pending: string}
     */
    private static function snapshot(array $counts): array
    {
        return ['counts' => $counts + ['PENDING' => 0, 'RUNNING' => 0, 'WAIT_STATUS' => 0, 'ERROR' => 0], 'oldest_pending' => ''];
    }

    public function testBarTabsUrlsAndActiveTab(): void
    {
        $html = AdminNav::bar('fila');

        $this->assertStringStartsWith('<div class="nfse-navbar"><div class="nfse-navbar__brand">', $html);
        $this->assertStringContainsString('<nav class="nfse-navbar__tabs" aria-label="Seções do OpenNfse">', $html);
        $this->assertStringContainsString('<a class="nfse-tab" href="addonmodules.php?module=OpenNfse&amp;action=dashboard"><svg', $html);
        $this->assertStringContainsString('<a class="nfse-tab" href="addonmodules.php?module=OpenNfse&amp;action=fila" aria-current="page"><svg', $html);
        $this->assertStringContainsString('<a class="nfse-tab" href="addonmodules.php?module=OpenNfse&amp;action=relatorios&amp;tab=emitidas"><svg', $html);
        $this->assertStringContainsString('<a class="nfse-tab" href="addonmodules.php?module=OpenNfse&amp;action=config"><svg', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        foreach (['Dashboard', 'Fila', 'Relatórios', 'Configurações'] as $label) {
            $this->assertStringContainsString('</svg>' . $label . '</a>', $html);
        }

        // Aba desconhecida (ex.: tela antiga) não marca nenhuma.
        $this->assertStringNotContainsString('aria-current', AdminNav::bar('codigos'));
    }

    public function testIconsAreHiddenFromScreenReaders(): void
    {
        $html = AdminNav::bar('dashboard');

        $this->assertSame(4, substr_count($html, '<svg '));
        preg_match_all('/<svg[^>]*>/', $html, $m);
        foreach ($m[0] as $svg) {
            $this->assertStringContainsString('aria-hidden="true"', $svg);
        }
    }

    public function testBrandLogoQueueAndVersion(): void
    {
        $html = AdminNav::bar('dashboard', [
            'logo_url' => 'https://whmcs.example/modules/addons/OpenNfse/assets/opennsfe_logo.png',
            'queue' => ['tone' => 'ok', 'text' => 'Fila: 3 pendentes', 'title' => 'Cron executado há 1 min (WHMCS)'],
            'version' => ['current' => '0.1.26', 'latest' => '0.1.27', 'update_available' => true, 'href' => 'addonmodules.php?module=OpenNfse&action=config&tab=processamento'],
        ]);

        $this->assertStringContainsString(
            '<img class="nfse-navbar__logo" src="https://whmcs.example/modules/addons/OpenNfse/assets/opennsfe_logo.png" alt="" width="28" height="28">'
            . '<span class="nfse-navbar__name">OpenNfse</span>',
            $html
        );
        $this->assertStringContainsString(
            '<div class="nfse-navbar__status"><a class="nfse-navbar__queue" href="addonmodules.php?module=OpenNfse&amp;action=fila" title="Cron executado há 1 min (WHMCS)">'
            . '<span class="nfse-dot nfse-dot--ok" aria-hidden="true"></span>Fila: 3 pendentes</a>'
            . '<a class="nfse-badge nfse-badge--version nfse-badge--warn" href="addonmodules.php?module=OpenNfse&amp;action=config&amp;tab=processamento">v0.1.26 → 0.1.27 disponível</a></div>',
            $html
        );

        $neutral = AdminNav::bar('dashboard', ['version' => ['current' => 'v0.1.27', 'latest' => '0.1.27', 'update_available' => false]]);
        $this->assertStringContainsString('<div class="nfse-navbar__status"><span class="nfse-badge nfse-badge--version">v0.1.27</span></div>', $neutral);
        $this->assertStringNotContainsString('nfse-navbar__queue', $neutral, 'sem estado da fila, o link não aparece');
    }

    public function testBarEscapesValues(): void
    {
        $html = AdminNav::bar('dashboard', [
            'logo_url' => 'x" onerror="alert(1)',
            'queue' => ['tone' => 'ok"><b', 'text' => '<script>', 'title' => '"><i>'],
            'version' => ['current' => '<b>1</b>'],
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringContainsString('src="x&quot; onerror=&quot;alert(1)"', $html);
        $this->assertStringContainsString('&lt;script&gt;</a>', $html);
    }

    public function testQueueStatusTexts(): void
    {
        $fresh = self::cron('2026-11-05 09:59:00');

        $this->assertSame(
            ['tone' => 'ok', 'text' => 'Fila: vazia', 'title' => 'Cron executado há 1 min (WHMCS)'],
            AdminNav::queueStatus(self::snapshot([]), $fresh)
        );
        $this->assertSame('Fila: 1 pendente', AdminNav::queueStatus(self::snapshot(['PENDING' => 1]), $fresh)['text']);
        $this->assertSame('Fila: 1.200 pendentes', AdminNav::queueStatus(self::snapshot(['PENDING' => 1200]), $fresh)['text']);
        $this->assertSame('ok', AdminNav::queueStatus(self::snapshot(['PENDING' => 3, 'RUNNING' => 2, 'WAIT_STATUS' => 1]), $fresh)['tone']);
    }

    public function testQueueStatusWithErrorsOrStaleCron(): void
    {
        $fresh = self::cron('2026-11-05 09:59:00');

        $errors = AdminNav::queueStatus(self::snapshot(['ERROR' => 2, 'PENDING' => 3]), $fresh);
        $this->assertSame('danger', $errors['tone']);
        $this->assertSame('Fila: 2 com erro · 3 pendentes', $errors['text']);
        $this->assertSame('Fila: 1 com erro', AdminNav::queueStatus(self::snapshot(['ERROR' => 1]), $fresh)['text']);

        $stale = AdminNav::queueStatus(self::snapshot(['PENDING' => 3]), self::cron('2026-11-05 09:30:00'));
        $this->assertSame(['tone' => 'danger', 'text' => 'Fila: 3 pendentes', 'title' => 'Cron executado há 30 min (WHMCS)'], $stale);

        $never = AdminNav::queueStatus(self::snapshot([]), self::cron(''));
        $this->assertSame(['tone' => 'danger', 'text' => 'Fila: vazia', 'title' => 'Cron nunca executado'], $never);

        $this->assertSame('ok', AdminNav::queueStatus(self::snapshot([]), null)['tone'], 'sem a configuração, só a fila decide');
        $this->assertNull(AdminNav::queueStatus(null, $fresh), 'sem os dados da fila, o estado some');
    }

    public function testTitleRowAndShell(): void
    {
        $this->assertSame(
            '<div class="nfse-header nfse-titlerow"><div><h1 class="nfse-title">Fila</h1><div class="nfse-subtitle">Itens de emissão e consulta</div></div></div>',
            AdminNav::titleRow('Fila', 'Itens de emissão e consulta')
        );
        $this->assertSame('<div class="nfse-header nfse-titlerow"><div><h1 class="nfse-title">A &amp; &lt;b&gt;</h1></div></div>', AdminNav::titleRow('A & <b>'));
        $this->assertStringEndsWith('<div class="nfse-header__tools"><button>x</button></div></div>', AdminNav::titleRow('T', '', '<button>x</button>'));

        $defaults = [
            'fila' => ['Fila', 'Itens de emissão e consulta'],
            'relatorios' => ['Relatórios', 'Notas, cancelamentos e exportações'],
            'config' => ['Configurações', 'Certificado, prestador e processamento'],
        ];
        foreach ($defaults as $tab => [$title, $subtitle]) {
            $html = AdminNav::shell($tab);
            $this->assertStringStartsWith('<div class="nfse-ui nfse-shell"><div class="nfse-navbar">', $html);
            $this->assertStringEndsWith(AdminNav::titleRow($title, $subtitle) . '</div>', $html);
        }

        $this->assertStringNotContainsString('nfse-titlerow', AdminNav::shell('dashboard'), 'a Dashboard monta a própria linha de título');
        $this->assertStringNotContainsString('nfse-titlerow', AdminNav::shell('fila', [], ''));
        $this->assertStringContainsString(
            '<h1 class="nfse-title">Alertas de NFS-e</h1><div class="nfse-subtitle">Notas que pedem revisão manual</div>',
            AdminNav::shell('dashboard', [], 'Alertas de NFS-e', 'Notas que pedem revisão manual')
        );
        $this->assertStringContainsString('<h1 class="nfse-title">Detalhe do log</h1></div>', AdminNav::shell('relatorios', [], 'Detalhe do log'));
    }

    public function testModuleTitleStyleOnlyOnAddonPage(): void
    {
        $style = '<style>#contentarea > div > h1:first-of-type{display:none}</style>';

        $this->assertSame($style, AdminNav::moduleTitleStyle('addonmodules', ['module' => 'OpenNfse', 'action' => 'fila']));
        $this->assertSame($style, AdminNav::moduleTitleStyle('/admin/addonmodules.php', ['module' => 'OpenNfse']));
        $this->assertSame('', AdminNav::moduleTitleStyle('addonmodules', ['module' => 'outro_addon']));
        $this->assertSame('', AdminNav::moduleTitleStyle('addonmodules', []));
        $this->assertSame('', AdminNav::moduleTitleStyle('invoices', ['module' => 'OpenNfse']));
        $this->assertSame('', AdminNav::moduleTitleStyle('index', []));
    }

    public function testRenderTabsNeverBreaksWhenTheQueueQueryFails(): void
    {
        // O stub do Capsule não tem raw()/groupBy(): a consulta da fila falha, e a barra sai sem o estado.
        Capsule::$rows['mod_opennfse_config'] = [(object) ['id' => 1, 'cron_last_run_at' => '', 'cron_last_source' => '']];
        $controller = new class () {
            use AdminHelpersTrait;
        };

        ob_start();
        $controller->renderTabs('fila');
        $html = (string) ob_get_clean();

        $this->assertStringStartsWith('<div class="nfse-ui nfse-shell"><div class="nfse-navbar">', $html);
        $this->assertStringContainsString('aria-current="page"><svg', $html);
        $this->assertStringNotContainsString('nfse-navbar__queue', $html);
        $this->assertStringContainsString('<h1 class="nfse-title">Fila</h1>', $html);
        $this->assertStringContainsString('/modules/addons/OpenNfse/assets/opennsfe_logo.png', $html);
    }

    public function testRenderTabsUsesTheQueueGivenByTheScreen(): void
    {
        $controller = new class () {
            use AdminHelpersTrait;
        };

        ob_start();
        $controller->renderTabs('dashboard', '', null, [
            'config' => ['cron_last_run_at' => date('Y-m-d H:i:s'), 'cron_last_source' => 'whmcs_cron'],
            'queue' => self::snapshot(['PENDING' => 2]),
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<span class="nfse-dot nfse-dot--ok" aria-hidden="true"></span>Fila: 2 pendentes</a>', $html);
        $this->assertStringNotContainsString('nfse-titlerow', $html);
    }
}
