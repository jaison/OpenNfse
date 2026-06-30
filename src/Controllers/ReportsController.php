<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Module;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\GroupServiceCodeRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\PaymentGatewaySettingsRepository;
use OpenNfse\Repositories\QueueRepository;
use OpenNfse\Repositories\ReportRepository;
use OpenNfse\Repositories\ServiceNbsCatalogRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use OpenNfse\Repositories\WhmcsPaymentGatewayRepository;
use OpenNfse\Services\CryptoService;
use OpenNfse\Services\InvoiceEmailService;
use OpenNfse\Services\NfseService;
use OpenNfse\Services\QueueErrorClassifierService;
use OpenNfse\Services\QueueService;
use OpenNfse\Services\StorageService;
use OpenNfse\Services\TokenService;
use WHMCS\Database\Capsule;
use OpenNfse\Controllers\Support\AdminHelpersTrait;

final class ReportsController
{
    use AdminHelpersTrait;

    public function showRelatorios(): void
    {
        $active = trim((string) ($_GET['tab'] ?? 'emitidas'));
        $tabMeta = [
            'emitidas' => [
                'label' => 'NFS-e Emitidas',
                'badge' => 'Fiscal',
                'badge_bg' => '#e8f5e9',
                'badge_color' => '#2e7d32',
                'summary' => 'Acompanha notas emitidas e canceladas, com totais e exportações do período.',
            ],
            'falhas' => [
                'label' => 'Falhas',
                'badge' => 'Atenção',
                'badge_bg' => '#fff8e1',
                'badge_color' => '#8a6d3b',
                'summary' => 'Concentra erros de emissão, rejeições e problemas que exigem correção manual.',
            ],
            'cancelamentos' => [
                'label' => 'Cancelamentos',
                'badge' => 'Auditoria',
                'badge_bg' => '#fff3e0',
                'badge_color' => '#a67c00',
                'summary' => 'Mostra cancelamentos do período com foco em conferência fiscal e contábil.',
            ],
            'logs' => [
                'label' => 'Logs',
                'badge' => 'Técnico',
                'badge_bg' => '#eef2f7',
                'badge_color' => '#5f6b7a',
                'summary' => 'Centraliza request/response e rastreabilidade técnica para suporte e diagnóstico.',
            ],
        ];
        $allowed = [];
        foreach ($tabMeta as $key => $meta) {
            $allowed[$key] = (string) ($meta['label'] ?? $key);
        }
        if (!isset($allowed[$active])) {
            $active = 'emitidas';
        }

        Module::ui()->renderHeader('Relatórios - OpenNFS-e');
        $this->renderTabs('relatorios');

        $params = $_GET;
        unset($params['module'], $params['action'], $params['tab']);

        $h = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $activeMeta = $tabMeta[$active] ?? $tabMeta['emitidas'];

        echo '<style>';
        echo '.nfse-report-layout{display:flex;gap:18px;align-items:flex-start;}';
        echo '.nfse-report-sidebar{width:280px;border:1px solid #d9e1ea;background:#fafbfd;padding:12px;box-sizing:border-box;}';
        echo '.nfse-report-sidebar-summary{margin-top:4px;margin-bottom:12px;padding:10px;border:1px solid #e6ebf1;background:#fff;}';
        echo '.nfse-report-sidebar-summary strong{display:block;font-size:12px;color:#334155;margin-bottom:4px;}';
        echo '.nfse-report-sidebar-summary span{display:block;font-size:11px;color:#6b7785;line-height:1.45;}';
        echo '.nfse-report-sidebar-links a{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-radius:6px;margin-bottom:6px;text-decoration:none;color:#2c4778;border-left:3px solid transparent;transition:all .15s ease;}';
        echo '.nfse-report-sidebar-links a:hover{background:#f0f4f8;}';
        echo '.nfse-report-sidebar-links a.is-active{background:#eef4fb;border-left-color:#2d6ca2;font-weight:700;color:#234b74;box-shadow:inset 0 0 0 1px #dbe7f3;}';
        echo '.nfse-report-sidebar-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;white-space:nowrap;}';
        echo '.nfse-report-content{flex:1;min-width:0;}';
        echo '@media (max-width: 1200px){.nfse-report-layout{flex-direction:column;}.nfse-report-sidebar{width:100%;}}';
        echo '</style>';

        echo '<div class="nfse-report-layout">';
        echo '<div class="nfse-report-sidebar">';
        echo '<div class="nfse-report-sidebar-summary">';
        echo '<strong>' . $h((string) ($activeMeta['label'] ?? 'Relatórios')) . '</strong>';
        echo '<span>' . $h((string) ($activeMeta['summary'] ?? '')) . '</span>';
        echo '</div>';
        echo '<div class="nfse-report-sidebar-links">';
        foreach ($allowed as $key => $label) {
            $p = $params;
            $p['tab'] = $key;
            $url = 'addonmodules.php?module=OpenNfse&action=relatorios';
            if (!empty($p)) {
                $url .= '&' . http_build_query($p, '', '&', PHP_QUERY_RFC3986);
            }
            $isActive = $key === $active;
            $meta = $tabMeta[$key] ?? ['badge' => 'Relatório', 'badge_bg' => '#eef2f7', 'badge_color' => '#5f6b7a'];
            echo '<a href="' . $h($url) . '" class="' . ($isActive ? 'is-active' : '') . '">';
            echo '<span>' . $h($label) . '</span>';
            echo '<span class="nfse-report-sidebar-badge" style="background:' . $h((string) ($meta['badge_bg'] ?? '#eef2f7')) . ';color:' . $h((string) ($meta['badge_color'] ?? '#5f6b7a')) . ';">' . $h((string) ($meta['badge'] ?? 'Relatório')) . '</span>';
            echo '</a>';
        }
        echo '</div>';
        echo '</div>';
        echo '<div class="nfse-report-content">';

        if ($active === 'falhas') {
            $this->showRelatorioFalhas(true);
        } elseif ($active === 'cancelamentos') {
            $this->showRelatorioCancelamentos(true);
        } elseif ($active === 'logs') {
            $this->showRelatorioLogs(true);
        } else {
            $this->showRelatorioEmitidas(true);
        }

        echo '</div>';
        echo '</div>';

        Module::ui()->renderFooter();
    }


    public function showRelatorioEmitidas(bool $embedded = false): void
    {
        if (!$embedded) {
            $this->redirectRelatorios('emitidas');
        }

        $dataInicial = trim((string) ($_REQUEST['data_inicial'] ?? ''));
        $dataFinal = trim((string) ($_REQUEST['data_final'] ?? ''));
        $status = trim((string) ($_REQUEST['status'] ?? 'EMITIDA,CANCELADA'));
        $cliente = trim((string) ($_REQUEST['cliente'] ?? ''));

        $filters = [
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => $status,
            'cliente' => $cliente,
        ];

        $repo = new ReportRepository();
        $rows = $repo->listNotas($filters, 200);
        $summary = $repo->summaryNotas($filters);

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="emitidas" />';
        echo '<div style="margin-bottom:14px;border:1px solid #ddd;padding:12px;background:#fafafa;">';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px;">';
        echo '<div style="min-width:150px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Data inicial</div>';
        echo '<input type="date" name="data_inicial" value="' . htmlspecialchars($dataInicial, ENT_QUOTES, 'UTF-8') . '" style="width:150px;" />';
        echo '</div>';
        echo '<div style="min-width:150px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Data final</div>';
        echo '<input type="date" name="data_final" value="' . htmlspecialchars($dataFinal, ENT_QUOTES, 'UTF-8') . '" style="width:150px;" />';
        echo '</div>';
        echo '<div style="min-width:200px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Status</div>';
        echo '<select name="status" style="width:200px;">';
        $statusOptions = [
            '' => 'Todos os status',
            'EMITIDA,CANCELADA' => 'Emitidas e Canceladas',
            'EMITIDA' => 'Emitida',
            'CANCELADA' => 'Cancelada',
            'PROCESSANDO' => 'Processando',
            'REJEITADA' => 'Rejeitada',
            'ERRO' => 'Erro',
        ];
        foreach ($statusOptions as $val => $label) {
            $sel = $val === $status ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<div style="flex:1;min-width:320px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Cliente</div>';
        echo '<input type="text" name="cliente" placeholder="Cliente (ID ou nome)" value="' . htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8') . '" style="width:100%;" />';
        echo '</div>';
        echo '<div style="display:flex;gap:6px;align-items:flex-end;">';
        echo '<button type="submit" class="btn btn-xs btn-default">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:12%;">Data</th>';
        echo '<th style="width:10%;">NFS-e</th>';
        echo '<th style="width:34%;">Cliente</th>';
        echo '<th style="width:10%;">Invoice</th>';
        echo '<th style="width:14%;">Valor</th>';
        echo '<th style="width:20%;">Status</th>';
        echo '</tr>';

        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            $clienteNome = $this->resolveClientName($row);
            $numeroNfse = (string) ($row['numero_nf'] ?? '');
            $statusRow = (string) ($row['status'] ?? '');
            $dataRef = (string) ($row['emitida_em'] ?? $row['nfse_updated_at'] ?? '');
            $dataFmt = $this->formatDate($dataRef, 'd/m/Y');
            $valor = (float) ($row['invoice_total'] ?? 0);
            $prefix = (string) ($row['currency_prefix'] ?? 'R$ ');
            $suffix = (string) ($row['currency_suffix'] ?? '');

            echo '<tr>';
            echo '<td>' . htmlspecialchars($dataFmt, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($numeroNfse !== '' ? $numeroNfse : '-', ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($clienteNome, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a></td>';
            echo '<td>' . htmlspecialchars($this->formatMoney($valor, $prefix, $suffix), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($statusRow, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }

        echo '</table>';

        echo '<div style="margin-top:10px;">';
        echo 'Total de notas: <strong>' . (int) ($summary['total_notas'] ?? 0) . '</strong> &nbsp; ';
        $statusParts = array_values(array_filter(array_map('trim', explode(',', $status)), static fn (string $v): bool => $v !== ''));
        $showSplitTotals = count($statusParts) === 2 && in_array('EMITIDA', $statusParts, true) && in_array('CANCELADA', $statusParts, true);
        if ($showSplitTotals) {
            $filtersEmitidas = $filters;
            $filtersEmitidas['status'] = 'EMITIDA';
            $filtersCanceladas = $filters;
            $filtersCanceladas['status'] = 'CANCELADA';
            $sumEmitidas = $repo->summaryNotas($filtersEmitidas);
            $sumCanceladas = $repo->summaryNotas($filtersCanceladas);
            echo 'Valor total Emitidas: <strong>' . htmlspecialchars($this->formatMoney((float) ($sumEmitidas['total_valor'] ?? 0), 'R$ ', ''), ENT_QUOTES, 'UTF-8') . '</strong> &nbsp; ';
            echo 'Valor total Canceladas: <strong>' . htmlspecialchars($this->formatMoney((float) ($sumCanceladas['total_valor'] ?? 0), 'R$ ', ''), ENT_QUOTES, 'UTF-8') . '</strong>';
        } else {
            echo 'Valor total: <strong>' . htmlspecialchars($this->formatMoney((float) ($summary['total_valor'] ?? 0), 'R$ ', ''), ENT_QUOTES, 'UTF-8') . '</strong>';
        }
        echo '<div style="margin-top:6px;">';
        echo $this->renderPostActionButton('relatoriosExport', 'Exportar CSV', [
            'tab' => 'emitidas',
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => $status,
            'cliente' => $cliente,
        ], 'btn btn-xs btn-default');
        echo $this->renderPostActionButton('relatoriosExportZip', 'Baixar XMLs ZIP', [
            'tab' => 'emitidas',
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => $status,
            'cliente' => $cliente,
        ], 'btn btn-xs btn-default', 'margin-left:6px;');
        echo '</div>';
        echo '</div>';
    }


    public function showRelatorioFalhas(bool $embedded = false): void
    {
        if (!$embedded) {
            $this->redirectRelatorios('falhas');
        }

        $dataInicial = trim((string) ($_REQUEST['data_inicial'] ?? ''));
        $dataFinal = trim((string) ($_REQUEST['data_final'] ?? ''));
        $cliente = trim((string) ($_REQUEST['cliente'] ?? ''));

        $filters = [
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'cliente' => $cliente,
        ];

        $rows = (new ReportRepository())->listFalhas($filters, 200);

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="falhas" />';
        echo '<div style="margin-bottom:14px;border:1px solid #ddd;padding:12px;background:#fafafa;">';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px;">';
        echo '<div style="min-width:150px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Data inicial</div>';
        echo '<input type="date" name="data_inicial" value="' . htmlspecialchars($dataInicial, ENT_QUOTES, 'UTF-8') . '" style="width:150px;" />';
        echo '</div>';
        echo '<div style="min-width:150px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Data final</div>';
        echo '<input type="date" name="data_final" value="' . htmlspecialchars($dataFinal, ENT_QUOTES, 'UTF-8') . '" style="width:150px;" />';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<div style="flex:1;min-width:320px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Cliente</div>';
        echo '<input type="text" name="cliente" placeholder="Cliente (ID ou nome)" value="' . htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8') . '" style="width:100%;" />';
        echo '</div>';
        echo '<div style="display:flex;gap:6px;align-items:flex-end;">';
        echo '<button type="submit" class="btn btn-xs btn-default">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas">Limpar</a>';
        echo $this->renderPostActionButton('relatoriosExport', 'Exportar CSV', [
            'tab' => 'falhas',
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'cliente' => $cliente,
        ], 'btn btn-xs btn-default');
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:12%;">Data</th>';
        echo '<th style="width:38%;">Cliente</th>';
        echo '<th style="width:10%;">Invoice</th>';
        echo '<th style="width:40%;">Erro</th>';
        echo '</tr>';

        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            $clienteNome = $this->resolveClientName($row);
            $dataFmt = $this->formatDate((string) ($row['data'] ?? ''), 'd/m/Y');
            $erro = trim((string) ($row['erro'] ?? ''));
            if ($erro === '') {
                $erro = '-';
            }
            if (mb_strlen($erro) > 220) {
                $erro = mb_substr($erro, 0, 220) . '...';
            }

            echo '<tr>';
            echo '<td>' . htmlspecialchars($dataFmt, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($clienteNome, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a></td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }


    public function showRelatorioCancelamentos(bool $embedded = false): void
    {
        if (!$embedded) {
            $this->redirectRelatorios('cancelamentos');
        }

        $dataInicial = trim((string) ($_REQUEST['data_inicial'] ?? ''));
        $dataFinal = trim((string) ($_REQUEST['data_final'] ?? ''));
        $cliente = trim((string) ($_REQUEST['cliente'] ?? ''));

        $filters = [
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => '',
            'cliente' => $cliente,
            'date_field' => 'cancelado',
        ];

        $repo = new ReportRepository();
        $rows = $repo->listNotas($filters, 200);

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="cancelamentos" />';
        echo '<div style="margin-bottom:14px;border:1px solid #ddd;padding:12px;background:#fafafa;">';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px;">';
        echo '<div style="min-width:150px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Data inicial</div>';
        echo '<input type="date" name="data_inicial" value="' . htmlspecialchars($dataInicial, ENT_QUOTES, 'UTF-8') . '" style="width:150px;" />';
        echo '</div>';
        echo '<div style="min-width:150px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Data final</div>';
        echo '<input type="date" name="data_final" value="' . htmlspecialchars($dataFinal, ENT_QUOTES, 'UTF-8') . '" style="width:150px;" />';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<div style="flex:1;min-width:320px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Cliente</div>';
        echo '<input type="text" name="cliente" placeholder="Cliente (ID ou nome)" value="' . htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8') . '" style="width:100%;" />';
        echo '</div>';
        echo '<div style="display:flex;gap:6px;align-items:flex-end;">';
        echo '<button type="submit" class="btn btn-xs btn-default">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=cancelamentos">Limpar</a>';
        echo $this->renderPostActionButton('relatoriosExport', 'Exportar CSV', [
            'tab' => 'cancelamentos',
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'cliente' => $cliente,
        ], 'btn btn-xs btn-default');
        echo $this->renderPostActionButton('relatoriosExportZip', 'Baixar XMLs ZIP', [
            'tab' => 'cancelamentos',
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'cliente' => $cliente,
        ], 'btn btn-xs btn-default');
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:14%;">Data Emissão</th>';
        echo '<th style="width:14%;">Data Cancelamento</th>';
        echo '<th style="width:10%;">NFS-e</th>';
        echo '<th style="width:34%;">Cliente</th>';
        echo '<th style="width:10%;">Invoice</th>';
        echo '<th style="width:18%;">Valor</th>';
        echo '</tr>';

        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            $clienteNome = $this->resolveClientName($row);
            $numeroNfse = (string) ($row['numero_nf'] ?? '');
            $emissaoFmt = $this->formatDate((string) ($row['emitida_em'] ?? ''), 'd/m/Y');
            $cancelFmt = $this->formatDate((string) ($row['cancelado_em'] ?? ''), 'd/m/Y');
            $valor = (float) ($row['invoice_total'] ?? 0);
            $prefix = (string) ($row['currency_prefix'] ?? 'R$ ');
            $suffix = (string) ($row['currency_suffix'] ?? '');

            echo '<tr>';
            echo '<td>' . htmlspecialchars($emissaoFmt, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($cancelFmt, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($numeroNfse !== '' ? $numeroNfse : '-', ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($clienteNome, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a></td>';
            echo '<td>' . htmlspecialchars($this->formatMoney($valor, $prefix, $suffix), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }


    public function showRelatorioLogs(bool $embedded = false): void
    {
        if (!$embedded) {
            $this->redirectRelatorios('logs');
        }

        $invoiceFilter = trim((string) ($_REQUEST['invoiceid'] ?? ''));
        $tipoFilter = trim((string) ($_REQUEST['tipo'] ?? ''));
        $dataInicial = trim((string) ($_REQUEST['data_inicial'] ?? ''));
        $dataFinal = trim((string) ($_REQUEST['data_final'] ?? ''));
        $qFilter = trim((string) ($_REQUEST['q'] ?? ''));
        $page = (int) ($_REQUEST['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $buildQuery = static function () use ($invoiceFilter, $tipoFilter, $dataInicial, $dataFinal, $qFilter) {
            $q = Capsule::table('mod_opennfse_logs as l')
                ->leftJoin('mod_opennfse_notas as n', 'n.id', '=', 'l.nota_id');

            if ($invoiceFilter !== '' && ctype_digit($invoiceFilter)) {
                $invoiceId = (int) $invoiceFilter;
                $q->where(static function ($w) use ($invoiceId) {
                    $w->where('n.invoiceid', $invoiceId)
                        ->orWhere('l.request', 'like', '%"invoiceid":' . $invoiceId . '%')
                        ->orWhere('l.request', 'like', '%"invoiceid": ' . $invoiceId . '%')
                        ->orWhere('l.response', 'like', '%"invoiceid":' . $invoiceId . '%')
                        ->orWhere('l.response', 'like', '%"invoiceid": ' . $invoiceId . '%');
                });
            }

            if ($tipoFilter !== '') {
                $q->where('l.tipo', $tipoFilter);
            }

            if ($dataInicial !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicial)) {
                $q->where('l.created_at', '>=', $dataInicial . ' 00:00:00');
            }
            if ($dataFinal !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFinal)) {
                $q->where('l.created_at', '<=', $dataFinal . ' 23:59:59');
            }

            if ($qFilter !== '') {
                $q->where(static function ($w) use ($qFilter) {
                    $w->where('l.request', 'like', '%' . $qFilter . '%')
                        ->orWhere('l.response', 'like', '%' . $qFilter . '%');
                });
            }

            return $q;
        };

        $tipoOptions = [];
        try {
            $tipoRows = Capsule::table('mod_opennfse_logs')->select('tipo')->distinct()->orderBy('tipo', 'asc')->limit(200)->get();
            foreach ($tipoRows as $r) {
                $t = trim((string) ($r->tipo ?? ''));
                if ($t !== '') {
                    $tipoOptions[] = $t;
                }
            }
        } catch (\Throwable $e) {
            $tipoOptions = [];
        }

        $total = (int) $buildQuery()->count('l.id');
        $rows = $buildQuery()
            ->select([
                'l.id',
                'l.nota_id',
                'l.tipo',
                'l.request',
                'l.response',
                'l.created_at',
                'n.invoiceid as nota_invoiceid',
            ])
            ->orderBy('l.id', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="logs" />';
        echo '<div style="margin-bottom:14px;border:1px solid #ddd;padding:12px;background:#fafafa;">';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px;">';
        echo '<div style="min-width:150px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Data inicial</div>';
        echo '<input type="date" name="data_inicial" value="' . htmlspecialchars($dataInicial, ENT_QUOTES, 'UTF-8') . '" style="width:150px;" />';
        echo '</div>';
        echo '<div style="min-width:150px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Data final</div>';
        echo '<input type="date" name="data_final" value="' . htmlspecialchars($dataFinal, ENT_QUOTES, 'UTF-8') . '" style="width:150px;" />';
        echo '</div>';
        echo '<div style="min-width:120px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Invoice ID</div>';
        echo '<input type="text" name="invoiceid" placeholder="Invoice ID" value="' . htmlspecialchars($invoiceFilter, ENT_QUOTES, 'UTF-8') . '" style="width:120px;" />';
        echo '</div>';
        echo '<div style="min-width:240px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Tipo</div>';
        echo '<select name="tipo" style="width:240px;">';
        echo '<option value="">Todos os tipos</option>';
        foreach ($tipoOptions as $t) {
            $sel = $t === $tipoFilter ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<div style="flex:1;min-width:320px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Buscar em request/response</div>';
        echo '<input type="text" name="q" placeholder="Buscar (request/response)" value="' . htmlspecialchars($qFilter, ENT_QUOTES, 'UTF-8') . '" style="width:100%;" />';
        echo '</div>';
        echo '<div style="display:flex;gap:6px;align-items:flex-end;">';
        echo '<button type="submit" class="btn btn-xs btn-default">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=logs">Limpar</a>';
        echo $this->renderPostActionButton('relatoriosExport', 'Exportar CSV', [
            'tab' => 'logs',
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'invoiceid' => $invoiceFilter,
            'tipo' => $tipoFilter,
            'q' => $qFilter,
        ], 'btn btn-xs btn-default');
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        $extractInvoiceId = static function (?string $json): ?int {
            $json = (string) $json;
            if ($json === '') {
                return null;
            }
            $data = json_decode($json, true);
            if (is_array($data) && isset($data['invoiceid']) && is_numeric($data['invoiceid'])) {
                return (int) $data['invoiceid'];
            }
            if (preg_match('/"invoiceid"\s*:\s*(\d+)/', $json, $m)) {
                return (int) $m[1];
            }
            return null;
        };

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:6%;text-align:center;">ID</th>';
        echo '<th style="width:14%;">Data</th>';
        echo '<th style="width:14%;">Tipo</th>';
        echo '<th style="width:8%;">Invoice</th>';
        echo '<th style="width:26%;">Request</th>';
        echo '<th style="width:26%;">Response</th>';
        echo '<th style="width:6%;"></th>';
        echo '</tr>';

        foreach ($rows as $r) {
            $id = (int) ($r->id ?? 0);
            $notaId = (int) ($r->nota_id ?? 0);
            $tipo = (string) ($r->tipo ?? '');
            $created = (string) ($r->created_at ?? '');
            $req = (string) ($r->request ?? '');
            $resp = (string) ($r->response ?? '');
            $notaInvoiceId = (int) ($r->nota_invoiceid ?? 0);

            $invoiceId = $notaInvoiceId > 0 ? $notaInvoiceId : ($extractInvoiceId($req) ?? $extractInvoiceId($resp) ?? 0);
            $invoiceCell = '-';
            if ($invoiceId > 0) {
                $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
                $invoiceCell = '<a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a>';
            }

            echo '<tr>';
            echo '<td style="font-size:12px;text-align:center;">' . $id . '</td>';
            echo '<td style="font-size:12px;">' . htmlspecialchars($created, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="font-size:12px;word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="font-size:12px;">' . $invoiceCell . '</td>';
            if ($req !== '') {
                echo '<td><textarea readonly rows="1" style="width:100%;max-width:100%;box-sizing:border-box;height:26px;resize:none;white-space:pre-wrap;overflow-y:auto;overflow-x:hidden;word-break:break-word;">' . htmlspecialchars($req, ENT_QUOTES, 'UTF-8') . '</textarea></td>';
            } else {
                echo '<td>-</td>';
            }
            if ($resp !== '') {
                echo '<td><textarea readonly rows="1" style="width:100%;max-width:100%;box-sizing:border-box;height:26px;resize:none;white-space:pre-wrap;overflow-y:auto;overflow-x:hidden;word-break:break-word;">' . htmlspecialchars($resp, ENT_QUOTES, 'UTF-8') . '</textarea></td>';
            } else {
                echo '<td>-</td>';
            }
            echo '<td style="font-size:12px;">' . $this->renderPostActionButton('logView', 'Abrir', ['id' => $id], 'btn btn-xs btn-default') . '</td>';
            echo '</tr>';
        }

        echo '</table>';

        $totalPages = (int) ceil($total / $perPage);
        if ($totalPages < 1) {
            $totalPages = 1;
        }
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        echo '<div style="margin-top:10px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">';
        echo '<div>Total: <strong>' . $total . '</strong></div>';

        $baseParams = [
            'module' => 'OpenNfse',
            'action' => 'relatorios',
            'tab' => 'logs',
            'invoiceid' => $invoiceFilter,
            'tipo' => $tipoFilter,
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'q' => $qFilter,
        ];

        echo '<div>';
        if ($page > 1) {
            $p = $baseParams;
            $p['page'] = $page - 1;
            echo '<a class="btn btn-default" href="addonmodules.php?' . htmlspecialchars(http_build_query($p, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Anterior</a> ';
        }
        echo '<span style="margin:0 6px;">Página ' . $page . ' / ' . $totalPages . '</span>';
        if ($page < $totalPages) {
            $p = $baseParams;
            $p['page'] = $page + 1;
            echo '<a class="btn btn-default" href="addonmodules.php?' . htmlspecialchars(http_build_query($p, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Próxima</a>';
        }
        echo '</div>';
        echo '</div>';
    }


    public function exportRelatoriosCsv(): void
    {
        $tab = trim((string) ($_REQUEST['tab'] ?? 'emitidas'));
        $allowed = ['emitidas' => true, 'falhas' => true, 'cancelamentos' => true, 'logs' => true];
        if (!isset($allowed[$tab])) {
            $tab = 'emitidas';
        }

        $filename = 'nfse_' . $tab . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        fwrite($out, "\xEF\xBB\xBF");

        if ($tab === 'falhas') {
            $dataInicial = trim((string) ($_REQUEST['data_inicial'] ?? ''));
            $dataFinal = trim((string) ($_REQUEST['data_final'] ?? ''));
            $cliente = trim((string) ($_REQUEST['cliente'] ?? ''));
            $rows = (new ReportRepository())->listFalhas([
                'data_inicial' => $dataInicial,
                'data_final' => $dataFinal,
                'cliente' => $cliente,
            ], 5000);

            fputcsv($out, ['data', 'cliente', 'invoiceid', 'erro'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    (string) ($r['data'] ?? ''),
                    $this->resolveClientName($r),
                    (string) ($r['invoiceid'] ?? ''),
                    (string) ($r['erro'] ?? ''),
                ], ';');
            }
            fclose($out);
            exit;
        }

        if ($tab === 'cancelamentos') {
            $dataInicial = trim((string) ($_REQUEST['data_inicial'] ?? ''));
            $dataFinal = trim((string) ($_REQUEST['data_final'] ?? ''));
            $cliente = trim((string) ($_REQUEST['cliente'] ?? ''));
            $rows = (new ReportRepository())->listNotas([
                'data_inicial' => $dataInicial,
                'data_final' => $dataFinal,
                'status' => '',
                'cliente' => $cliente,
                'date_field' => 'cancelado',
            ], 5000);

            fputcsv($out, ['emitida_em', 'cancelado_em', 'numero_nf', 'cliente', 'invoiceid', 'valor', 'status'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    (string) ($r['emitida_em'] ?? ''),
                    (string) ($r['cancelado_em'] ?? ''),
                    (string) ($r['numero_nf'] ?? ''),
                    $this->resolveClientName($r),
                    (string) ($r['invoiceid'] ?? ''),
                    (string) ($r['invoice_total'] ?? ''),
                    (string) ($r['status'] ?? ''),
                ], ';');
            }
            fclose($out);
            exit;
        }

        if ($tab === 'logs') {
            $invoiceFilter = trim((string) ($_REQUEST['invoiceid'] ?? ''));
            $tipoFilter = trim((string) ($_REQUEST['tipo'] ?? ''));
            $dataInicial = trim((string) ($_REQUEST['data_inicial'] ?? ''));
            $dataFinal = trim((string) ($_REQUEST['data_final'] ?? ''));
            $qFilter = trim((string) ($_REQUEST['q'] ?? ''));

            $q = Capsule::table('mod_opennfse_logs as l')
                ->leftJoin('mod_opennfse_notas as n', 'n.id', '=', 'l.nota_id');

            if ($invoiceFilter !== '' && ctype_digit($invoiceFilter)) {
                $invoiceId = (int) $invoiceFilter;
                $q->where(static function ($w) use ($invoiceId) {
                    $w->where('n.invoiceid', $invoiceId)
                        ->orWhere('l.request', 'like', '%"invoiceid":' . $invoiceId . '%')
                        ->orWhere('l.request', 'like', '%"invoiceid": ' . $invoiceId . '%')
                        ->orWhere('l.response', 'like', '%"invoiceid":' . $invoiceId . '%')
                        ->orWhere('l.response', 'like', '%"invoiceid": ' . $invoiceId . '%');
                });
            }
            if ($tipoFilter !== '') {
                $q->where('l.tipo', $tipoFilter);
            }
            if ($dataInicial !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicial)) {
                $q->where('l.created_at', '>=', $dataInicial . ' 00:00:00');
            }
            if ($dataFinal !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFinal)) {
                $q->where('l.created_at', '<=', $dataFinal . ' 23:59:59');
            }
            if ($qFilter !== '') {
                $q->where(static function ($w) use ($qFilter) {
                    $w->where('l.request', 'like', '%' . $qFilter . '%')
                        ->orWhere('l.response', 'like', '%' . $qFilter . '%');
                });
            }

            $rows = $q->select(['l.id', 'l.tipo', 'l.created_at', 'n.invoiceid as invoiceid', 'l.request', 'l.response'])
                ->orderBy('l.id', 'desc')
                ->limit(5000)
                ->get();

            fputcsv($out, ['id', 'created_at', 'tipo', 'invoiceid', 'request', 'response'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    (string) ($r->id ?? ''),
                    (string) ($r->created_at ?? ''),
                    (string) ($r->tipo ?? ''),
                    (string) ($r->invoiceid ?? ''),
                    (string) ($r->request ?? ''),
                    (string) ($r->response ?? ''),
                ], ';');
            }
            fclose($out);
            exit;
        }

        $dataInicial = trim((string) ($_REQUEST['data_inicial'] ?? ''));
        $dataFinal = trim((string) ($_REQUEST['data_final'] ?? ''));
        $status = trim((string) ($_REQUEST['status'] ?? 'EMITIDA'));
        $cliente = trim((string) ($_REQUEST['cliente'] ?? ''));
        $rows = (new ReportRepository())->listNotas([
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => $status,
            'cliente' => $cliente,
        ], 5000);

        fputcsv($out, ['data', 'numero_nf', 'cliente', 'invoiceid', 'valor', 'status', 'chave_acesso'], ';');
        foreach ($rows as $r) {
            $dataRef = (string) ($r['emitida_em'] ?? $r['nfse_updated_at'] ?? '');
            fputcsv($out, [
                $dataRef,
                (string) ($r['numero_nf'] ?? ''),
                $this->resolveClientName($r),
                (string) ($r['invoiceid'] ?? ''),
                (string) ($r['invoice_total'] ?? ''),
                (string) ($r['status'] ?? ''),
                (string) ($r['chave_acesso'] ?? ''),
            ], ';');
        }
        fclose($out);
        exit;
    }


    public function exportRelatoriosZip(): void
    {
        $tab = trim((string) ($_REQUEST['tab'] ?? 'emitidas'));
        if (!in_array($tab, ['emitidas', 'cancelamentos'], true)) {
            $tab = 'emitidas';
        }

        if (!class_exists(\ZipArchive::class)) {
            throw new NfseModuleException('Extensão ZipArchive não disponível no PHP.');
        }

        $dataInicial = trim((string) ($_REQUEST['data_inicial'] ?? ''));
        $dataFinal = trim((string) ($_REQUEST['data_final'] ?? ''));
        $cliente = trim((string) ($_REQUEST['cliente'] ?? ''));
        $status = $tab === 'emitidas' ? trim((string) ($_REQUEST['status'] ?? 'EMITIDA')) : '';

        $filters = [
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => $status,
            'cliente' => $cliente,
        ];
        if ($tab === 'cancelamentos') {
            $filters['date_field'] = 'cancelado';
        }

        $rows = (new ReportRepository())->listNotas($filters, 5000);
        $storage = new StorageService();

        $tmpBase = tempnam(sys_get_temp_dir(), 'nfse_zip_');
        if ($tmpBase === false) {
            throw new NfseModuleException('Não foi possível preparar o arquivo ZIP.');
        }
        @unlink($tmpBase);
        $zipPath = $tmpBase . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new NfseModuleException('Não foi possível criar o arquivo ZIP.');
        }

        $added = 0;
        foreach ($rows as $row) {
            $xmlPath = trim((string) ($row['xml_path'] ?? ''));
            if ($xmlPath === '') {
                continue;
            }

            try {
                $absPath = $storage->resolveAbsolutePath($xmlPath);
            } catch (\Throwable $e) {
                continue;
            }

            if (!is_file($absPath)) {
                continue;
            }

            $entryName = basename($absPath);
            if ($zip->locateName($entryName) !== false) {
                $entryName = 'invoice_' . (int) ($row['invoiceid'] ?? 0) . '_' . $entryName;
            }

            if ($zip->addFile($absPath, $entryName)) {
                $added++;
            }
        }

        $zip->close();

        if ($added <= 0 || !is_file($zipPath)) {
            @unlink($zipPath);
            throw new NfseModuleException('Nenhum XML foi encontrado para exportação em lote.');
        }

        $filename = 'nfse_xml_' . $tab . '_' . date('Ymd_His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }


    public function showLogView(): void
    {
        $id = (int) ($_REQUEST['id'] ?? 0);
        if ($id <= 0) {
            Module::ui()->renderError('Log inválido.');
            return;
        }

        $row = Capsule::table('mod_opennfse_logs as l')
            ->leftJoin('mod_opennfse_notas as n', 'n.id', '=', 'l.nota_id')
            ->select([
                'l.id',
                'l.tipo',
                'l.created_at',
                'l.request',
                'l.response',
                'n.invoiceid as invoiceid',
            ])
            ->where('l.id', $id)
            ->first();

        if (!$row) {
            Module::ui()->renderError('Log não encontrado.');
            return;
        }

        Module::ui()->renderHeader('OpenNFS-e');
        $this->renderTabs('relatorios');

        $invoiceId = (int) ($row->invoiceid ?? 0);
        $back = 'addonmodules.php?module=OpenNfse&action=relatorios&tab=logs';
        echo '<div style="margin-bottom:10px;"><a class="btn btn-default" href="' . htmlspecialchars($back, ENT_QUOTES, 'UTF-8') . '">Voltar</a></div>';
        if ($invoiceId > 0) {
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            echo '<div style="margin-bottom:10px;">Invoice: <a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a></div>';
        }
        echo '<div style="margin-bottom:10px;">Tipo: <strong>' . htmlspecialchars((string) ($row->tipo ?? ''), ENT_QUOTES, 'UTF-8') . '</strong> &nbsp; Data: <strong>' . htmlspecialchars((string) ($row->created_at ?? ''), ENT_QUOTES, 'UTF-8') . '</strong></div>';

        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
        echo '<div style="flex:1;min-width:420px;">';
        echo '<div style="margin-bottom:6px;"><strong>Request</strong></div>';
        echo '<textarea readonly style="width:100%;height:320px;resize:none;white-space:pre-wrap;overflow:auto;overflow-wrap:anywhere;word-break:break-word;">' . htmlspecialchars((string) ($row->request ?? ''), ENT_QUOTES, 'UTF-8') . '</textarea>';
        echo '</div>';
        echo '<div style="flex:1;min-width:420px;">';
        echo '<div style="margin-bottom:6px;"><strong>Response</strong></div>';
        echo '<textarea readonly style="width:100%;height:320px;resize:none;white-space:pre-wrap;overflow:auto;overflow-wrap:anywhere;word-break:break-word;">' . htmlspecialchars((string) ($row->response ?? ''), ENT_QUOTES, 'UTF-8') . '</textarea>';
        echo '</div>';
        echo '</div>';

        Module::ui()->renderFooter();
    }


    public function showLogs(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            Module::ui()->renderError('Informe invoiceid.');
            return;
        }

        $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
        if (!$nota) {
            Module::ui()->renderError('Nota não encontrada para esta fatura.');
            return;
        }

        $notaId = (int) ($nota['id'] ?? 0);
        $rows = Capsule::table('mod_opennfse_logs')
            ->where('nota_id', $notaId)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        Module::ui()->renderHeader('OpenNFS-e');
        $this->renderTabs('notas');

        $back = 'addonmodules.php?module=OpenNfse&action=notas&invoiceid=' . $invoiceId;
        echo '<div style="margin-bottom:10px;"><a href="' . htmlspecialchars($back, ENT_QUOTES, 'UTF-8') . '">Voltar</a></div>';

        foreach ($rows as $r) {
            $tipo = (string) ($r->tipo ?? '');
            $created = (string) ($r->created_at ?? '');
            $req = (string) ($r->request ?? '');
            $resp = (string) ($r->response ?? '');

            echo '<div style="border:1px solid #ddd;padding:10px;margin-bottom:10px;background:#fff;">';
            echo '<div style="margin-bottom:6px;"><strong>' . htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8') . '</strong> <span style="color:#666;">' . htmlspecialchars($created, ENT_QUOTES, 'UTF-8') . '</span></div>';
            if ($req !== '') {
                echo '<div style="margin-bottom:6px;"><strong>Request</strong></div>';
                echo '<pre style="white-space:pre-wrap;word-break:break-word;">' . htmlspecialchars($req, ENT_QUOTES, 'UTF-8') . '</pre>';
            }
            if ($resp !== '') {
                echo '<div style="margin-bottom:6px;"><strong>Response</strong></div>';
                echo '<pre style="white-space:pre-wrap;word-break:break-word;">' . htmlspecialchars($resp, ENT_QUOTES, 'UTF-8') . '</pre>';
            }
            echo '</div>';
        }

        Module::ui()->renderFooter();
    }

}
