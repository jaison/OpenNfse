<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Module;
use OpenNfse\Repositories\ApiAuditRepository;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\DpsApiAuditRepository;
use OpenNfse\Repositories\FiscalHistoryRepository;
use OpenNfse\Repositories\GroupServiceCodeRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\PaymentGatewaySettingsRepository;
use OpenNfse\Repositories\QueueRepository;
use OpenNfse\Repositories\ReportRepository;
use OpenNfse\Repositories\ServiceNbsCatalogRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use OpenNfse\Repositories\WhmcsPaymentGatewayRepository;
use OpenNfse\Services\CryptoService;
use OpenNfse\Services\ApiAuditService;
use OpenNfse\Services\DpsApiAuditService;
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

    public function getAuditoriaDashboardSummary(?string $month = null, ?string $fromDate = null, ?string $toDate = null): array
    {
        $requestedMonth = trim((string) ($month ?? ''));
        $gatewayMap = $this->getAuditoriaActiveGatewayMap();
        $gatewayKeys = array_keys($gatewayMap);
        $dataInicial = trim((string) ($fromDate ?? ''));
        $dataFinal = trim((string) ($toDate ?? ''));
        $selectedMonth = '';

        if ($dataInicial === '' || $dataFinal === '') {
            [$selectedMonth, $dataInicial, $dataFinal] = $this->resolveAuditoriaMonthRange($requestedMonth !== '' ? $requestedMonth : date('Y-m'));
        }

        $filters = [
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'gateways' => $gatewayKeys,
        ];

        $summary = (new ReportRepository())->summaryAuditoriaInvoices($filters);

        return [
            'selected_month' => $selectedMonth,
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'has_gateways' => !empty($gatewayKeys),
            'total_invoices' => (int) ($summary['total_invoices'] ?? 0),
            'total_valor' => (float) ($summary['total_valor'] ?? 0),
        ];
    }

    public function showRelatorios(): void
    {
        $active = trim((string) ($_GET['tab'] ?? 'emitidas'));
        $isAjaxRequest = (string) ($_REQUEST['ajax'] ?? '') === '1';
        $tabMeta = [
            'emitidas' => [
                'label' => 'NFS-e Emitidas',
                'badge' => 'Fiscal',
                'badge_bg' => '#e8f5e9',
                'badge_color' => '#2e7d32',
                'summary' => 'Acompanha notas emitidas e canceladas, com totais e exportações do período.',
            ],
            'auditoria' => [
                'label' => 'Auditoria Emissão',
                'badge' => 'Conferência',
                'badge_bg' => '#e8f0fe',
                'badge_color' => '#23527c',
                'summary' => 'Lista faturas pagas em gateways ativos que não geraram NFS-e e também não entraram na fila.',
            ],
            'xml_auditoria' => [
                'label' => 'Auditoria XML',
                'badge' => 'Técnico',
                'badge_bg' => '#eef2f7',
                'badge_color' => '#5f6b7a',
                'summary' => 'Compara a pasta mensal de XMLs com o xml_path atual das notas para encontrar órfãos e referências inconsistentes.',
            ],
            'auditoria_api_dps' => [
                'label' => 'Auditoria DPS > API',
                'badge' => 'API',
                'badge_bg' => '#eaf7f1',
                'badge_color' => '#176b46',
                'summary' => 'Confere as DPS do período diretamente na API e verifica lacunas na sequência numérica do emissor.',
            ],
            'historico_fiscal' => [
                'label' => 'Historico Fiscal',
                'badge' => 'Fiscal',
                'badge_bg' => '#f3e8ff',
                'badge_color' => '#6b21a8',
                'summary' => 'Preserva os documentos e eventos fiscais da invoice ao longo do tempo, inclusive emissões, cancelamentos e snapshots de migracao.',
            ],
            'cancelamentos' => [
                'label' => 'Cancelamentos',
                'badge' => 'Auditoria',
                'badge_bg' => '#fff3e0',
                'badge_color' => '#a67c00',
                'summary' => 'Mostra cancelamentos do período com foco em conferência fiscal e contábil.',
            ],
            'falhas' => [
                'label' => 'Falhas',
                'badge' => 'Atenção',
                'badge_bg' => '#fff8e1',
                'badge_color' => '#8a6d3b',
                'summary' => 'Concentra erros de emissão, rejeições e problemas que exigem correção manual.',
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

        // Requests AJAX da auditoria DPS precisam sair antes do layout completo
        // para evitar contaminar a resposta JSON com o HTML do módulo.
        if ($isAjaxRequest && $active === 'auditoria_api_dps') {
            $this->showRelatorioAuditoriaApiDps(true);
            return;
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
        echo '.nfse-report-sidebar{width:280px;padding:14px;border:1px solid #dbe3ea;border-radius:10px;background:linear-gradient(180deg,#fbfcfe 0%,#f4f6f8 100%);box-shadow:0 2px 8px rgba(15,23,42,0.05);box-sizing:border-box;}';
        echo '.nfse-report-sidebar-summary{margin-top:2px;margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,0.04);}';
        echo '.nfse-report-sidebar-summary strong{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#5b6776;margin-bottom:6px;}';
        echo '.nfse-report-sidebar-summary span{display:block;font-size:13px;color:#334155;line-height:1.5;font-weight:600;}';
        echo '.nfse-report-sidebar-links{display:flex;flex-direction:column;gap:8px;}';
        echo '.nfse-report-sidebar-links a{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 12px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;text-decoration:none;color:#2c4778;box-shadow:0 1px 2px rgba(15,23,42,0.03);transition:all .15s ease;}';
        echo '.nfse-report-sidebar-links a:hover{background:#f8fafc;border-color:#cfd9e5;box-shadow:0 2px 6px rgba(15,23,42,0.06);transform:translateY(-1px);}';
        echo '.nfse-report-sidebar-links a.is-active{background:linear-gradient(180deg,#f7fbff 0%,#edf4fb 100%);border-color:#c8d8ea;color:#234b74;box-shadow:inset 0 0 0 1px #dbe7f3,0 2px 8px rgba(35,82,124,0.08);}';
        echo '.nfse-report-sidebar-link-label{display:block;font-size:13px;font-weight:600;line-height:1.35;}';
        echo '.nfse-report-sidebar-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:10px;font-weight:700;white-space:nowrap;letter-spacing:.03em;text-transform:uppercase;}';
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
            echo '<span class="nfse-report-sidebar-link-label">' . $h($label) . '</span>';
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
        } elseif ($active === 'auditoria') {
            $this->showRelatorioAuditoria(true);
        } elseif ($active === 'xml_auditoria') {
            $this->showRelatorioXmlAuditoria(true);
        } elseif ($active === 'historico_fiscal') {
            $this->showRelatorioHistoricoFiscal(true);
        } elseif ($active === 'auditoria_api_dps') {
            $this->showRelatorioAuditoriaApiDps(true);
        } elseif ($active === 'auditoria_api') {
            $this->showRelatorioAuditoriaApi(true);
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

        $msg = trim((string) ($_REQUEST['msg'] ?? ''));
        $invoiceFilter = trim((string) ($_REQUEST['invoiceid'] ?? ''));
        $dataInicial = trim((string) ($_REQUEST['data_inicial'] ?? ''));
        $dataFinal = trim((string) ($_REQUEST['data_final'] ?? ''));
        if ($dataInicial === '') {
            $dataInicial = date('Y-m-01');
        }
        if ($dataFinal === '') {
            $dataFinal = date('Y-m-d');
        }
        $status = trim((string) ($_REQUEST['status'] ?? 'EMITIDA,CANCELADA'));
        $cliente = trim((string) ($_REQUEST['cliente'] ?? ''));
        $perPage = (int) ($_REQUEST['per_page'] ?? 50);
        if (!in_array($perPage, [50, 100, 500], true)) {
            $perPage = 50;
        }
        $page = (int) ($_REQUEST['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        $filters = [
            'invoiceid' => $invoiceFilter,
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => $status,
            'cliente' => $cliente,
        ];

        $repo = new ReportRepository();
        $summary = $repo->summaryNotas($filters);
        $totalNotas = (int) ($summary['total_notas'] ?? 0);
        $totalPages = max(1, (int) ceil($totalNotas / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $rows = $repo->listNotas($filters, $perPage, $offset);

        if ($msg === 'status_done') {
            echo '<div class="successbox">Consulta de status executada.</div>';
        } elseif ($msg === 'status_error') {
            echo '<div class="errorbox">Erro ao consultar status. Verifique os logs do módulo.</div>';
        } elseif ($msg === 'reemitir_gateway_disabled') {
            echo '<div class="errorbox">Reemissão manual desativada para o gateway de pagamento desta fatura.</div>';
        } elseif ($msg === 'reemitir_enqueued') {
            echo '<div class="successbox">Reemissão manual enfileirada. O cron processará em breve.</div>';
        } elseif ($msg === 'reemitir_requested') {
            echo '<div class="successbox">Reemissão manual solicitada. Verifique o status e o XML na fatura.</div>';
        } elseif ($msg === 'reemitir_error') {
            echo '<div class="errorbox">Erro ao solicitar reemissão manual. Verifique os logs do módulo.</div>';
        } elseif ($msg === 'cancel_done') {
            echo '<div class="successbox">Cancelamento solicitado com sucesso.</div>';
        } elseif ($msg === 'cancel_error') {
            echo '<div class="errorbox">Erro ao cancelar NFS-e. Verifique os logs do módulo.</div>';
        }

        $recentCompetencias = $this->buildRecentCompetenciaZipPresets();
        if (!empty($recentCompetencias)) {
            echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #d7eadc;border-radius:8px;background:linear-gradient(180deg,#fbfefb 0%,#f2f9f4 100%);">';
            echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
            echo '<div style="flex:1 1 420px;">';
            echo '<div style="font-size:11px;color:#5f7a67;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Exportação por competência</div>';
            echo '<div style="font-size:15px;font-weight:700;color:#1f4d30;margin-bottom:4px;">Baixar XMLs por competência</div>';
            echo '<div style="font-size:12px;color:#4f6b57;line-height:1.5;">Atalhos rápidos para baixar os XMLs ZIPados dos 3 últimos meses fechados separadamente, considerando todas as notas Emitidas e Canceladas.</div>';
            echo '</div>';
            echo '<div style="min-width:420px;flex:1 1 520px;padding:12px 14px;border:1px solid #bfe0c7;border-radius:8px;background:#ffffff;box-shadow:0 2px 8px rgba(22,101,52,0.08);">';
            echo '<div style="font-size:11px;color:#176b46;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Últimos 3 períodos fechados</div>';
            echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
            foreach ($recentCompetencias as $preset) {
                echo $this->renderPostActionButton(
                    'relatoriosExportZip',
                    (string) ($preset['label'] ?? ''),
                    [
                        'tab' => 'emitidas',
                        'invoiceid' => $invoiceFilter,
                        'data_inicial' => (string) ($preset['data_inicial'] ?? ''),
                        'data_final' => (string) ($preset['data_final'] ?? ''),
                        'status' => 'EMITIDA,CANCELADA',
                        'cliente' => '',
                        'zip_layout' => 'competencia',
                    ],
                    'btn btn-success'
                );
            }
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="emitidas" />';
        echo '<input type="hidden" name="per_page" value="' . htmlspecialchars((string) $perPage, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;flex:1 1 860px;">';
        echo '<div style="min-width:130px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Invoice ID</div>';
        echo '<input type="text" name="invoiceid" value="' . htmlspecialchars($invoiceFilter, ENT_QUOTES, 'UTF-8') . '" style="width:130px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="min-width:160px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Data inicial</div>';
        echo '<input type="date" name="data_inicial" value="' . htmlspecialchars($dataInicial, ENT_QUOTES, 'UTF-8') . '" style="width:160px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="min-width:160px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Data final</div>';
        echo '<input type="date" name="data_final" value="' . htmlspecialchars($dataFinal, ENT_QUOTES, 'UTF-8') . '" style="width:160px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="min-width:220px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Status</div>';
        echo '<select name="status" style="width:220px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;">';
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
        echo '<div style="flex:1 1 320px;min-width:320px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Cliente</div>';
        echo '<input type="text" name="cliente" placeholder="Cliente (ID ou nome)" value="' . htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8') . '" style="width:100%;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas">Limpar</a>';
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

        if (empty($rows)) {
            echo '<tr><td colspan="6" style="text-align:center;color:#666;">Nenhuma nota encontrada para os critérios selecionados.</td></tr>';
        }

        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            $clienteNome = $this->resolveClientName($row);
            $numeroNfse = (string) ($row['numero_nf'] ?? '');
            $statusRow = (string) ($row['status'] ?? '');
            $dataRef = (string) ($row['reference_date'] ?? $row['emitida_em'] ?? $row['nfse_updated_at'] ?? '');
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

        echo '<div style="margin-top:12px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px;background:linear-gradient(180deg,#fbfcfe 0%,#f5f7fa 100%);">';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:stretch;">';
        echo '<div style="min-width:180px;flex:1 1 180px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;">';
        echo '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Total de notas</div>';
        echo '<div style="font-size:17px;font-weight:700;color:#1f2937;">' . (int) ($summary['total_notas'] ?? 0) . '</div>';
        echo '</div>';
        $statusParts = array_values(array_filter(array_map('trim', explode(',', $status)), static fn (string $v): bool => $v !== ''));
        $showSplitTotals = count($statusParts) === 2 && in_array('EMITIDA', $statusParts, true) && in_array('CANCELADA', $statusParts, true);
        if ($showSplitTotals) {
            $filtersEmitidas = $filters;
            $filtersEmitidas['status'] = 'EMITIDA';
            $filtersCanceladas = $filters;
            $filtersCanceladas['status'] = 'CANCELADA';
            $sumEmitidas = $repo->summaryNotas($filtersEmitidas);
            $sumCanceladas = $repo->summaryNotas($filtersCanceladas);
            echo '<div style="min-width:240px;flex:1 1 240px;padding:10px 12px;border:1px solid #d7eadc;border-radius:6px;background:#fff;">';
            echo '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Valor Emitidas</div>';
            echo '<div style="font-size:17px;font-weight:700;color:#166534;">' . htmlspecialchars($this->formatMoney((float) ($sumEmitidas['total_valor'] ?? 0), 'R$ ', ''), ENT_QUOTES, 'UTF-8') . '</div>';
            echo '</div>';
            echo '<div style="min-width:240px;flex:1 1 240px;padding:10px 12px;border:1px solid #eadfd7;border-radius:6px;background:#fff;">';
            echo '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Valor Canceladas</div>';
            echo '<div style="font-size:17px;font-weight:700;color:#9a3412;">' . htmlspecialchars($this->formatMoney((float) ($sumCanceladas['total_valor'] ?? 0), 'R$ ', ''), ENT_QUOTES, 'UTF-8') . '</div>';
            echo '</div>';
        } else {
            echo '<div style="min-width:240px;flex:1 1 240px;padding:10px 12px;border:1px solid #dbe5f0;border-radius:6px;background:#fff;">';
            echo '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Valor total</div>';
            echo '<div style="font-size:17px;font-weight:700;color:#1d4ed8;">' . htmlspecialchars($this->formatMoney((float) ($summary['total_valor'] ?? 0), 'R$ ', ''), ENT_QUOTES, 'UTF-8') . '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
        echo '<div style="margin-top:10px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
        $firstItem = $totalNotas > 0 ? ($offset + 1) : 0;
        $lastItem = min($offset + count($rows), $totalNotas);
        echo '<div style="font-size:14px;color:#374151;">Exibindo <strong style="color:#111827;">' . $firstItem . '-' . $lastItem . '</strong> de <strong style="color:#111827;">' . $totalNotas . '</strong> registros</div>';

        $baseParams = [
            'module' => 'OpenNfse',
            'action' => 'relatorios',
            'tab' => 'emitidas',
            'invoiceid' => $invoiceFilter,
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => $status,
            'cliente' => $cliente,
            'per_page' => $perPage,
        ];

        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
        if ($page > 1) {
            $prevParams = $baseParams;
            $prevParams['page'] = $page - 1;
            echo '<a class="btn btn-xs btn-default" style="min-width:82px;" href="addonmodules.php?' . htmlspecialchars(http_build_query($prevParams, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Anterior</a>';
        }
        echo '<span style="display:inline-flex;align-items:center;padding:6px 10px;border:1px solid #d1d5db;border-radius:999px;background:#f9fafb;font-size:13px;font-weight:600;color:#374151;">Página ' . $page . ' / ' . $totalPages . '</span>';
        if ($page < $totalPages) {
            $nextParams = $baseParams;
            $nextParams['page'] = $page + 1;
            echo '<a class="btn btn-xs btn-default" style="min-width:82px;" href="addonmodules.php?' . htmlspecialchars(http_build_query($nextParams, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Próxima</a>';
        }
        echo '</div>';
        echo '</div>';
        echo '<div style="margin-top:10px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;">';
        echo '<form method="get" action="addonmodules.php" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:0;">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="emitidas" />';
        echo '<input type="hidden" name="invoiceid" value="' . htmlspecialchars($invoiceFilter, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<input type="hidden" name="data_inicial" value="' . htmlspecialchars($dataInicial, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<input type="hidden" name="data_final" value="' . htmlspecialchars($dataFinal, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<input type="hidden" name="status" value="' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<input type="hidden" name="cliente" value="' . htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<div style="min-width:190px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Página</div>';
        echo '<select name="page" style="min-width:190px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;">';
        for ($pageOption = 1; $pageOption <= $totalPages; $pageOption++) {
            $selectedPage = $pageOption === $page ? ' selected' : '';
            echo '<option value="' . $pageOption . '"' . $selectedPage . '>Página ' . $pageOption . ' de ' . $totalPages . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div style="min-width:160px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Registros</div>';
        echo '<select name="per_page" style="min-width:160px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;">';
        foreach ([50, 100, 500] as $perPageOption) {
            $selectedPerPage = $perPageOption === $perPage ? ' selected' : '';
            echo '<option value="' . $perPageOption . '"' . $selectedPerPage . '>' . $perPageOption . ' por página</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Aplicar</button>';
        echo '</form>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo $this->renderPostActionButton('relatoriosExportZip', 'Baixar XMLs do período escolhido', [
            'tab' => 'emitidas',
            'invoiceid' => $invoiceFilter,
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => $status,
            'cliente' => $cliente,
        ], 'btn btn-xs btn-default');
        echo $this->renderPostActionButton('relatoriosExport', 'Exportar CSV', [
            'tab' => 'emitidas',
            'invoiceid' => $invoiceFilter,
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => $status,
            'cliente' => $cliente,
        ], 'btn btn-xs btn-default');
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
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;flex:1 1 620px;">';
        echo '<div style="min-width:160px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Data inicial</div>';
        echo '<input type="date" name="data_inicial" value="' . htmlspecialchars($dataInicial, ENT_QUOTES, 'UTF-8') . '" style="width:160px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="min-width:160px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Data final</div>';
        echo '<input type="date" name="data_final" value="' . htmlspecialchars($dataFinal, ENT_QUOTES, 'UTF-8') . '" style="width:160px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="flex:1 1 320px;min-width:320px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Cliente</div>';
        echo '<input type="text" name="cliente" placeholder="Cliente (ID ou nome)" value="' . htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8') . '" style="width:100%;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
        echo '<div style="margin-bottom:12px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;">';
        echo $this->renderPostActionButton('relatoriosExport', 'Exportar CSV', [
            'tab' => 'falhas',
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'cliente' => $cliente,
        ], 'btn btn-xs btn-default');
        echo '</div>';

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
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;flex:1 1 620px;">';
        echo '<div style="min-width:160px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Data inicial</div>';
        echo '<input type="date" name="data_inicial" value="' . htmlspecialchars($dataInicial, ENT_QUOTES, 'UTF-8') . '" style="width:160px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="min-width:160px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Data final</div>';
        echo '<input type="date" name="data_final" value="' . htmlspecialchars($dataFinal, ENT_QUOTES, 'UTF-8') . '" style="width:160px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="flex:1 1 320px;min-width:320px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Cliente</div>';
        echo '<input type="text" name="cliente" placeholder="Cliente (ID ou nome)" value="' . htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8') . '" style="width:100%;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=cancelamentos">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
        echo '<div style="margin-bottom:12px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;">';
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


    public function showRelatorioAuditoria(bool $embedded = false): void
    {
        if (!$embedded) {
            $this->redirectRelatorios('auditoria');
        }

        $requestedMonth = trim((string) ($_REQUEST['mes'] ?? ''));
        $gatewayMap = $this->getAuditoriaActiveGatewayMap();
        $gatewayKeys = array_keys($gatewayMap);

        [$selectedMonth, $dataInicial, $dataFinal] = $this->resolveAuditoriaMonthRange($requestedMonth !== '' ? $requestedMonth : date('Y-m'));

        $repo = new ReportRepository();
        $filters = [
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'gateways' => $gatewayKeys,
        ];
        $rows = $repo->listAuditoriaInvoices($filters, 200);

        $summary = $repo->summaryAuditoriaInvoices($filters);
        $periodLabel = $this->formatAuditoriaMonthLabel($selectedMonth);

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="auditoria" />';
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">';
        echo '<div style="min-width:190px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Mês de referência</div>';
        echo '<input type="month" name="mes" value="' . htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8') . '" style="width:190px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=auditoria">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
        if (empty($gatewayKeys)) {
            echo '<div class="alert alert-warning" style="margin-bottom:12px;">Nenhum gateway ativo e habilitado no addon foi encontrado para a auditoria.</div>';
        } else {
            $notice = 'Exibindo o período de ' . htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') . ' para conferir faturas pagas que não foram emitidas NFS-e. Revise e emita manualmente cada uma delas se for o caso.';
            echo '<div style="margin-bottom:14px;padding:14px 16px;border:1px solid #efd6d6;border-radius:8px;background:linear-gradient(180deg,#fffafa 0%,#fff4f4 100%);box-shadow:0 2px 8px rgba(127,29,29,0.06);">';
            echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
            echo '<div style="font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#9f3a38;">Atenção crítica</div>';
            echo '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:#fdeaea;color:#9f3a38;font-size:10px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;white-space:nowrap;">Revisão manual</span>';
            echo '</div>';
            echo '<div style="margin-top:8px;font-size:13px;line-height:1.55;font-weight:600;color:#7f1d1d;">' . $notice . '</div>';
            echo '</div>';
        }

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:12%;">Fatura</th>';
        echo '<th style="width:34%;">Cliente</th>';
        echo '<th style="width:20%;">Pagamento</th>';
        echo '<th style="width:18%;">Gateway</th>';
        echo '<th style="width:16%;">Valor</th>';
        echo '</tr>';

        if (empty($rows)) {
            echo '<tr><td colspan="5" style="text-align:center;color:#666;">Nenhuma fatura encontrada para os critérios selecionados.</td></tr>';
        }

        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoice_id'] ?? 0);
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            $clienteNome = $this->resolveClientName($row);
            $paidAt = $this->formatDate((string) ($row['paid_at'] ?? ''), 'd/m/Y H:i');
            $gatewayKey = strtolower(trim((string) ($row['paymentmethod'] ?? '')));
            $gatewayLabel = $gatewayMap[$gatewayKey] ?? ($gatewayKey !== '' ? $gatewayKey : '-');
            $valor = (float) ($row['invoice_total'] ?? 0);
            $prefix = (string) ($row['currency_prefix'] ?? 'R$ ');
            $suffix = (string) ($row['currency_suffix'] ?? '');

            echo '<tr>';
            echo '<td><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a></td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($clienteNome, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($paidAt, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($gatewayLabel, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($this->formatMoney($valor, $prefix, $suffix), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }

        echo '</table>';
        echo '<div style="margin-top:12px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px;background:linear-gradient(180deg,#fbfcfe 0%,#f5f7fa 100%);">';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:stretch;">';
        echo '<div style="min-width:220px;flex:1 1 220px;padding:10px 12px;border:1px solid #dbe5f0;border-radius:6px;background:#fff;">';
        echo '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Total de faturas</div>';
        echo '<div style="font-size:17px;font-weight:700;color:#1f2937;">' . (int) ($summary['total_invoices'] ?? 0) . '</div>';
        echo '</div>';
        echo '<div style="min-width:240px;flex:1 1 240px;padding:10px 12px;border:1px solid #d7eadc;border-radius:6px;background:#fff;">';
        echo '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Valor total</div>';
        echo '<div style="font-size:17px;font-weight:700;color:#176b46;">' . htmlspecialchars($this->formatMoney((float) ($summary['total_valor'] ?? 0), 'R$ ', ''), ENT_QUOTES, 'UTF-8') . '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }


    public function showRelatorioXmlAuditoria(bool $embedded = false): void
    {
        if (!$embedded) {
            $this->redirectRelatorios('xml_auditoria');
        }

        $requestedMonth = trim((string) ($_REQUEST['mes'] ?? ''));
        $defaultMonth = $this->getXmlAuditDefaultMonth();
        [$selectedMonth] = $this->resolveAuditoriaMonthRange($requestedMonth !== '' ? $requestedMonth : $defaultMonth);

        $audit = $this->buildXmlAuditData($selectedMonth);
        $periodLabel = $this->formatAuditoriaMonthLabel($selectedMonth);
        $issueCount = (int) ($audit['orphan_count'] ?? 0)
            + (int) ($audit['missing_reference_count'] ?? 0)
            + (int) ($audit['unexpected_status_count'] ?? 0)
            + (int) ($audit['duplicate_reference_count'] ?? 0);
        $flashMessage = trim((string) ($_GET['msg'] ?? ''));

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="xml_auditoria" />';
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">';
        echo '<div style="min-width:190px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Mês de referência</div>';
        echo '<input type="month" name="mes" value="' . htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8') . '" style="width:190px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Auditar</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=xml_auditoria">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fbfcfe 0%,#f5f8fb 100%);">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">Escopo da auditoria</div>';
        echo '<div style="font-size:12px;color:#5b6776;line-height:1.55;">';
        echo 'Comparando a pasta <strong>' . htmlspecialchars((string) ($audit['relative_dir'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</strong> referente a <strong>' . htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') . '</strong> ';
        echo 'com o campo <code>xml_path</code> atual da base. Ambiente: <strong>' . htmlspecialchars((string) ($audit['environment_label'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</strong> ';
        echo '&nbsp; Serie: <strong>' . htmlspecialchars((string) ($audit['serie_label'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</strong>. ';
        echo 'Arquivos <code>cancelamento_nfse_*</code> sao contabilizados separadamente e nao entram como orfaos tecnicos.';
        echo '</div>';
        echo '</div>';

        $metricCards = [
            ['label' => 'Arquivos no disco', 'value' => (int) ($audit['physical_count'] ?? 0), 'color' => '#23527c'],
            ['label' => 'Cancelamentos no disco', 'value' => (int) ($audit['cancelamento_file_count'] ?? 0), 'color' => '#a67c00'],
            ['label' => 'XMLs referenciados', 'value' => (int) ($audit['db_reference_count'] ?? 0), 'color' => '#2e7d32'],
            ['label' => 'Orfaos no disco', 'value' => (int) ($audit['orphan_count'] ?? 0), 'color' => '#b45f06'],
            ['label' => 'Referencias quebradas', 'value' => (int) ($audit['missing_reference_count'] ?? 0), 'color' => '#a94442'],
            ['label' => 'Status inconsistente', 'value' => (int) ($audit['unexpected_status_count'] ?? 0), 'color' => '#8a6d3b'],
            ['label' => 'Referencias duplicadas', 'value' => (int) ($audit['duplicate_reference_count'] ?? 0), 'color' => '#6a1b9a'],
        ];
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px;">';
        foreach ($metricCards as $card) {
            echo '<div style="border:1px solid #dbe3ea;border-radius:8px;background:#fff;padding:12px 14px;box-shadow:0 1px 2px rgba(15,23,42,0.04);">';
            echo '<div style="font-size:11px;color:#667085;text-transform:uppercase;letter-spacing:.02em;margin-bottom:6px;">' . htmlspecialchars((string) $card['label'], ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<div style="font-size:26px;line-height:1.1;font-weight:700;color:' . htmlspecialchars((string) $card['color'], ENT_QUOTES, 'UTF-8') . ';">' . (int) $card['value'] . '</div>';
            echo '</div>';
        }
        echo '</div>';

        if ($issueCount > 0) {
            echo '<div class="alert alert-warning" style="margin-bottom:14px;">Foram encontradas <strong>' . $issueCount . '</strong> divergencias tecnicas entre filesystem e base para este mes.</div>';
        } else {
            echo '<div class="alert alert-success" style="margin-bottom:14px;">Nenhuma divergencia tecnica foi encontrada entre a pasta mensal e o xml_path atual das notas.</div>';
        }
        if ($flashMessage === 'orphan_cancel_done') {
            echo '<div class="alert alert-success" style="margin-bottom:14px;">A NFS-e órfã foi cancelada e o evento foi registrado no histórico fiscal.</div>';
        }

        $statusSummary = $this->buildXmlAuditStatusSummary((array) ($audit['status_counts'] ?? []));
        if ($statusSummary !== '') {
            echo '<div style="margin-bottom:14px;font-size:12px;color:#5b6776;">Status atuais das notas que apontam para esta pasta: ' . htmlspecialchars($statusSummary, ENT_QUOTES, 'UTF-8') . '.</div>';
        }

        $unexpectedRows = (array) ($audit['unexpected_status_rows'] ?? []);
        echo '<div style="margin-bottom:16px;">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;">Notas com XML salvo e status diferente de Emitida/Cancelada</div>';
        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:10%;">Data</th>';
        echo '<th style="width:10%;">Invoice</th>';
        echo '<th style="width:26%;">Cliente</th>';
        echo '<th style="width:12%;">Status</th>';
        echo '<th style="width:12%;">NFS-e</th>';
        echo '<th style="width:30%;">xml_path</th>';
        echo '</tr>';
        if (empty($unexpectedRows)) {
            echo '<tr><td colspan="6" style="text-align:center;color:#666;">Nenhuma nota inconsistente encontrada.</td></tr>';
        }
        foreach ($unexpectedRows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            $dataRef = (string) ($row['reference_date'] ?? $row['emitida_em'] ?? $row['nfse_updated_at'] ?? '');
            echo '<tr>';
            echo '<td>' . htmlspecialchars($this->formatDate($dataRef, 'd/m/Y'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a></td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($this->resolveClientName($row), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['status'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) (($row['numero_nf'] ?? '') !== '' ? $row['numero_nf'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) ($row['xml_path_normalized'] ?? $row['xml_path'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';

        $missingRows = (array) ($audit['missing_reference_rows'] ?? []);
        echo '<div style="margin-bottom:16px;">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;">Referencias quebradas na base</div>';
        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:10%;">Data</th>';
        echo '<th style="width:10%;">Invoice</th>';
        echo '<th style="width:26%;">Cliente</th>';
        echo '<th style="width:12%;">Status</th>';
        echo '<th style="width:12%;">NFS-e</th>';
        echo '<th style="width:30%;">xml_path</th>';
        echo '</tr>';
        if (empty($missingRows)) {
            echo '<tr><td colspan="6" style="text-align:center;color:#666;">Nenhuma referencia quebrada encontrada.</td></tr>';
        }
        foreach ($missingRows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            $dataRef = (string) ($row['reference_date'] ?? $row['emitida_em'] ?? $row['nfse_updated_at'] ?? '');
            echo '<tr>';
            echo '<td>' . htmlspecialchars($this->formatDate($dataRef, 'd/m/Y'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a></td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($this->resolveClientName($row), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['status'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) (($row['numero_nf'] ?? '') !== '' ? $row['numero_nf'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) ($row['xml_path_normalized'] ?? $row['xml_path'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';

        $orphanFiles = (array) ($audit['orphan_files'] ?? []);
        echo '<div style="margin-bottom:16px;">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;">Arquivos orfaos no disco</div>';
        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:22%;">Arquivo</th>';
        echo '<th style="width:40%;">Caminho</th>';
        echo '<th style="width:13%;">Modificado em</th>';
        echo '<th style="width:10%;">Tamanho</th>';
        echo '<th style="width:15%;">Ação</th>';
        echo '</tr>';
        if (empty($orphanFiles)) {
            echo '<tr><td colspan="5" style="text-align:center;color:#666;">Nenhum arquivo orfao encontrado.</td></tr>';
        }
        foreach ($orphanFiles as $file) {
            $xmlPath = (string) ($file['relative_path'] ?? '');
            echo '<tr>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) ($file['filename'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($xmlPath !== '' ? $xmlPath : '-', ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($file['modified_at'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($this->formatAuditBytes((int) ($file['size'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>';
            if ($xmlPath !== '') {
                echo '<form method="get" action="addonmodules.php" style="margin:0;">';
                echo '<input type="hidden" name="module" value="OpenNfse" />';
                echo '<input type="hidden" name="action" value="cancelOrphanXmlForm" />';
                echo '<input type="hidden" name="xml_path" value="' . htmlspecialchars($xmlPath, ENT_QUOTES, 'UTF-8') . '" />';
                echo '<input type="hidden" name="mes" value="' . htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8') . '" />';
                echo '<button type="submit" class="btn btn-xs btn-warning">Cancelar</button>';
                echo '</form>';
            } else {
                echo '-';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';

        $duplicateReferences = (array) ($audit['duplicate_references'] ?? []);
        echo '<div style="margin-bottom:16px;">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;">Referencias duplicadas ao mesmo xml_path</div>';
        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:46%;">xml_path</th>';
        echo '<th style="width:14%;">Ocorrencias</th>';
        echo '<th style="width:40%;">Invoices</th>';
        echo '</tr>';
        if (empty($duplicateReferences)) {
            echo '<tr><td colspan="3" style="text-align:center;color:#666;">Nenhuma referencia duplicada encontrada.</td></tr>';
        }
        foreach ($duplicateReferences as $duplicate) {
            $invoiceLinks = [];
            foreach ((array) ($duplicate['rows'] ?? []) as $row) {
                $invoiceId = (int) ($row['invoiceid'] ?? 0);
                if ($invoiceId <= 0) {
                    continue;
                }
                $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
                $invoiceLinks[] = '<a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a>';
            }

            echo '<tr>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) ($duplicate['xml_path'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . (int) ($duplicate['count'] ?? 0) . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . (!empty($invoiceLinks) ? implode(', ', $invoiceLinks) : '-') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';
    }

    public function showCancelOrphanXmlForm(): void
    {
        $xmlPath = trim((string) ($_REQUEST['xml_path'] ?? ''));
        if ($xmlPath === '') {
            Module::ui()->renderError('XML órfão inválido.');
            return;
        }

        try {
            $context = $this->buildOrphanXmlCancelContext($xmlPath);
        } catch (\Throwable $e) {
            Module::ui()->renderError('Erro ao preparar cancelamento do XML órfão: ' . $e->getMessage());
            return;
        }

        Module::ui()->renderHeader('Cancelar NFS-e órfã');
        $this->renderTabs('relatorios');

        $selectedMonth = trim((string) ($_REQUEST['mes'] ?? ''));
        $backUrl = 'addonmodules.php?module=OpenNfse&action=relatorios&tab=xml_auditoria';
        if ($selectedMonth !== '') {
            $backUrl .= '&mes=' . rawurlencode($selectedMonth);
        }

        $token = (new TokenService())->token();

        echo '<div style="margin-bottom:10px;"><a class="btn btn-default" href="' . htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') . '">Voltar para Auditoria XML</a></div>';
        echo '<div class="alert alert-warning" style="margin-bottom:14px;">Esta ação cancela a NFS-e representada pelo XML órfão, sem alterar automaticamente a nota operacional atual da invoice.</div>';

        echo '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3" style="margin-bottom:14px;">';
        echo '<tr><td class="fieldlabel" width="25%">Arquivo</td><td class="fieldarea">' . htmlspecialchars((string) ($context['filename'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        echo '<tr><td class="fieldlabel">Caminho</td><td class="fieldarea" style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) ($context['xml_path'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        echo '<tr><td class="fieldlabel">Invoice</td><td class="fieldarea">' . htmlspecialchars((string) (($context['invoiceid'] ?? 0) > 0 ? (string) $context['invoiceid'] : '-'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        echo '<tr><td class="fieldlabel">NFS-e</td><td class="fieldarea">' . htmlspecialchars((string) (($context['numero_nf'] ?? '') !== '' ? $context['numero_nf'] : '-'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        echo '<tr><td class="fieldlabel">ID DPS</td><td class="fieldarea" style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) (($context['id_dps'] ?? '') !== '' ? $context['id_dps'] : '-'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        echo '<tr><td class="fieldlabel">Chave de acesso</td><td class="fieldarea" style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) ($context['chave_acesso'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        echo '<tr><td class="fieldlabel">Emitida em</td><td class="fieldarea">' . htmlspecialchars($this->formatDate((string) ($context['emitida_em'] ?? ''), 'd/m/Y H:i:s'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        echo '</table>';

        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=cancelOrphanXml">';
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
        }
        echo '<input type="hidden" name="xml_path" value="' . htmlspecialchars((string) ($context['xml_path'] ?? ''), ENT_QUOTES, 'UTF-8') . '" />';
        if ($selectedMonth !== '') {
            echo '<input type="hidden" name="mes" value="' . htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8') . '" />';
        }
        echo '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">';
        echo '<tr><td class="fieldlabel" width="25%">Código do motivo</td><td class="fieldarea"><select id="codigo_motivo" name="codigo_motivo" class="form-control">';
        echo '<option value="1">1 - Erro na emissão da NFS-e (será emitida outra nota correta)</option>';
        echo '<option value="2">2 - Serviço não prestado / cancelamento da prestação</option>';
        echo '<option value="9" selected>9 - NFS-e emitida indevidamente</option>';
        echo '</select></td></tr>';
        echo '<tr><td class="fieldlabel" width="25%">Motivo</td><td class="fieldarea"><input id="motivo" type="text" name="motivo" class="form-control" value="Emissão indevida" /></td></tr>';
        echo '<tr><td class="fieldlabel" width="25%">Descrição</td><td class="fieldarea"><input id="descricao" type="text" name="descricao" class="form-control" value="NFS-e cancelada por ter sido emitida indevidamente, pois outra nota fiscal já foi emitida corretamente para a mesma operação." /></td></tr>';
        echo '</table>';
        echo '<script>';
        echo '(function(){';
        echo 'var map={';
        echo '"1":{m:"Erro na emissão",d:"NFS-e cancelada em razão de erro identificado na emissão. Será emitida nova nota fiscal com os dados corretos."},';
        echo '"2":{m:"Serviço não prestado",d:"NFS-e cancelada porque o serviço não foi prestado ao tomador, não gerando efeitos fiscais para a operação."},';
        echo '"9":{m:"Emissão indevida",d:"NFS-e cancelada por ter sido emitida indevidamente, pois outra nota fiscal já foi emitida corretamente para a mesma operação."}';
        echo '};';
        echo 'var sel=document.getElementById("codigo_motivo");';
        echo 'var motivo=document.getElementById("motivo");';
        echo 'var desc=document.getElementById("descricao");';
        echo 'if(!sel||!motivo||!desc){return;}';
        echo 'function apply(){var v=sel.value; if(!map[v]){return;} motivo.value=map[v].m; desc.value=map[v].d;}';
        echo 'sel.addEventListener("change", apply);';
        echo '})();';
        echo '</script>';
        echo '<p><button type="submit" class="btn btn-danger" onclick="return confirm(\'Cancelar esta NFS-e órfã é uma operação fiscal. Continuar?\');">Cancelar NFS-e órfã</button></p>';
        echo '</form>';

        Module::ui()->renderFooter();
    }

    public function cancelOrphanXml(): void
    {
        $xmlPath = trim((string) ($_REQUEST['xml_path'] ?? ''));
        $codigo = trim((string) ($_REQUEST['codigo_motivo'] ?? ''));
        $motivo = trim((string) ($_REQUEST['motivo'] ?? ''));
        $descricao = trim((string) ($_REQUEST['descricao'] ?? ''));
        if ($xmlPath === '' || $codigo === '' || $motivo === '' || $descricao === '') {
            Module::ui()->renderError('Preencha os dados do cancelamento da NFS-e órfã.');
            return;
        }

        try {
            (new NfseService())->cancelarNfseOrfaPorXmlPath($xmlPath, $codigo, $motivo, $descricao);
        } catch (\Throwable $e) {
            Module::ui()->renderError('Erro ao cancelar NFS-e órfã: ' . $e->getMessage());
            return;
        }

        $url = 'addonmodules.php?module=OpenNfse&action=relatorios&tab=xml_auditoria&msg=orphan_cancel_done';
        $selectedMonth = trim((string) ($_REQUEST['mes'] ?? ''));
        if ($selectedMonth !== '') {
            $url .= '&mes=' . rawurlencode($selectedMonth);
        }
        header('Location: ' . $url);
        exit;
    }


    public function showRelatorioHistoricoFiscal(bool $embedded = false): void
    {
        if (!$embedded) {
            $this->redirectRelatorios('historico_fiscal');
        }

        $this->ensureFiscalHistoryReady();

        $requestedMonth = trim((string) ($_REQUEST['mes'] ?? ''));
        $tipoRegistro = strtoupper(trim((string) ($_REQUEST['tipo_registro'] ?? '')));
        $invoiceFilter = trim((string) ($_REQUEST['invoiceid'] ?? ''));
        $page = (int) ($_REQUEST['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        $defaultMonth = $this->getXmlAuditDefaultMonth();
        [$selectedMonth] = $this->resolveAuditoriaMonthRange($requestedMonth !== '' ? $requestedMonth : $defaultMonth);

        $filters = [
            'month' => $selectedMonth,
            'tipo_registro' => $tipoRegistro,
            'invoiceid' => $invoiceFilter,
        ];

        $repo = new FiscalHistoryRepository();
        $summary = $repo->summaryHistory($filters);
        $perPage = 1000;
        $totalDocs = (int) ($summary['total_docs'] ?? 0);
        $totalPages = max(1, (int) ceil($totalDocs / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $rows = $repo->listHistory($filters, $perPage, $offset);

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="historico_fiscal" />';
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;flex:1 1 640px;">';
        echo '<div style="min-width:190px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Mês de referência</div>';
        echo '<input type="month" name="mes" value="' . htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8') . '" style="width:190px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="min-width:190px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Tipo</div>';
        echo '<select name="tipo_registro" style="width:190px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;">';
        $typeOptions = [
            '' => 'Todos os registros',
            'EMISSAO' => 'Emissão',
            'CANCELAMENTO' => 'Cancelamento',
            'SNAPSHOT' => 'Snapshot legado',
        ];
        foreach ($typeOptions as $value => $label) {
            $selected = $value === $tipoRegistro ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div style="min-width:150px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Invoice ID</div>';
        echo '<input type="text" name="invoiceid" value="' . htmlspecialchars($invoiceFilter, ENT_QUOTES, 'UTF-8') . '" style="width:150px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=historico_fiscal">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px;">';
        $historyCards = [
            ['label' => 'Documentos no periodo', 'value' => (int) ($summary['total_docs'] ?? 0), 'color' => '#23527c'],
            ['label' => 'Emissoes', 'value' => (int) ($summary['total_emissoes'] ?? 0), 'color' => '#2e7d32'],
            ['label' => 'Cancelamentos', 'value' => (int) ($summary['total_cancelamentos'] ?? 0), 'color' => '#a67c00'],
            ['label' => 'Snapshots legado', 'value' => (int) ($summary['total_snapshots'] ?? 0), 'color' => '#6b7280'],
        ];
        foreach ($historyCards as $card) {
            echo '<div style="border:1px solid #dbe3ea;border-radius:8px;background:#fff;padding:12px 14px;box-shadow:0 1px 2px rgba(15,23,42,0.04);">';
            echo '<div style="font-size:11px;color:#667085;text-transform:uppercase;letter-spacing:.02em;margin-bottom:6px;">' . htmlspecialchars((string) $card['label'], ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<div style="font-size:26px;line-height:1.1;font-weight:700;color:' . htmlspecialchars((string) $card['color'], ENT_QUOTES, 'UTF-8') . ';">' . (int) $card['value'] . '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:12%;">Data</th>';
        echo '<th style="width:10%;">Tipo</th>';
        echo '<th style="width:10%;">Status</th>';
        echo '<th style="width:9%;">NFS-e</th>';
        echo '<th style="width:23%;">Cliente</th>';
        echo '<th style="width:8%;">Invoice</th>';
        echo '<th style="width:10%;">Valor</th>';
        echo '<th style="width:9%;">Origem</th>';
        echo '<th style="width:9%;">XMLs</th>';
        echo '</tr>';

        if (empty($rows)) {
            echo '<tr><td colspan="9" style="text-align:center;color:#666;">Nenhum registro fiscal encontrado para os critérios selecionados.</td></tr>';
        }

        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            $eventDate = $this->resolveFiscalHistoryEventDate($row);
            $xmlBadges = [];
            if (trim((string) ($row['xml_path'] ?? '')) !== '') {
                $xmlBadges[] = 'NF';
            }
            if (trim((string) ($row['cancel_xml_path'] ?? '')) !== '') {
                $xmlBadges[] = 'CAN';
            }

            echo '<tr>';
            echo '<td>' . htmlspecialchars($this->formatDate($eventDate, 'd/m/Y H:i'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['tipo_registro'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['status_fiscal'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) (($row['numero_nf'] ?? '') !== '' ? $row['numero_nf'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($this->resolveClientName($row), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a></td>';
            echo '<td>' . htmlspecialchars($this->formatMoney((float) ($row['invoice_total'] ?? 0), (string) ($row['currency_prefix'] ?? 'R$ '), (string) ($row['currency_suffix'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['origem'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars(!empty($xmlBadges) ? implode(' / ', $xmlBadges) : '-', ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }

        echo '</table>';

        echo '<div style="margin-top:10px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
        $firstItem = $totalDocs > 0 ? ($offset + 1) : 0;
        $lastItem = min($offset + count($rows), $totalDocs);
        echo '<div style="font-size:14px;color:#374151;">Exibindo <strong style="color:#111827;">' . $firstItem . '-' . $lastItem . '</strong> de <strong style="color:#111827;">' . $totalDocs . '</strong> registros</div>';

        $baseParams = [
            'module' => 'OpenNfse',
            'action' => 'relatorios',
            'tab' => 'historico_fiscal',
            'mes' => $selectedMonth,
            'tipo_registro' => $tipoRegistro,
            'invoiceid' => $invoiceFilter,
        ];
        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
        if ($page > 1) {
            $prevParams = $baseParams;
            $prevParams['page'] = $page - 1;
            echo '<a class="btn btn-xs btn-default" style="min-width:82px;" href="addonmodules.php?' . htmlspecialchars(http_build_query($prevParams, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Anterior</a>';
        }
        echo '<span style="display:inline-flex;align-items:center;padding:6px 10px;border:1px solid #d1d5db;border-radius:999px;background:#f9fafb;font-size:13px;font-weight:600;color:#374151;">Página ' . $page . ' / ' . $totalPages . '</span>';
        if ($page < $totalPages) {
            $nextParams = $baseParams;
            $nextParams['page'] = $page + 1;
            echo '<a class="btn btn-xs btn-default" style="min-width:82px;" href="addonmodules.php?' . htmlspecialchars(http_build_query($nextParams, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Próxima</a>';
        }
        echo '</div>';
        echo '</div>';
    }

    public function showRelatorioAuditoriaApiDps(bool $embedded = false): void
    {
        if (!$embedded) {
            $this->redirectRelatorios('auditoria_api_dps');
        }

        $this->ensureFiscalHistoryReady();

        $requestedMonth = trim((string) ($_REQUEST['mes'] ?? ''));
        $defaultMonth = $this->getXmlAuditDefaultMonth();
        [$selectedMonth] = $this->resolveAuditoriaMonthRange($requestedMonth !== '' ? $requestedMonth : $defaultMonth);
        $dpsPage = max(1, (int) ($_REQUEST['dps_page'] ?? 1));
        $gapPage = max(1, (int) ($_REQUEST['gap_page'] ?? 1));
        $runId = (int) ($_REQUEST['run_id'] ?? 0);
        $startAudit = (string) ($_REQUEST['start_auditoria'] ?? '') === '1';
        $action = trim((string) ($_REQUEST['dps_audit_action'] ?? ''));
        $isAjax = (string) ($_REQUEST['ajax'] ?? '') === '1';
        $service = new DpsApiAuditService();
        $repo = new DpsApiAuditRepository();

        if ($isAjax && $action === 'process_batch') {
            header('Content-Type: application/json; charset=UTF-8');
            try {
                $payload = $service->processRunBatch($runId, 25);
                echo json_encode(['success' => true, 'payload' => $payload], JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'payload' => $service->getRunPayload($runId),
                ], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        $auditError = null;
        if ($startAudit) {
            try {
                $run = $service->createRun($selectedMonth);
                $runId = (int) ($run['id'] ?? 0);
            } catch (\Throwable $e) {
                $auditError = $e->getMessage();
            }
        }

        $run = $runId > 0 ? $repo->findRun($runId) : null;
        if ($run !== null && (string) ($run['audit_month'] ?? '') !== $selectedMonth) {
            $run = null;
            $runId = 0;
        }
        if ($run === null) {
            $run = $repo->findLatestRunByMonth($selectedMonth);
        }
        if ($run !== null) {
            $runId = (int) ($run['id'] ?? 0);
        }

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="auditoria_api_dps" />';
        if ($runId > 0 && is_array($run) && (string) ($run['audit_month'] ?? '') === $selectedMonth) {
            echo '<input type="hidden" name="run_id" value="' . $runId . '" />';
        }
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">';
        echo '<div style="min-width:190px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Mês de referência</div>';
        echo '<input type="month" name="mes" value="' . htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8') . '" style="width:190px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Atualizar</button>';
        echo '<button type="submit" name="start_auditoria" value="1" class="btn btn-xs btn-success" style="height:34px;padding:0 12px;line-height:32px;">Iniciar auditoria assíncrona</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=auditoria_api_dps">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fbfcfe 0%,#f5f8fb 100%);">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">Por que esta função existe</div>';
        echo '<div style="font-size:12px;color:#5b6776;line-height:1.6;">';
        echo 'Esta auditoria existe para conferir se as DPS geradas localmente foram reconhecidas pela API, identificar divergências entre a chave local e a chave retornada pela consulta oficial, e apontar possíveis lacunas na sequência da DPS que indiquem tentativas perdidas, falhas de persistência ou saltos sem evidência técnica.';
        echo '</div>';
        echo '</div>';

        if ($auditError !== null) {
            echo '<div class="alert alert-danger" style="margin-bottom:14px;">Falha ao executar a auditoria API por DPS: ' . htmlspecialchars($auditError, ENT_QUOTES, 'UTF-8') . '</div>';
            return;
        }

        if (!is_array($run)) {
            echo '<div class="alert alert-info" style="margin-bottom:14px;">Selecione o mês desejado e clique em <strong>Iniciar auditoria assíncrona</strong> para criar uma execução em lotes da conferência por DPS.</div>';
            return;
        }

        $statusCounts = [
            'OK' => (int) ($run['ok_count'] ?? 0),
            'LOCAL_SEM_CHAVE' => (int) ($run['local_sem_chave_count'] ?? 0),
            'SEM_CHAVE_API' => (int) ($run['sem_chave_api_count'] ?? 0),
            'NAO_ENCONTRADA' => (int) ($run['nao_encontrada_count'] ?? 0),
            'CHAVE_DIVERGENTE' => (int) ($run['chave_divergente_count'] ?? 0),
            'ERRO_API' => (int) ($run['erro_api_count'] ?? 0),
            'SEM_ID_DPS' => (int) ($run['sem_id_dps_count'] ?? 0),
        ];
        $statusCards = [
            ['label' => 'DPS locais', 'value' => (int) ($run['total_items'] ?? 0), 'color' => '#23527c'],
            ['label' => 'Processadas', 'value' => (int) ($run['processed_items'] ?? 0), 'color' => '#176b46'],
            ['label' => 'OK', 'value' => (int) ($statusCounts['OK'] ?? 0), 'color' => '#2e7d32'],
            ['label' => 'Sem chave local', 'value' => (int) ($statusCounts['LOCAL_SEM_CHAVE'] ?? 0), 'color' => '#176b46'],
            ['label' => 'Sem chave na API', 'value' => (int) ($statusCounts['SEM_CHAVE_API'] ?? 0), 'color' => '#8a6d3b'],
            ['label' => 'Não encontradas', 'value' => (int) ($statusCounts['NAO_ENCONTRADA'] ?? 0), 'color' => '#b45f06'],
            ['label' => 'Divergentes', 'value' => (int) ($statusCounts['CHAVE_DIVERGENTE'] ?? 0), 'color' => '#a94442'],
            ['label' => 'Erros API', 'value' => (int) ($statusCounts['ERRO_API'] ?? 0), 'color' => '#8e44ad'],
            ['label' => 'Sem ID DPS', 'value' => (int) ($statusCounts['SEM_ID_DPS'] ?? 0), 'color' => '#6b7280'],
        ];
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px;">';
        foreach ($statusCards as $card) {
            echo '<div style="border:1px solid #dbe3ea;border-radius:8px;background:#fff;padding:12px 14px;box-shadow:0 1px 2px rgba(15,23,42,0.04);">';
            echo '<div style="font-size:11px;color:#667085;text-transform:uppercase;letter-spacing:.02em;margin-bottom:6px;">' . htmlspecialchars((string) $card['label'], ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<div style="font-size:26px;line-height:1.1;font-weight:700;color:' . htmlspecialchars((string) $card['color'], ENT_QUOTES, 'UTF-8') . ';">' . (int) $card['value'] . '</div>';
            echo '</div>';
        }
        echo '</div>';

        $status = (string) ($run['status'] ?? 'pending');
        $progressPercent = (int) ($run['total_items'] ?? 0) > 0
            ? round((((int) ($run['processed_items'] ?? 0)) / max(1, (int) ($run['total_items'] ?? 0))) * 100)
            : 100;
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,0.04);">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px;">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;">Execução assíncrona</div>';
        echo '<div style="font-size:12px;color:#5b6776;">Run ID <strong>' . $runId . '</strong> &nbsp; Status <strong id="dps-audit-status-label">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</strong></div>';
        echo '</div>';
        echo '<div style="height:10px;background:#eef2f7;border-radius:999px;overflow:hidden;margin-bottom:8px;">';
        echo '<div id="dps-audit-progress-bar" style="height:10px;background:#176b46;width:' . $progressPercent . '%;"></div>';
        echo '</div>';
        echo '<div id="dps-audit-progress-text" style="font-size:12px;color:#5b6776;">' . (int) ($run['processed_items'] ?? 0) . ' de ' . (int) ($run['total_items'] ?? 0) . ' DPS processadas (' . $progressPercent . '%).</div>';
        $runtimeHintDisplay = ($status === 'running' || $status === 'pending') ? 'block' : 'none';
        $lastRunError = trim((string) ($run['last_error'] ?? ''));
        if ($status === 'running' || $status === 'pending') {
            echo '<div id="dps-audit-runtime-hint" style="margin-top:6px;font-size:12px;color:#8a6d3b;">Processamento em andamento por lotes curtos. Mantenha esta página aberta até a conclusão, pois os próximos lotes e as retentativas automáticas dependem desta aba ativa no navegador.</div>';
        }
        echo '<div id="dps-audit-retry-state" style="display:' . $runtimeHintDisplay . ';margin-top:6px;font-size:12px;color:#8a6d3b;"></div>';
        echo '<div id="dps-audit-last-error" style="display:' . ($lastRunError !== '' ? 'block' : 'none') . ';margin-top:8px;padding:10px 12px;border:1px solid #f2d6d6;background:#fff8f8;color:#8f2d2d;font-size:12px;line-height:1.5;white-space:pre-wrap;">' . htmlspecialchars($lastRunError, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '</div>';

        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,0.04);">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">Conferência da sequência DPS</div>';
        echo '<div style="font-size:12px;color:#5b6776;line-height:1.6;">';
        echo 'Primeiro número no período: <strong>' . htmlspecialchars((string) (($run['first_number'] ?? null) !== null ? $run['first_number'] : '-'), ENT_QUOTES, 'UTF-8') . '</strong> ';
        echo '&nbsp; Último número no período: <strong>' . htmlspecialchars((string) (($run['last_number'] ?? null) !== null ? $run['last_number'] : '-'), ENT_QUOTES, 'UTF-8') . '</strong> ';
        echo '&nbsp; Lacunas: <strong>' . (int) ($run['gap_count'] ?? 0) . '</strong> ';
        echo '&nbsp; Último sequencial atual: <strong>' . htmlspecialchars((string) (($run['current_sequence_last_number'] ?? null) !== null ? $run['current_sequence_last_number'] : '-'), ENT_QUOTES, 'UTF-8') . '</strong>.';
        echo '</div>';
        echo '</div>';

        $statusLabelMap = [
            'OK' => ['OK', '#e8f5e9', '#2e7d32'],
            'DIVERGENCIA_CANCELADA' => ['Diverg. cancelada', '#eef6ff', '#23527c'],
            'DIVERGENCIA_REEMITIDA' => ['Diverg. reemitida', '#eef6ff', '#23527c'],
            'LOCAL_SEM_CHAVE' => ['Local sem chave', '#eaf7f1', '#176b46'],
            'SEM_CHAVE_API' => ['API sem chave', '#fff8e1', '#8a6d3b'],
            'NAO_ENCONTRADA' => ['Não encontrada', '#fff3e0', '#b45f06'],
            'CHAVE_DIVERGENTE' => ['Chave divergente', '#fdecec', '#a94442'],
            'ERRO_API' => ['Erro API', '#f3e8ff', '#8e44ad'],
            'SEM_ID_DPS' => ['Sem ID DPS', '#eef2f7', '#6b7280'],
        ];

        $dpsPerPage = 100;
        $dpsTotal = $repo->countResults($runId, 'DPS');
        $dpsPages = max(1, (int) ceil($dpsTotal / $dpsPerPage));
        if ($dpsPage > $dpsPages) {
            $dpsPage = $dpsPages;
        }
        $dpsRows = $repo->listResults($runId, 'DPS', $dpsPerPage, ($dpsPage - 1) * $dpsPerPage);

        echo '<div style="margin-bottom:16px;">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;">Conferência local vs API por DPS</div>';
        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:10%;">Data</th>';
        echo '<th style="width:10%;">Invoice</th>';
        echo '<th style="width:18%;">Cliente</th>';
        echo '<th style="width:17%;">ID DPS</th>';
        echo '<th style="width:8%;">Seq.</th>';
        echo '<th style="width:10%;">NFS-e</th>';
        echo '<th style="width:10%;">Status</th>';
        echo '<th style="width:17%;">Chave local / API</th>';
        echo '</tr>';
        if (empty($dpsRows)) {
            echo '<tr><td colspan="8" style="text-align:center;color:#666;">Nenhuma emissão com DPS foi encontrada no período selecionado.</td></tr>';
        }
        foreach ($dpsRows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            $statusKey = (string) ($row['audit_status'] ?? 'SEM_ID_DPS');
            $statusMeta = $statusLabelMap[$statusKey] ?? [$statusKey, '#eef2f7', '#5f6b7a'];
            $eventDate = (string) (($row['event_date'] ?? '') !== '' ? $row['event_date'] : (($row['created_at'] ?? '') !== '' ? $row['created_at'] : ''));
            $localChave = trim((string) ($row['local_chave_acesso'] ?? ''));
            $apiChave = trim((string) ($row['api_chave_acesso'] ?? ''));

            echo '<tr>';
            echo '<td>' . htmlspecialchars($this->formatDate($eventDate, 'd/m/Y H:i'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a></td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($this->resolveClientName($row), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) (($row['id_dps'] ?? '') !== '' ? $row['id_dps'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) (($row['numero_dps'] ?? null) !== null ? $row['numero_dps'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) (($row['numero_nf'] ?? '') !== '' ? $row['numero_nf'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><span style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . htmlspecialchars((string) $statusMeta[1], ENT_QUOTES, 'UTF-8') . ';color:' . htmlspecialchars((string) $statusMeta[2], ENT_QUOTES, 'UTF-8') . ';font-size:10px;font-weight:700;">' . htmlspecialchars((string) $statusMeta[0], ENT_QUOTES, 'UTF-8') . '</span><div style="margin-top:4px;color:#6b7280;">' . htmlspecialchars((string) ($row['audit_message'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div></td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">Local: ' . htmlspecialchars($localChave !== '' ? $localChave : '-', ENT_QUOTES, 'UTF-8') . '<br />API: ' . htmlspecialchars($apiChave !== '' ? $apiChave : '-', ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        $baseDpsParams = [
            'module' => 'OpenNfse',
            'action' => 'relatorios',
            'tab' => 'auditoria_api_dps',
            'mes' => $selectedMonth,
            'run_id' => $runId,
            'gap_page' => $gapPage,
        ];
        echo '<div style="margin-top:10px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
        $dpsFirst = $dpsTotal > 0 ? (($dpsPage - 1) * $dpsPerPage + 1) : 0;
        $dpsLast = min(($dpsPage - 1) * $dpsPerPage + count($dpsRows), $dpsTotal);
        echo '<div style="font-size:14px;color:#374151;">Exibindo <strong style="color:#111827;">' . $dpsFirst . '-' . $dpsLast . '</strong> de <strong style="color:#111827;">' . $dpsTotal . '</strong> DPS</div>';
        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
        if ($dpsPage > 1) {
            $prevParams = $baseDpsParams;
            $prevParams['dps_page'] = $dpsPage - 1;
            echo '<a class="btn btn-xs btn-default" style="min-width:82px;" href="addonmodules.php?' . htmlspecialchars(http_build_query($prevParams, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Anterior</a>';
        }
        echo '<span style="display:inline-flex;align-items:center;padding:6px 10px;border:1px solid #d1d5db;border-radius:999px;background:#f9fafb;font-size:13px;font-weight:600;color:#374151;">Página ' . $dpsPage . ' / ' . $dpsPages . '</span>';
        if ($dpsPage < $dpsPages) {
            $nextParams = $baseDpsParams;
            $nextParams['dps_page'] = $dpsPage + 1;
            echo '<a class="btn btn-xs btn-default" style="min-width:82px;" href="addonmodules.php?' . htmlspecialchars(http_build_query($nextParams, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Próxima</a>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';

        $gapPerPage = 100;
        $gapTotal = $repo->countResults($runId, 'GAP');
        $gapPages = max(1, (int) ceil($gapTotal / $gapPerPage));
        if ($gapPage > $gapPages) {
            $gapPage = $gapPages;
        }
        $gapRows = $repo->listResults($runId, 'GAP', $gapPerPage, ($gapPage - 1) * $gapPerPage);
        echo '<div style="margin-bottom:16px;">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;">Lacunas na sequência DPS</div>';
        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:10%;">Seq.</th>';
        echo '<th style="width:24%;">ID DPS esperado</th>';
        echo '<th style="width:18%;">Classificação</th>';
        echo '<th style="width:12%;">Evidências</th>';
        echo '<th style="width:36%;">Leitura</th>';
        echo '</tr>';
        if (empty($gapRows)) {
            echo '<tr><td colspan="5" style="text-align:center;color:#666;">Nenhuma lacuna foi identificada na sequência das DPS do período.</td></tr>';
        }
        foreach ($gapRows as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) ($row['numero_dps'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) (($row['id_dps'] ?? '') !== '' ? $row['id_dps'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['evidence_classification'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . (int) ($row['evidence_count'] ?? 0) . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) ($row['audit_message'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        $baseGapParams = [
            'module' => 'OpenNfse',
            'action' => 'relatorios',
            'tab' => 'auditoria_api_dps',
            'mes' => $selectedMonth,
            'run_id' => $runId,
            'dps_page' => $dpsPage,
        ];
        echo '<div style="margin-top:10px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
        $gapFirst = $gapTotal > 0 ? (($gapPage - 1) * $gapPerPage + 1) : 0;
        $gapLast = min(($gapPage - 1) * $gapPerPage + count($gapRows), $gapTotal);
        echo '<div style="font-size:14px;color:#374151;">Exibindo <strong style="color:#111827;">' . $gapFirst . '-' . $gapLast . '</strong> de <strong style="color:#111827;">' . $gapTotal . '</strong> lacunas</div>';
        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
        if ($gapPage > 1) {
            $prevParams = $baseGapParams;
            $prevParams['gap_page'] = $gapPage - 1;
            echo '<a class="btn btn-xs btn-default" style="min-width:82px;" href="addonmodules.php?' . htmlspecialchars(http_build_query($prevParams, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Anterior</a>';
        }
        echo '<span style="display:inline-flex;align-items:center;padding:6px 10px;border:1px solid #d1d5db;border-radius:999px;background:#f9fafb;font-size:13px;font-weight:600;color:#374151;">Página ' . $gapPage . ' / ' . $gapPages . '</span>';
        if ($gapPage < $gapPages) {
            $nextParams = $baseGapParams;
            $nextParams['gap_page'] = $gapPage + 1;
            echo '<a class="btn btn-xs btn-default" style="min-width:82px;" href="addonmodules.php?' . htmlspecialchars(http_build_query($nextParams, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Próxima</a>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';

        if ($status === 'running' || $status === 'pending') {
            $ajaxBaseUrl = 'addonmodules.php?' . http_build_query([
                'module' => 'OpenNfse',
                'action' => 'relatorios',
                'tab' => 'auditoria_api_dps',
                'mes' => $selectedMonth,
                'run_id' => $runId,
                'ajax' => 1,
                'dps_audit_action' => 'process_batch',
            ], '', '&', PHP_QUERY_RFC3986);
            $reloadUrl = 'addonmodules.php?' . http_build_query([
                'module' => 'OpenNfse',
                'action' => 'relatorios',
                'tab' => 'auditoria_api_dps',
                'mes' => $selectedMonth,
                'run_id' => $runId,
            ], '', '&', PHP_QUERY_RFC3986);
            echo "<script>
(() => {
  const progressBar = document.getElementById('dps-audit-progress-bar');
  const progressText = document.getElementById('dps-audit-progress-text');
  const statusLabel = document.getElementById('dps-audit-status-label');
  const runtimeHint = document.getElementById('dps-audit-runtime-hint');
  const retryState = document.getElementById('dps-audit-retry-state');
  const lastErrorBox = document.getElementById('dps-audit-last-error');
  const ajaxBaseUrl = " . json_encode($ajaxBaseUrl) . ";
  const reloadUrl = " . json_encode($reloadUrl) . ";
  let retryCount = 0;
  let inFlight = false;
  let retryTimer = null;

  function setLastError(detail) {
    if (!lastErrorBox) {
      return;
    }
    if (!detail) {
      lastErrorBox.style.display = 'none';
      lastErrorBox.textContent = '';
      return;
    }
    lastErrorBox.style.display = 'block';
    lastErrorBox.textContent = detail;
  }

  function scheduleRetry(kind, detail, delayMs) {
    retryCount += 1;
    if (runtimeHint) {
      runtimeHint.textContent = kind === 'backend'
        ? 'O lote retornou erro de aplicação. A retentativa automática permanece ativa.'
        : 'Falha de comunicação detectada. A retentativa automática permanece ativa.';
    }
    if (retryState) {
      retryState.style.display = 'block';
      retryState.textContent = 'Tentativa automática #' + retryCount + ' agendada para ' + (delayMs / 1000) + 's. A execução continuará do ponto já persistido.';
    }
    setLastError(detail || 'Erro sem detalhe retornado.');
    window.clearTimeout(retryTimer);
    retryTimer = window.setTimeout(tick, delayMs);
  }

  function buildHttpDetail(response, bodyText) {
    const statusText = response && response.status ? 'HTTP ' + response.status + (response.statusText ? ' ' + response.statusText : '') : 'Resposta HTTP inválida';
    const snippet = String(bodyText || '').trim();
    if (!snippet) {
      return statusText;
    }
    return statusText + ': ' + snippet.slice(0, 800);
  }

  function applyPayload(payload) {
    if (!payload || typeof payload !== 'object') {
      return;
    }
    if (statusLabel) {
      statusLabel.textContent = payload.status || 'running';
    }
    if (progressBar) {
      progressBar.style.width = String(payload.progress_percent || 0) + '%';
    }
    if (progressText) {
      progressText.textContent = String(payload.processed_items || 0) + ' de ' + String(payload.total_items || 0) + ' DPS processadas (' + String(payload.progress_percent || 0) + '%).';
    }
    if (payload.last_error) {
      setLastError(payload.last_error);
    } else if (retryCount === 0) {
      setLastError('');
    }
  }

  async function tick() {
    if (inFlight) {
      return;
    }
    inFlight = true;
    try {
      const response = await fetch(ajaxBaseUrl + '&_ts=' + Date.now(), { credentials: 'same-origin' });
      const rawText = await response.text();
      let data = null;
      try {
        data = rawText !== '' ? JSON.parse(rawText) : null;
      } catch (parseError) {
        throw new Error('Resposta inválida ao processar o lote. ' + buildHttpDetail(response, rawText));
      }
      if (!response.ok) {
        applyPayload(data && data.payload ? data.payload : null);
        scheduleRetry('backend', (data && data.error) ? String(data.error) : buildHttpDetail(response, rawText), 2500);
        return;
      }
      if (!data || typeof data !== 'object') {
        throw new Error('Resposta vazia ao processar o lote da auditoria.');
      }
      if (!data.success) {
        applyPayload(data.payload || null);
        scheduleRetry('backend', data.error || 'Falha ao processar um lote da auditoria.', 2500);
        return;
      }
      const payload = data.payload || {};
      retryCount = 0;
      if (retryState) {
        retryState.textContent = '';
        retryState.style.display = 'none';
      }
      if (runtimeHint) {
        runtimeHint.textContent = 'Processamento em andamento por lotes curtos. Mantenha esta página aberta até a conclusão, pois os próximos lotes e as retentativas automáticas dependem desta aba ativa no navegador.';
      }
      setLastError(payload.last_error || '');
      applyPayload(payload);
      if (payload.finished) {
        window.location.href = reloadUrl;
        return;
      }
      retryTimer = window.setTimeout(tick, 600);
    } catch (error) {
      scheduleRetry('communication', error && error.message ? error.message : String(error), 2500);
    } finally {
      inFlight = false;
    }
  }
  retryTimer = window.setTimeout(tick, 300);
})();
</script>";
        }
    }

    public function showRelatorioAuditoriaApi(bool $embedded = false): void
    {
        if (!$embedded) {
            $this->redirectRelatorios('auditoria_api');
        }

        $this->ensureFiscalHistoryReady();

        $requestedMonth = trim((string) ($_REQUEST['mes'] ?? ''));
        $defaultMonth = $this->getXmlAuditDefaultMonth();
        [$selectedMonth] = $this->resolveAuditoriaMonthRange($requestedMonth !== '' ? $requestedMonth : $defaultMonth);

        $syncRequested = (string) ($_REQUEST['sync_nsu'] ?? '') === '1';
        $syncResult = null;
        $syncError = null;
        if ($syncRequested) {
            try {
                $syncResult = (new ApiAuditService())->syncByNsu(10);
            } catch (\Throwable $e) {
                $syncError = $e->getMessage();
            }
        }

        $config = (new ConfigRepository())->get();
        $environment = trim((string) ($config['environment'] ?? 'producao'));
        $apiRepo = new ApiAuditRepository();
        $state = $apiRepo->getSyncState($environment);
        $diagnostics = json_decode((string) ($state['last_diagnostics_json'] ?? ''), true);
        if (!is_array($diagnostics)) {
            $diagnostics = [];
        }
        $apiSummary = $apiRepo->summaryDistributedDocumentsByMonth($environment, $selectedMonth);
        $apiRows = $apiRepo->listDistributedDocumentsByMonth($environment, $selectedMonth);

        $historyRepo = new FiscalHistoryRepository();
        $localSummary = $historyRepo->summaryComparableHistoryByMonth($selectedMonth);
        $localRows = $historyRepo->listComparableHistoryByMonth($selectedMonth);
        $comparison = $this->buildApiAuditComparison($apiRows, $localRows);

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="auditoria_api" />';
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">';
        echo '<div style="min-width:190px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Mês de referência</div>';
        echo '<input type="month" name="mes" value="' . htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8') . '" style="width:190px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Filtrar</button>';
        echo '<button type="submit" name="sync_nsu" value="1" class="btn btn-xs btn-success" style="height:34px;padding:0 12px;line-height:32px;">Sincronizar NSU agora</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=auditoria_api">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        if ($syncError !== null) {
            echo '<div class="alert alert-danger" style="margin-bottom:14px;">Falha ao sincronizar a distribuicao da API: ' . htmlspecialchars($syncError, ENT_QUOTES, 'UTF-8') . '</div>';
        } elseif (is_array($syncResult)) {
            $processedCount = (int) ($syncResult['processed_count'] ?? 0);
            $alertClass = $processedCount > 0 ? 'success' : 'warning';
            $messagePrefix = $processedCount > 0 ? 'Sincronizacao concluida.' : 'Sincronizacao concluida sem retorno de documentos.';
            echo '<div class="alert alert-' . $alertClass . '" style="margin-bottom:14px;">' . $messagePrefix . ' Foram processados <strong>' . $processedCount . '</strong> documentos em <strong>' . (int) ($syncResult['batch_count'] ?? 0) . '</strong> lote(s), usando o modo <strong>' . htmlspecialchars((string) ($syncResult['mode'] ?? 'com_cnpj'), ENT_QUOTES, 'UTF-8') . '</strong>.</div>';
        }

        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fbfcfe 0%,#f5f8fb 100%);">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">Estado da sincronização</div>';
        echo '<div style="font-size:12px;color:#5b6776;line-height:1.55;">';
        echo 'Ambiente: <strong>' . htmlspecialchars($environment !== '' ? $environment : 'producao', ENT_QUOTES, 'UTF-8') . '</strong> ';
        echo '&nbsp; CNPJ consulta: <strong>' . htmlspecialchars((string) (($state['cnpj_consulta'] ?? '') !== '' ? $state['cnpj_consulta'] : '-'), ENT_QUOTES, 'UTF-8') . '</strong> ';
        echo '&nbsp; Ultimo NSU: <strong>' . (int) ($state['ultimo_nsu'] ?? 0) . '</strong> ';
        echo '&nbsp; Maior NSU conhecido: <strong>' . (int) ($state['maior_nsu'] ?? 0) . '</strong> ';
        echo '&nbsp; Ultimo sync: <strong>' . htmlspecialchars((string) (($state['ultimo_sync_em'] ?? '') !== '' ? $this->formatDate((string) $state['ultimo_sync_em'], 'd/m/Y H:i') : '-'), ENT_QUOTES, 'UTF-8') . '</strong> ';
        echo '&nbsp; Modo usado: <strong>' . htmlspecialchars((string) (($state['last_sync_mode'] ?? '') !== '' ? $state['last_sync_mode'] : '-'), ENT_QUOTES, 'UTF-8') . '</strong>.';
        echo '</div>';
        echo '</div>';

        if (!empty($diagnostics)) {
            echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,0.04);">';
            echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;">Diagnostico da distribuicao</div>';
            if (!empty($diagnostics['tested_at'])) {
                echo '<div style="font-size:12px;color:#5b6776;margin-bottom:10px;">Ultimo teste em <strong>' . htmlspecialchars($this->formatDate((string) $diagnostics['tested_at'], 'd/m/Y H:i:s'), ENT_QUOTES, 'UTF-8') . '</strong>, partindo do NSU <strong>' . (int) ($diagnostics['initial_nsu'] ?? 0) . '</strong>.</div>';
            }

            $diagModes = [
                'with_cnpj' => 'Consulta com CNPJ',
                'without_cnpj' => 'Consulta sem CNPJ',
            ];

            foreach ($diagModes as $diagKey => $diagLabel) {
                $diag = $diagnostics[$diagKey] ?? null;
                if (!is_array($diag)) {
                    continue;
                }

                echo '<div style="border:1px solid #e5edf5;border-radius:8px;background:#fbfcfe;padding:10px 12px;margin-bottom:10px;">';
                echo '<div style="font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">' . htmlspecialchars($diagLabel, ENT_QUOTES, 'UTF-8') . '</div>';
                echo '<div style="font-size:12px;color:#5b6776;line-height:1.6;">';
                echo 'Modo: <strong>' . htmlspecialchars((string) ($diag['mode'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</strong> ';
                echo '&nbsp; CNPJ: <strong>' . htmlspecialchars((string) (($diag['cnpj_consulta'] ?? '') !== '' ? $diag['cnpj_consulta'] : '-'), ENT_QUOTES, 'UTF-8') . '</strong> ';
                echo '&nbsp; Processados: <strong>' . (int) ($diag['processed_count'] ?? 0) . '</strong> ';
                echo '&nbsp; Lotes: <strong>' . (int) ($diag['batch_count'] ?? 0) . '</strong> ';
                echo '&nbsp; Respostas vazias: <strong>' . (int) ($diag['empty_response_count'] ?? 0) . '</strong> ';
                echo '&nbsp; Ultimo NSU: <strong>' . (int) ($diag['ultimo_nsu'] ?? 0) . '</strong> ';
                echo '&nbsp; Maior NSU: <strong>' . (int) ($diag['maior_nsu'] ?? 0) . '</strong>.';
                echo '</div>';

                if (!empty($diag['error_message'])) {
                    echo '<div class="alert alert-danger" style="margin:10px 0 0 0;">' . htmlspecialchars((string) $diag['error_message'], ENT_QUOTES, 'UTF-8') . '</div>';
                }

                $alerts = is_array($diag['alertas'] ?? null) ? $diag['alertas'] : [];
                if (!empty($alerts)) {
                    echo '<div style="margin-top:8px;font-size:12px;color:#8a6d3b;"><strong>Alertas:</strong> ';
                    $parts = [];
                    foreach ($alerts as $message) {
                        if (!is_array($message)) {
                            continue;
                        }
                        $text = trim((string) (($message['mensagem'] ?? '') !== '' ? $message['mensagem'] : ($message['descricao'] ?? '')));
                        if ($text !== '') {
                            $parts[] = $text;
                        }
                    }
                    echo htmlspecialchars(!empty($parts) ? implode(' | ', $parts) : '-', ENT_QUOTES, 'UTF-8');
                    echo '</div>';
                }

                $errors = is_array($diag['errors'] ?? null) ? $diag['errors'] : [];
                if (!empty($errors)) {
                    echo '<div style="margin-top:8px;font-size:12px;color:#a94442;"><strong>Erros:</strong> ';
                    $parts = [];
                    foreach ($errors as $message) {
                        if (!is_array($message)) {
                            continue;
                        }
                        $text = trim((string) (($message['mensagem'] ?? '') !== '' ? $message['mensagem'] : ($message['descricao'] ?? '')));
                        if ($text !== '') {
                            $parts[] = $text;
                        }
                    }
                    echo htmlspecialchars(!empty($parts) ? implode(' | ', $parts) : '-', ENT_QUOTES, 'UTF-8');
                    echo '</div>';
                }

                if (!empty($diag['raw_response_summary'])) {
                    echo '<div style="margin-top:8px;font-size:12px;color:#5b6776;word-break:break-word;overflow-wrap:anywhere;"><strong>Resumo bruto:</strong> ' . htmlspecialchars((string) $diag['raw_response_summary'], ENT_QUOTES, 'UTF-8') . '</div>';
                }
                echo '</div>';
            }
            echo '</div>';
        }

        $cards = [
            ['label' => 'API no periodo', 'value' => (int) ($apiSummary['total_docs'] ?? 0), 'color' => '#176b46'],
            ['label' => 'Local no periodo', 'value' => (int) ($localSummary['total_docs'] ?? 0), 'color' => '#23527c'],
            ['label' => 'API sem local', 'value' => count((array) ($comparison['missing_local'] ?? [])), 'color' => '#b45f06'],
            ['label' => 'Local sem API', 'value' => count((array) ($comparison['missing_api'] ?? [])), 'color' => '#a94442'],
            ['label' => 'Correspondencias', 'value' => (int) ($comparison['matched_count'] ?? 0), 'color' => '#2e7d32'],
        ];
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px;">';
        foreach ($cards as $card) {
            echo '<div style="border:1px solid #dbe3ea;border-radius:8px;background:#fff;padding:12px 14px;box-shadow:0 1px 2px rgba(15,23,42,0.04);">';
            echo '<div style="font-size:11px;color:#667085;text-transform:uppercase;letter-spacing:.02em;margin-bottom:6px;">' . htmlspecialchars((string) $card['label'], ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<div style="font-size:26px;line-height:1.1;font-weight:700;color:' . htmlspecialchars((string) $card['color'], ENT_QUOTES, 'UTF-8') . ';">' . (int) $card['value'] . '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<div style="margin-bottom:16px;">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;">Documentos recebidos da API sem correspondente local</div>';
        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:10%;">Data</th>';
        echo '<th style="width:10%;">Tipo</th>';
        echo '<th style="width:14%;">NSU</th>';
        echo '<th style="width:16%;">Chave</th>';
        echo '<th style="width:10%;">NFS-e</th>';
        echo '<th style="width:14%;">Evento</th>';
        echo '<th style="width:26%;">Chave de comparacao</th>';
        echo '</tr>';
        $missingLocal = (array) ($comparison['missing_local'] ?? []);
        if (empty($missingLocal)) {
            echo '<tr><td colspan="7" style="text-align:center;color:#666;">Nenhum documento da API ficou sem correspondente local no período.</td></tr>';
        }
        foreach ($missingLocal as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($this->formatDate((string) ($row['referencia_em'] ?? ''), 'd/m/Y H:i'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['tipo_documento'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . (int) ($row['nsu'] ?? 0) . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) (($row['chave_acesso'] ?? '') !== '' ? $row['chave_acesso'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) (($row['numero_nf'] ?? '') !== '' ? $row['numero_nf'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) (($row['tipo_evento'] ?? '') !== '' ? $row['tipo_evento'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) ($row['_compare_key'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';

        echo '<div style="margin-bottom:16px;">';
        echo '<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;">Historico local sem correspondente na API distribuida</div>';
        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:10%;">Data</th>';
        echo '<th style="width:10%;">Tipo</th>';
        echo '<th style="width:16%;">Chave</th>';
        echo '<th style="width:10%;">NFS-e</th>';
        echo '<th style="width:12%;">Invoice</th>';
        echo '<th style="width:20%;">Cliente</th>';
        echo '<th style="width:22%;">Chave de comparacao</th>';
        echo '</tr>';
        $missingApi = (array) ($comparison['missing_api'] ?? []);
        if (empty($missingApi)) {
            echo '<tr><td colspan="7" style="text-align:center;color:#666;">Nenhum registro local ficou sem correspondente na API distribuida para o período.</td></tr>';
        }
        foreach ($missingApi as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            echo '<tr>';
            echo '<td>' . htmlspecialchars($this->formatDate((string) ($row['_event_date'] ?? ''), 'd/m/Y H:i'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['tipo_registro'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) (($row['chave_acesso'] ?? '') !== '' ? $row['chave_acesso'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) (($row['numero_nf'] ?? '') !== '' ? $row['numero_nf'] : '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . $invoiceId . '</a></td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($this->resolveClientName($row), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) ($row['_compare_key'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';
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
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;flex:1 1 860px;">';
        echo '<div style="min-width:160px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Data inicial</div>';
        echo '<input type="date" name="data_inicial" value="' . htmlspecialchars($dataInicial, ENT_QUOTES, 'UTF-8') . '" style="width:160px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="min-width:160px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Data final</div>';
        echo '<input type="date" name="data_final" value="' . htmlspecialchars($dataFinal, ENT_QUOTES, 'UTF-8') . '" style="width:160px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="min-width:130px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Invoice ID</div>';
        echo '<input type="text" name="invoiceid" placeholder="Invoice ID" value="' . htmlspecialchars($invoiceFilter, ENT_QUOTES, 'UTF-8') . '" style="width:130px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="min-width:250px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Tipo</div>';
        echo '<select name="tipo" style="width:250px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;">';
        echo '<option value="">Todos os tipos</option>';
        foreach ($tipoOptions as $t) {
            $sel = $t === $tipoFilter ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div style="flex:1 1 320px;min-width:320px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Buscar em request/response</div>';
        echo '<input type="text" name="q" placeholder="Buscar (request/response)" value="' . htmlspecialchars($qFilter, ENT_QUOTES, 'UTF-8') . '" style="width:100%;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=logs">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
        echo '<div style="margin-bottom:12px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;">';
        echo $this->renderPostActionButton('relatoriosExport', 'Exportar CSV', [
            'tab' => 'logs',
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'invoiceid' => $invoiceFilter,
            'tipo' => $tipoFilter,
            'q' => $qFilter,
        ], 'btn btn-xs btn-default');
        echo '</div>';

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

        echo '<div style="margin-top:10px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
        echo '<div style="font-size:14px;color:#374151;">Total: <strong style="color:#111827;">' . $total . '</strong></div>';

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

        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
        if ($page > 1) {
            $p = $baseParams;
            $p['page'] = $page - 1;
            echo '<a class="btn btn-xs btn-default" style="min-width:82px;" href="addonmodules.php?' . htmlspecialchars(http_build_query($p, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Anterior</a>';
        }
        echo '<span style="display:inline-flex;align-items:center;padding:6px 10px;border:1px solid #d1d5db;border-radius:999px;background:#f9fafb;font-size:13px;font-weight:600;color:#374151;">Página ' . $page . ' / ' . $totalPages . '</span>';
        if ($page < $totalPages) {
            $p = $baseParams;
            $p['page'] = $page + 1;
            echo '<a class="btn btn-xs btn-default" style="min-width:82px;" href="addonmodules.php?' . htmlspecialchars(http_build_query($p, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') . '">Próxima</a>';
        }
        echo '</div>';
        echo '</div>';
    }


    public function exportRelatoriosCsv(): void
    {
        $tab = trim((string) ($_REQUEST['tab'] ?? 'emitidas'));
        $allowed = ['emitidas' => true, 'falhas' => true, 'cancelamentos' => true, 'auditoria' => true, 'logs' => true];
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

        if ($tab === 'auditoria') {
            [$selectedMonth, $dataInicial, $dataFinal] = $this->resolveAuditoriaMonthRange(trim((string) ($_REQUEST['mes'] ?? '')));
            $rows = (new ReportRepository())->listAuditoriaInvoices([
                'data_inicial' => $dataInicial,
                'data_final' => $dataFinal,
                'gateways' => array_keys($this->getAuditoriaActiveGatewayMap()),
            ], 5000);
            $gatewayMap = $this->getAuditoriaActiveGatewayMap();

            fputcsv($out, ['invoice_id', 'cliente', 'data_pagamento', 'gateway', 'valor'], ';');
            foreach ($rows as $r) {
                $gatewayKey = strtolower(trim((string) ($r['paymentmethod'] ?? '')));
                fputcsv($out, [
                    (string) ($r['invoice_id'] ?? ''),
                    $this->resolveClientName($r),
                    (string) ($r['paid_at'] ?? ''),
                    (string) ($gatewayMap[$gatewayKey] ?? ($gatewayKey !== '' ? $gatewayKey : '')),
                    (string) ($r['invoice_total'] ?? ''),
                ], ';');
            }
            fclose($out);
            exit;
        }

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
        $invoiceFilter = trim((string) ($_REQUEST['invoiceid'] ?? ''));
        $cliente = trim((string) ($_REQUEST['cliente'] ?? ''));
        $rows = (new ReportRepository())->listNotas([
            'invoiceid' => $invoiceFilter,
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => $status,
            'cliente' => $cliente,
        ], 5000);

        fputcsv($out, ['data', 'numero_nf', 'cliente', 'invoiceid', 'valor', 'status', 'chave_acesso'], ';');
        foreach ($rows as $r) {
            $dataRef = (string) ($r['reference_date'] ?? $r['emitida_em'] ?? $r['nfse_updated_at'] ?? '');
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
        $invoiceFilter = trim((string) ($_REQUEST['invoiceid'] ?? ''));
        $zipLayout = trim((string) ($_REQUEST['zip_layout'] ?? ''));

        $filters = [
            'invoiceid' => $invoiceFilter,
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

        $useCompetenciaLayout = $zipLayout === 'competencia' && $tab === 'emitidas';
        if ($useCompetenciaLayout) {
            foreach ($this->getCompetenciaZipDirectories() as $directory) {
                $zip->addEmptyDir($directory);
            }
        }

        $added = 0;
        foreach ($rows as $row) {
            $paths = $this->extractZipXmlPathsFromRow($row);
            if (empty($paths)) {
                continue;
            }

            $directory = $useCompetenciaLayout ? $this->resolveCompetenciaZipDirectory($row) : '';
            foreach ($paths as $xmlPath) {
                try {
                    $absPath = $storage->resolveAbsolutePath($xmlPath);
                } catch (\Throwable $e) {
                    continue;
                }

                if (!is_file($absPath)) {
                    continue;
                }

                $entryName = $this->buildZipEntryName($zip, $row, $absPath, $directory);
                if ($zip->addFile($absPath, $entryName)) {
                    $added++;
                }
            }
        }

        $zip->close();

        if ($added <= 0 || !is_file($zipPath)) {
            @unlink($zipPath);
            throw new NfseModuleException('Nenhum XML foi encontrado para exportação em lote.');
        }

        $filename = $useCompetenciaLayout
            ? $this->buildCompetenciaZipFilename($dataInicial)
            : 'nfse_xml_' . $tab . '_' . date('Ymd_His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    private function ensureFiscalHistoryReady(): void
    {
        Module::migrator()->up();
        (new FiscalHistoryRepository())->backfillCurrentStateIfNeeded();
    }

    private function resolveFiscalHistoryEventDate(array $row): string
    {
        $canceladoEm = trim((string) ($row['cancelado_em'] ?? ''));
        if ($canceladoEm !== '') {
            return $canceladoEm;
        }

        $emitidaEm = trim((string) ($row['emitida_em'] ?? ''));
        if ($emitidaEm !== '') {
            return $emitidaEm;
        }

        return (string) ($row['created_at'] ?? '');
    }

    private function buildApiAuditComparison(array $apiRows, array $localRows): array
    {
        $apiComparable = [];
        foreach ($apiRows as $row) {
            $type = strtoupper(trim((string) ($row['tipo_documento'] ?? '')));
            if (!in_array($type, ['EMISSAO', 'CANCELAMENTO'], true)) {
                continue;
            }

            $key = $this->buildApiAuditCompareKey(
                $type,
                (string) ($row['chave_acesso'] ?? ''),
                (string) ($row['numero_nf'] ?? ''),
                (string) ($row['referencia_em'] ?? '')
            );
            if ($key === '') {
                continue;
            }

            $row['_compare_key'] = $key;
            $apiComparable[$key] = $row;
        }

        $localComparable = [];
        foreach ($localRows as $row) {
            $type = strtoupper(trim((string) ($row['tipo_registro'] ?? '')));
            if (!in_array($type, ['EMISSAO', 'CANCELAMENTO'], true)) {
                continue;
            }

            $eventDate = $this->resolveFiscalHistoryEventDate($row);
            $key = $this->buildApiAuditCompareKey(
                $type,
                (string) ($row['chave_acesso'] ?? ''),
                (string) ($row['numero_nf'] ?? ''),
                $eventDate
            );
            if ($key === '') {
                continue;
            }

            $row['_compare_key'] = $key;
            $row['_event_date'] = $eventDate;
            $localComparable[$key] = $row;
        }

        $missingLocal = [];
        foreach ($apiComparable as $key => $row) {
            if (!isset($localComparable[$key])) {
                $missingLocal[] = $row;
            }
        }

        $missingApi = [];
        foreach ($localComparable as $key => $row) {
            if (!isset($apiComparable[$key])) {
                $missingApi[] = $row;
            }
        }

        usort($missingLocal, static function (array $a, array $b): int {
            return strcmp((string) ($b['referencia_em'] ?? ''), (string) ($a['referencia_em'] ?? ''));
        });
        usort($missingApi, static function (array $a, array $b): int {
            return strcmp((string) ($b['_event_date'] ?? ''), (string) ($a['_event_date'] ?? ''));
        });

        return [
            'matched_count' => count(array_intersect(array_keys($apiComparable), array_keys($localComparable))),
            'missing_local' => $missingLocal,
            'missing_api' => $missingApi,
        ];
    }

    private function buildApiAuditCompareKey(string $type, string $chaveAcesso, string $numeroNf, string $referenceDate): string
    {
        $type = strtoupper(trim($type));
        $chaveAcesso = trim($chaveAcesso);
        $numeroNf = trim($numeroNf);
        $date = trim($referenceDate) !== '' ? substr(trim($referenceDate), 0, 10) : '';

        if ($chaveAcesso !== '') {
            return $type . '|CH|' . $chaveAcesso;
        }
        if ($numeroNf !== '') {
            return $type . '|NF|' . $numeroNf . '|' . $date;
        }
        if ($date !== '') {
            return $type . '|DT|' . $date;
        }

        return '';
    }

    private function buildXmlAuditData(string $selectedMonth): array
    {
        $config = (new ConfigRepository())->get();
        $environment = trim((string) ($config['environment'] ?? ''));
        $serie = trim((string) ($config['serie_dps'] ?? ''));

        $storage = new StorageService();
        $filesystem = $storage->listXmlFilesByMonth($selectedMonth, $environment, $serie);
        $relativeDir = (string) ($filesystem['relative_dir'] ?? '');

        $physicalFiles = [];
        $cancelamentoFiles = [];
        foreach ((array) ($filesystem['files'] ?? []) as $file) {
            $relativePath = $this->normalizeAuditStoragePath((string) ($file['relative_path'] ?? ''));
            if ($relativePath === '') {
                continue;
            }

            $file['relative_path'] = $relativePath;
            if ($this->isCancelamentoXmlFilename((string) ($file['filename'] ?? ''))) {
                $cancelamentoFiles[$relativePath] = $file;
            } else {
                $physicalFiles[$relativePath] = $file;
            }
        }

        $dbRows = $this->listXmlAuditDatabaseRows($relativeDir);
        $historyRows = $this->listXmlAuditHistoryRows($relativeDir);
        $dbRowsByPath = [];
        $historyRowsByPath = [];
        $unexpectedStatusRows = [];
        $missingReferenceRows = [];
        $duplicateReferences = [];
        $statusCounts = [];

        foreach ($dbRows as $row) {
            $normalizedPath = $this->normalizeAuditStoragePath((string) ($row['xml_path'] ?? ''));
            if ($normalizedPath === '') {
                continue;
            }

            $row['xml_path_normalized'] = $normalizedPath;
            $status = strtoupper(trim((string) ($row['status'] ?? '')));
            $statusKey = $status !== '' ? $status : 'SEM_STATUS';
            $statusCounts[$statusKey] = (int) ($statusCounts[$statusKey] ?? 0) + 1;
            $dbRowsByPath[$normalizedPath][] = $row;

            if (!in_array($status, ['EMITIDA', 'CANCELADA'], true)) {
                $unexpectedStatusRows[] = $row;
            }
        }

        foreach ($historyRows as $row) {
            $normalizedPath = $this->normalizeAuditStoragePath((string) ($row['xml_path'] ?? ''));
            if ($normalizedPath === '') {
                continue;
            }

            $row['xml_path_normalized'] = $normalizedPath;
            $historyRowsByPath[$normalizedPath][] = $row;
        }

        $orphanFiles = [];
        foreach ($physicalFiles as $relativePath => $file) {
            if (!isset($dbRowsByPath[$relativePath]) && !isset($historyRowsByPath[$relativePath])) {
                $orphanFiles[] = $file;
            }
        }

        foreach ($dbRowsByPath as $relativePath => $rows) {
            if (!isset($physicalFiles[$relativePath])) {
                foreach ($rows as $row) {
                    $missingReferenceRows[] = $row;
                }
            }

            if (count($rows) > 1) {
                $duplicateReferences[] = [
                    'xml_path' => $relativePath,
                    'count' => count($rows),
                    'rows' => $rows,
                ];
            }
        }

        ksort($statusCounts, SORT_NATURAL | SORT_FLAG_CASE);

        usort($unexpectedStatusRows, static function (array $a, array $b): int {
            return strcmp((string) ($b['nfse_updated_at'] ?? $b['emitida_em'] ?? ''), (string) ($a['nfse_updated_at'] ?? $a['emitida_em'] ?? ''));
        });
        usort($missingReferenceRows, static function (array $a, array $b): int {
            return strcmp((string) ($b['nfse_updated_at'] ?? $b['emitida_em'] ?? ''), (string) ($a['nfse_updated_at'] ?? $a['emitida_em'] ?? ''));
        });
        usort($orphanFiles, static function (array $a, array $b): int {
            return strcmp((string) ($b['modified_at'] ?? ''), (string) ($a['modified_at'] ?? ''));
        });
        usort($duplicateReferences, static function (array $a, array $b): int {
            return strcmp((string) ($a['xml_path'] ?? ''), (string) ($b['xml_path'] ?? ''));
        });

        return [
            'relative_dir' => $relativeDir,
            'environment_label' => $environment !== '' ? $environment : 'padrao',
            'serie_label' => $serie !== '' ? $serie : 'sem-serie',
            'physical_count' => count($physicalFiles) + count($cancelamentoFiles),
            'emissao_file_count' => count($physicalFiles),
            'cancelamento_file_count' => count($cancelamentoFiles),
            'db_reference_count' => count($dbRowsByPath),
            'orphan_count' => count($orphanFiles),
            'missing_reference_count' => count($missingReferenceRows),
            'unexpected_status_count' => count($unexpectedStatusRows),
            'duplicate_reference_count' => count($duplicateReferences),
            'status_counts' => $statusCounts,
            'orphan_files' => $orphanFiles,
            'cancelamento_files' => array_values($cancelamentoFiles),
            'missing_reference_rows' => $missingReferenceRows,
            'unexpected_status_rows' => $unexpectedStatusRows,
            'duplicate_references' => $duplicateReferences,
        ];
    }

    private function listXmlAuditDatabaseRows(string $relativeDir): array
    {
        if ($relativeDir === '') {
            return [];
        }

        $rows = [];
        $query = Capsule::table('mod_opennfse_notas as n')
            ->join('tblclients as c', 'c.id', '=', 'n.userid')
            ->select([
                'n.invoiceid',
                'n.userid',
                'n.numero_nf',
                'n.status',
                'n.emitida_em',
                'n.cancelado_em',
                'n.xml_path',
                'n.updated_at as nfse_updated_at',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->whereNotNull('n.xml_path')
            ->where('n.xml_path', '<>', '')
            ->where(static function ($where) use ($relativeDir) {
                $where->where('n.xml_path', 'like', $relativeDir . '/%')
                    ->orWhere('n.xml_path', 'like', 'attachments/' . $relativeDir . '/%');
            })
            ->orderByRaw('COALESCE(n.emitida_em, n.updated_at) DESC');

        foreach ($query->get() as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    private function listXmlAuditHistoryRows(string $relativeDir): array
    {
        if ($relativeDir === '') {
            return [];
        }

        $rows = [];
        $query = Capsule::table('mod_opennfse_notas_history as h')
            ->select([
                'h.invoiceid',
                'h.userid',
                'h.numero_nf',
                'h.status_fiscal as status',
                'h.emitida_em',
                'h.cancelado_em',
                'h.xml_path',
                'h.updated_at as nfse_updated_at',
            ])
            ->where('h.tipo_registro', 'EMISSAO')
            ->whereNotNull('h.xml_path')
            ->where('h.xml_path', '<>', '')
            ->where(static function ($where) use ($relativeDir) {
                $where->where('h.xml_path', 'like', $relativeDir . '/%')
                    ->orWhere('h.xml_path', 'like', 'attachments/' . $relativeDir . '/%');
            })
            ->orderByRaw('COALESCE(h.emitida_em, h.updated_at) DESC');

        foreach ($query->get() as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    private function buildOrphanXmlCancelContext(string $xmlPath): array
    {
        $normalizedPath = $this->normalizeAuditStoragePath($xmlPath);
        if ($normalizedPath === '') {
            throw new NfseModuleException('Caminho do XML órfão inválido.');
        }

        $storage = new StorageService();
        $absolutePath = $storage->resolveAbsolutePath($normalizedPath);
        $xml = @file_get_contents($absolutePath);
        if (!is_string($xml) || trim($xml) === '') {
            throw new NfseModuleException('Não foi possível ler o XML órfão.');
        }

        $chaveAcesso = trim((string) (\OpenNfse\Helpers\NfseXmlExtractor::extractChaveAcesso($xml) ?? ''));
        $historyRepo = new FiscalHistoryRepository();
        $history = $historyRepo->findLatestEmissionByXmlPathOrChave($normalizedPath, $chaveAcesso !== '' ? $chaveAcesso : null) ?? [];
        $idDps = trim((string) (($history['id_dps'] ?? '') !== '' ? $history['id_dps'] : (\OpenNfse\Helpers\NfseXmlExtractor::extractIdDps($xml) ?? '')));
        if ($chaveAcesso === '' && $idDps !== '') {
            $chaveAcesso = trim((string) ((new NfseService())->consultarChaveAcessoPorIdDps($idDps) ?? ''));
        }
        if ($chaveAcesso === '') {
            throw new NfseModuleException('Chave de acesso não encontrada no XML órfão e a DPS ainda não retornou a chave.');
        }
        if (empty($history)) {
            $history = $historyRepo->findLatestEmissionByXmlPathOrChave($normalizedPath, $chaveAcesso) ?? [];
        }
        $invoiceId = (int) ($history['invoiceid'] ?? $this->extractInvoiceIdFromFilename(basename($absolutePath)));

        return [
            'filename' => basename($absolutePath),
            'xml_path' => $normalizedPath,
            'invoiceid' => $invoiceId,
            'numero_nf' => (string) (($history['numero_nf'] ?? '') !== '' ? $history['numero_nf'] : (\OpenNfse\Helpers\NfseXmlExtractor::extractNumeroNfse($xml) ?? '')),
            'id_dps' => $idDps,
            'chave_acesso' => $chaveAcesso,
            'emitida_em' => (string) (($history['emitida_em'] ?? '') !== '' ? $history['emitida_em'] : (\OpenNfse\Helpers\NfseXmlExtractor::extractEmitidaEm($xml) ?? '')),
        ];
    }

    private function extractInvoiceIdFromFilename(string $filename): int
    {
        $filename = trim($filename);
        if ($filename === '') {
            return 0;
        }

        if (preg_match('/^nfse_.+_(\d+)_[0-9]{6}\.xml$/i', $filename, $matches) === 1) {
            return (int) ($matches[1] ?? 0);
        }

        return 0;
    }

    private function normalizeAuditStoragePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if (strpos($path, 'attachments/') === 0) {
            $path = ltrim(substr($path, strlen('attachments/')), '/');
        }

        return $path;
    }

    private function isCancelamentoXmlFilename(string $filename): bool
    {
        return strpos(strtolower(trim($filename)), 'cancelamento_nfse_') === 0;
    }

    private function buildXmlAuditStatusSummary(array $statusCounts): string
    {
        if (empty($statusCounts)) {
            return '';
        }

        $parts = [];
        foreach ($statusCounts as $status => $count) {
            $parts[] = $status . ': ' . (int) $count;
        }

        return implode(' | ', $parts);
    }

    private function getXmlAuditDefaultMonth(): string
    {
        try {
            return (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        } catch (\Throwable $e) {
            return date('Y-m');
        }
    }

    private function formatAuditBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }
        if ($bytes < 1073741824) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }

        return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
    }

    private function getAuditoriaActiveGatewayMap(): array
    {
        $gatewaySettingsRepo = new PaymentGatewaySettingsRepository();
        $map = [];

        foreach ((new WhmcsPaymentGatewayRepository())->listActive() as $gateway) {
            $key = strtolower(trim((string) ($gateway['gateway'] ?? '')));
            if ($key === '' || !$gatewaySettingsRepo->isEnabled($key)) {
                continue;
            }

            $name = trim((string) ($gateway['name'] ?? ''));
            $map[$key] = $name !== '' ? $name : $key;
        }

        if (!empty($map)) {
            asort($map, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $map;
    }

    private function resolveAuditoriaMonthRange(string $month): array
    {
        $month = trim($month);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if (!$start instanceof \DateTimeImmutable) {
            $month = date('Y-m');
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        }

        if (!$start instanceof \DateTimeImmutable) {
            return [date('Y-m'), date('Y-m-01'), date('Y-m-t')];
        }

        return [
            $start->format('Y-m'),
            $start->format('Y-m-d'),
            $start->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    private function formatAuditoriaMonthLabel(string $month): string
    {
        $month = trim($month);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $month !== '' ? $month : date('m/Y');
        }

        try {
            return (new \DateTimeImmutable($month . '-01'))->format('m/Y');
        } catch (\Throwable $e) {
            return $month;
        }
    }

    private function buildRecentCompetenciaZipPresets(): array
    {
        $presets = [];
        $monthNames = [
            1 => 'Janeiro',
            2 => 'Fevereiro',
            3 => 'Marco',
            4 => 'Abril',
            5 => 'Maio',
            6 => 'Junho',
            7 => 'Julho',
            8 => 'Agosto',
            9 => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro',
        ];

        try {
            $base = new \DateTimeImmutable('first day of this month');
            for ($offset = 3; $offset >= 1; $offset--) {
                $monthStart = $base->modify('-' . $offset . ' month');
                if (!$monthStart instanceof \DateTimeImmutable) {
                    continue;
                }

                $monthNumber = (int) $monthStart->format('n');
                $presets[] = [
                    'label' => $monthNames[$monthNumber] ?? $monthStart->format('m/Y'),
                    'data_inicial' => $monthStart->format('Y-m-01'),
                    'data_final' => $monthStart->modify('last day of this month')->format('Y-m-d'),
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $presets;
    }

    private function getCompetenciaZipDirectories(): array
    {
        return [
            'Emitidas',
            'Canceladas',
            'Emitidas para Estrangeiros',
        ];
    }

    private function resolveCompetenciaZipDirectory(array $row): string
    {
        $status = strtoupper(trim((string) ($row['status'] ?? '')));
        if ($status === 'CANCELADA') {
            return 'Canceladas';
        }

        if ($this->isExteriorReportRow($row)) {
            return 'Emitidas para Estrangeiros';
        }

        return 'Emitidas';
    }

    private function extractZipXmlPathsFromRow(array $row): array
    {
        $paths = [];
        foreach (['xml_path', 'cancel_xml_path'] as $field) {
            $path = trim((string) ($row[$field] ?? ''));
            if ($path === '') {
                continue;
            }

            $paths[$path] = $path;
        }

        return array_values($paths);
    }

    private function isExteriorReportRow(array $row): bool
    {
        $country = strtoupper(trim((string) ($row['country'] ?? '')));
        return $country !== 'BR';
    }

    private function buildZipEntryName(\ZipArchive $zip, array $row, string $absPath, string $directory = ''): string
    {
        $directory = trim($directory, '/');
        $filename = basename($absPath);
        $entryName = $directory !== '' ? ($directory . '/' . $filename) : $filename;
        if ($zip->locateName($entryName) === false) {
            return $entryName;
        }

        $filenameWithInvoice = 'invoice_' . (int) ($row['invoiceid'] ?? 0) . '_' . $filename;
        $entryName = $directory !== '' ? ($directory . '/' . $filenameWithInvoice) : $filenameWithInvoice;
        if ($zip->locateName($entryName) === false) {
            return $entryName;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $invoiceId = (int) ($row['invoiceid'] ?? 0);
        for ($index = 2; $index <= 999; $index++) {
            $candidate = $baseName . '_invoice_' . $invoiceId . '_' . $index;
            if ($extension !== '') {
                $candidate .= '.' . $extension;
            }
            $entryName = $directory !== '' ? ($directory . '/' . $candidate) : $candidate;
            if ($zip->locateName($entryName) === false) {
                return $entryName;
            }
        }

        return $directory !== '' ? ($directory . '/' . $filenameWithInvoice) : $filenameWithInvoice;
    }

    private function buildCompetenciaZipFilename(string $dataInicial): string
    {
        $competencia = $this->extractCompetenciaMonthToken($dataInicial);
        return 'nfse_xml_' . $competencia . '.zip';
    }

    private function extractCompetenciaMonthToken(string $dataInicial): string
    {
        $dataInicial = trim($dataInicial);
        if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $dataInicial, $matches)) {
            return $matches[2] . $matches[1];
        }

        try {
            return (new \DateTimeImmutable($dataInicial))->format('mY');
        } catch (\Throwable $e) {
            return date('mY');
        }
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
        $this->renderTabs('relatorios');

        $back = 'invoices.php?action=edit&id=' . $invoiceId;
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
