<?php

declare(strict_types=1);

namespace OpenNfse\Views;

/**
 * Barra de navegação das telas do addon no admin (Dashboard, Fila, Relatórios, Configurações),
 * com o título de cada tela, e o estilo que esconde o título "OpenNfse" impresso pelo WHMCS.
 * Não acessa o banco: recebe os dados prontos (AdminHelpersTrait::renderTabs).
 */
final class AdminNav
{
    public const TABS = [
        'dashboard' => ['label' => 'Dashboard', 'url' => 'addonmodules.php?module=OpenNfse&action=dashboard', 'icon' => 'grid'],
        'fila' => ['label' => 'Fila', 'url' => 'addonmodules.php?module=OpenNfse&action=fila', 'icon' => 'list'],
        'relatorios' => ['label' => 'Relatórios', 'url' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas', 'icon' => 'bars'],
        'config' => ['label' => 'Configurações', 'url' => 'addonmodules.php?module=OpenNfse&action=config', 'icon' => 'gear'],
    ];

    /** Título e subtítulo padrão de cada aba, mostrados na barra. A Dashboard passa o subtítulo com o período. */
    public const TITLES = [
        'dashboard' => ['Visão geral', ''],
        'fila' => ['Fila', 'Itens de emissão e consulta'],
        'relatorios' => ['Relatórios', 'Notas, cancelamentos e exportações'],
        'config' => ['Configurações', 'Certificado, prestador e processamento'],
    ];

    /**
     * Título "OpenNfse" que o WHMCS imprime acima da saída do addon: no tema Blend (WHMCS 8.13 e 9.1),
     * é o h1 filho direto do primeiro div de #contentarea. first-of-type, e não first-child, porque no
     * 8.x o link de ajuda (div.contexthelp) pode vir antes dele. A saída do addon fica dentro de
     * div.nfse-admin, então nenhum h1 dela é filho direto desse div.
     */
    public const MODULE_TITLE_SELECTOR = '#contentarea > div > h1:first-of-type';

    private const ICONS = [
        'grid' => '<rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect>'
            . '<rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect>',
        'list' => '<path d="M4 6h16M4 12h16M4 18h10"></path>',
        'bars' => '<path d="M5 20V10M12 20V4M19 20v-7"></path>',
        'gear' => '<circle cx="12" cy="12" r="3"></circle>'
            . '<path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"></path>',
    ];

    /**
     * @param array{
     *     logo_url?: string,
     *     queue?: array{tone: string, text: string, title: string}|null,
     *     version?: array{current?: string, latest?: string, update_available?: bool, href?: string},
     *     title?: string,
     *     subtitle?: string,
     *     detail?: string
     * } $d title, subtitle e detail: título da tela, subtítulo e um complemento que só vai no title (dica)
     */
    public static function bar(string $active, array $d = []): string
    {
        $html = '<div class="nfse-navbar">';
        $html .= '<div class="nfse-navbar__brand">';
        $logo = trim((string) ($d['logo_url'] ?? ''));
        if ($logo !== '') {
            $html .= '<img class="nfse-navbar__logo" src="' . self::h($logo) . '" alt="" width="28" height="28">';
        }
        $html .= '<span class="nfse-navbar__name">OpenNfse</span></div>';
        $html .= self::pageTitle((string) ($d['title'] ?? ''), (string) ($d['subtitle'] ?? ''), (string) ($d['detail'] ?? ''));

        $html .= '<nav class="nfse-navbar__tabs" aria-label="Seções do OpenNfse">';
        foreach (self::TABS as $key => $tab) {
            $current = $key === $active ? ' aria-current="page"' : '';
            $html .= '<a class="nfse-tab" href="' . self::h($tab['url']) . '"' . $current . '>'
                . self::icon($tab['icon']) . self::h($tab['label']) . '</a>';
        }
        $html .= '</nav>';

        $html .= '<div class="nfse-navbar__status">';
        $queue = $d['queue'] ?? null;
        if (is_array($queue)) {
            $html .= '<a class="nfse-navbar__queue" href="' . self::h(self::TABS['fila']['url']) . '"'
                . ($queue['title'] !== '' ? ' title="' . self::h($queue['title']) . '"' : '') . '>'
                . '<span class="nfse-dot nfse-dot--' . self::h($queue['tone']) . '" aria-hidden="true"></span>'
                . self::h($queue['text']) . '</a>';
        }
        $html .= self::versionPill($d['version'] ?? []);
        $html .= '</div>';

        return $html . '</div>';
    }

    /**
     * Estado da fila na barra: "Fila: vazia", "Fila: 1 pendente", "Fila: 3 pendentes" ou, com erro em
     * aberto, "Fila: 2 com erro · 3 pendentes". O ponto fica vermelho com erro em aberto (ERROR) ou com o
     * cron parado ou nunca executado, e verde nos demais casos. Sem os dados da fila (consulta falhou), null.
     *
     * @param array{counts?: array<string, int>}|null $snapshot QueueRepository::currentSnapshot()
     * @param array{ran: bool, seconds: int, stale: bool, text: string, short: string}|null $cron Dashboard\DashboardQueue::cronState()
     * @return array{tone: string, text: string, title: string}|null
     */
    public static function queueStatus(?array $snapshot, ?array $cron): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $pending = max(0, (int) ($snapshot['counts']['PENDING'] ?? 0));
        $errors = max(0, (int) ($snapshot['counts']['ERROR'] ?? 0));
        $pendingText = $pending === 0 ? '' : number_format($pending, 0, ',', '.') . ($pending === 1 ? ' pendente' : ' pendentes');

        if ($errors > 0) {
            $text = 'Fila: ' . number_format($errors, 0, ',', '.') . ' com erro' . ($pendingText !== '' ? ' · ' . $pendingText : '');
        } else {
            $text = 'Fila: ' . ($pendingText !== '' ? $pendingText : 'vazia');
        }

        $stale = $cron !== null && $cron['stale'];

        return [
            'tone' => $errors > 0 || $stale ? 'danger' : 'ok',
            'text' => $text,
            'title' => $cron !== null ? $cron['text'] : '',
        ];
    }

    /**
     * Título da tela dentro da barra, entre a marca e as abas: o h1 da página (o do WHMCS fica escondido)
     * e o subtítulo na mesma linha. Em espaço curto, o texto termina em reticências; o title guarda o texto
     * inteiro, mais o complemento $detail, que não aparece na barra.
     */
    public static function pageTitle(string $title, string $subtitle = '', string $detail = ''): string
    {
        if ($title === '') {
            return '';
        }

        $full = implode(' · ', array_filter([$title, $subtitle, $detail], static fn (string $part): bool => $part !== ''));
        $html = '<div class="nfse-navbar__page" title="' . self::h($full) . '">';
        $html .= '<h1 class="nfse-navbar__title">' . self::h($title) . '</h1>';
        if ($subtitle !== '') {
            $html .= '<span class="nfse-navbar__subtitle"> · ' . self::h($subtitle) . '</span>';
        }

        return $html . '</div>';
    }

    /**
     * Barra de navegação dentro de .nfse-ui, para os tokens valerem. Título null usa o padrão da aba
     * (TITLES); string vazia não mostra o título.
     *
     * @param array<string, mixed> $d dados de bar()
     */
    public static function shell(string $active, array $d = [], ?string $title = null, ?string $subtitle = null, string $detail = ''): string
    {
        if ($title === null) {
            [$title, $defaultSubtitle] = self::TITLES[$active] ?? ['', ''];
            $subtitle ??= $defaultSubtitle;
        }

        $d['title'] = $title;
        $d['subtitle'] = (string) $subtitle;
        $d['detail'] = $detail;

        return '<div class="nfse-ui nfse-shell">' . self::bar($active, $d) . '</div>';
    }

    /**
     * Estilo que esconde o título "OpenNfse" do WHMCS. Só nas telas do addon
     * (addonmodules.php?module=OpenNfse); vazio nas demais páginas do admin.
     *
     * @param array<string, mixed> $query $_GET
     */
    public static function moduleTitleStyle(string $filename, array $query): string
    {
        $filename = basename(trim($filename), '.php');
        if ($filename !== 'addonmodules' || (string) ($query['module'] ?? '') !== 'OpenNfse') {
            return '';
        }

        return '<style>' . self::MODULE_TITLE_SELECTOR . '{display:none}</style>';
    }

    /**
     * @param array{current?: string, latest?: string, update_available?: bool, href?: string} $version
     */
    public static function versionPill(array $version): string
    {
        $current = ltrim(trim((string) ($version['current'] ?? '')), 'vV ');
        $latest = ltrim(trim((string) ($version['latest'] ?? '')), 'vV ');
        $href = trim((string) ($version['href'] ?? ''));

        if (!empty($version['update_available']) && $latest !== '') {
            $label = 'v' . ($current !== '' ? $current : '—') . ' → ' . $latest . ' disponível';

            return $href !== ''
                ? '<a class="nfse-badge nfse-badge--version nfse-badge--warn" href="' . self::h($href) . '">' . self::h($label) . '</a>'
                : '<span class="nfse-badge nfse-badge--version nfse-badge--warn">' . self::h($label) . '</span>';
        }

        return $current !== '' ? '<span class="nfse-badge nfse-badge--version">v' . self::h($current) . '</span>' : '';
    }

    private static function icon(string $name): string
    {
        return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
            . (self::ICONS[$name] ?? '') . '</svg>';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
