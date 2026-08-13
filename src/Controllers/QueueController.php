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

final class QueueController
{
    use AdminHelpersTrait;

    public function showFila(): void
    {
        Module::ui()->renderHeader('OpenNFS-e');
        $this->renderTabs('fila');

        $token = (new TokenService())->token();

        $msg = (string) ($_REQUEST['msg'] ?? '');
        if ($msg === 'check_done') {
            echo '<div class="successbox">Consulta executada. Verifique o status na lista e/ou na fatura.</div>';
        } elseif ($msg === 'check_error') {
            echo '<div class="errorbox">Erro ao consultar status. Verifique os logs do módulo.</div>';
        } elseif ($msg === 'process_done') {
            echo '<div class="successbox">Processamento manual da fila executado.</div>';
        } elseif ($msg === 'process_error') {
            echo '<div class="errorbox">Erro ao processar a fila manualmente. Verifique os logs do módulo.</div>';
        }

        $statusFilter = trim((string) ($_REQUEST['status'] ?? ''));
        $invoiceFilter = trim((string) ($_REQUEST['invoiceid'] ?? ''));
        $updatedFrom = trim((string) ($_REQUEST['updated_from'] ?? ''));
        $updatedTo = trim((string) ($_REQUEST['updated_to'] ?? ''));
        if ($updatedFrom === '') {
            $updatedFrom = date('Y-m-d', strtotime('-7 days'));
        }
        if ($updatedTo === '') {
            $updatedTo = date('Y-m-d');
        }
        $perPage = (int) ($_REQUEST['per_page'] ?? 50);
        if (!in_array($perPage, [50, 100, 500], true)) {
            $perPage = 50;
        }
        $page = (int) ($_REQUEST['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);">';
        echo '<form id="nfse-fila-filters" method="get" action="addonmodules.php" style="margin:0;">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="fila" />';
        echo '<input type="hidden" name="per_page" value="' . htmlspecialchars((string) $perPage, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;flex:1 1 760px;">';
        echo '<div style="min-width:130px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Invoice ID</div>';
        echo '<input type="text" name="invoiceid" placeholder="Invoice ID" value="' . htmlspecialchars($invoiceFilter, ENT_QUOTES, 'UTF-8') . '" style="width:130px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="min-width:190px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Status</div>';
        echo '<select name="status" style="width:190px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;">';
        $statusOptions = [
            '' => 'Todos os status',
            'PENDING' => 'PENDING',
            'RUNNING' => 'RUNNING',
            'WAIT_STATUS' => 'WAIT_STATUS',
            'ERROR' => 'ERROR',
            'RESOLVIDO' => 'RESOLVIDO',
            'DONE' => 'DONE',
        ];
        foreach ($statusOptions as $val => $label) {
            $sel = $val === $statusFilter ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div style="min-width:160px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Atualizado de</div>';
        echo '<input type="date" name="updated_from" value="' . htmlspecialchars($updatedFrom, ENT_QUOTES, 'UTF-8') . '" style="width:160px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '<div style="min-width:160px;">';
        echo '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Atualizado até</div>';
        echo '<input type="date" name="updated_to" value="' . htmlspecialchars($updatedTo, ENT_QUOTES, 'UTF-8') . '" style="width:160px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" />';
        echo '</div>';
        echo '</div>';
        echo '</form>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<button type="submit" form="nfse-fila-filters" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=fila">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '<div style="margin-top:12px;padding:12px 14px;border:1px solid #bfd7ea;border-radius:8px;background:#fff;display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:0 1px 2px rgba(15,23,42,0.04);">';
        echo '<div style="flex:1 1 420px;">';
        echo '<div style="font-size:11px;color:#5f6b7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Ação operacional</div>';
        echo '<div style="font-size:15px;font-weight:700;color:#1f2937;margin-bottom:4px;">Processamento manual da fila</div>';
        echo '<div style="font-size:12px;color:#5b6573;line-height:1.5;">Use este atalho para executar imediatamente um lote da fila e atualizar a visão operacional sem esperar o próximo cron.</div>';
        echo '</div>';
        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=filaProcessNow" style="margin:0;">';
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
        }
        if ($invoiceFilter !== '') {
            echo '<input type="hidden" name="return_invoiceid" value="' . htmlspecialchars($invoiceFilter, ENT_QUOTES, 'UTF-8') . '" />';
        }
        if ($statusFilter !== '') {
            echo '<input type="hidden" name="return_status" value="' . htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') . '" />';
        }
        echo '<input type="hidden" name="return_updated_from" value="' . htmlspecialchars($updatedFrom, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<input type="hidden" name="return_updated_to" value="' . htmlspecialchars($updatedTo, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<input type="hidden" name="return_page" value="' . $page . '" />';
        echo '<input type="hidden" name="return_per_page" value="' . $perPage . '" />';
        echo '<button type="submit" class="btn btn-primary">Processar a fila manualmente agora</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';


        $applyFilters = static function ($query) use ($statusFilter, $invoiceFilter, $updatedFrom, $updatedTo): void {
            if ($statusFilter !== '') {
                $query->where('status', $statusFilter);
            }
            if ($invoiceFilter !== '' && ctype_digit($invoiceFilter)) {
                $query->where('invoiceid', (int) $invoiceFilter);
            }
            if ($updatedFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $updatedFrom)) {
                $query->where('updated_at', '>=', $updatedFrom . ' 00:00:00');
            }
            if ($updatedTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $updatedTo)) {
                $query->where('updated_at', '<=', $updatedTo . ' 23:59:59');
            }
        };

        $summaryQuery = Capsule::table('mod_opennfse_queue');
        $applyFilters($summaryQuery);
        $totalRows = (int) $summaryQuery->count();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $statusRowsQuery = Capsule::table('mod_opennfse_queue')
            ->select('status', Capsule::raw('COUNT(*) as total'));
        $applyFilters($statusRowsQuery);
        $statusRows = $statusRowsQuery
            ->groupBy('status')
            ->get();
        $statusTotals = [];
        foreach ($statusRows as $statusRow) {
            $statusTotals[(string) ($statusRow->status ?? '')] = (int) ($statusRow->total ?? 0);
        }

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px;">';
        $summaryCards = [
            ['label' => 'Itens filtrados', 'value' => $totalRows, 'color' => '#1f2937', 'border' => '#dbe5f0'],
            ['label' => 'PENDENTES', 'value' => (int) ($statusTotals['PENDING'] ?? 0), 'color' => '#8a5a00', 'border' => '#f3e2b8'],
            ['label' => 'WAIT_STATUS', 'value' => (int) ($statusTotals['WAIT_STATUS'] ?? 0), 'color' => '#8a6d3b', 'border' => '#f2e7c7'],
            ['label' => 'ERROS', 'value' => (int) ($statusTotals['ERROR'] ?? 0), 'color' => '#b42318', 'border' => '#f1c7c3'],
            ['label' => 'CONCLUÍDOS', 'value' => (int) (($statusTotals['DONE'] ?? 0) + ($statusTotals['RESOLVIDO'] ?? 0)), 'color' => '#176b46', 'border' => '#cce5d6'],
        ];
        foreach ($summaryCards as $card) {
            echo '<div style="border:1px solid ' . htmlspecialchars((string) $card['border'], ENT_QUOTES, 'UTF-8') . ';border-radius:8px;background:#fff;padding:12px 14px;box-shadow:0 1px 2px rgba(15,23,42,0.04);">';
            echo '<div style="font-size:11px;color:#667085;text-transform:uppercase;letter-spacing:.02em;margin-bottom:6px;">' . htmlspecialchars((string) $card['label'], ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<div style="font-size:26px;line-height:1.1;font-weight:700;color:' . htmlspecialchars((string) $card['color'], ENT_QUOTES, 'UTF-8') . ';">' . (int) $card['value'] . '</div>';
            echo '</div>';
        }
        echo '</div>';

        $baseParams = [
            'module' => 'OpenNfse',
            'action' => 'fila',
            'invoiceid' => $invoiceFilter,
            'status' => $statusFilter,
            'updated_from' => $updatedFrom,
            'updated_to' => $updatedTo,
            'per_page' => $perPage,
        ];
        $applyJoinedFilters = static function ($query) use ($statusFilter, $invoiceFilter, $updatedFrom, $updatedTo): void {
            if ($statusFilter !== '') {
                $query->where('q.status', $statusFilter);
            }
            if ($invoiceFilter !== '' && ctype_digit($invoiceFilter)) {
                $query->where('q.invoiceid', (int) $invoiceFilter);
            }
            if ($updatedFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $updatedFrom)) {
                $query->where('q.updated_at', '>=', $updatedFrom . ' 00:00:00');
            }
            if ($updatedTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $updatedTo)) {
                $query->where('q.updated_at', '<=', $updatedTo . ' 23:59:59');
            }
        };
        $q = Capsule::table('mod_opennfse_queue as q')
            ->leftJoin('mod_opennfse_notas as n', 'n.invoiceid', '=', 'q.invoiceid')
            ->select([
                'q.*',
                Capsule::raw('n.status as nota_status'),
                Capsule::raw('n.id_dps as nota_id_dps'),
                Capsule::raw('n.numero_nf as nota_numero_nf'),
                Capsule::raw('n.chave_acesso as nota_chave_acesso'),
                Capsule::raw('n.erro_api as nota_erro_api'),
                Capsule::raw('n.e2404_reemit_attempts as nota_e2404_reemit_attempts'),
            ])
            ->orderBy('q.id', 'desc');
        $applyJoinedFilters($q);
        $rows = $q->offset($offset)->limit($perPage)->get();

        $columnOptions = [
            'correlation' => 'Rastreio',
            'queue_id' => 'Fila ID',
            'invoice' => 'Invoice',
            'dps' => 'DPS atual',
            'nfse' => 'NFS-e',
            'queue_status' => 'Status Fila',
            'nota_status' => 'Status Nota',
            'tentativas' => 'Tent.',
            'ultima' => 'Última',
            'checks' => 'Checks',
            'proxima' => 'Próxima',
            'erro' => 'Erro',
            'acoes' => 'Ações',
        ];
        $defaultVisibleColumns = [
            'invoice' => true,
            'queue_status' => true,
            'tentativas' => true,
            'erro' => true,
        ];

        echo '<div style="margin-bottom:10px;display:flex;justify-content:flex-end;align-items:center;">';
        echo '<div id="nfse-fila-columns-wrap" style="position:relative;">';
        echo '<button type="button" id="nfse-fila-columns-toggle" class="btn btn-xs btn-default" style="height:32px;padding:0 12px;line-height:30px;">Colunas</button>';
        echo '<div id="nfse-fila-columns-popover" style="display:none;position:absolute;right:0;top:36px;z-index:30;width:220px;max-height:320px;overflow:auto;padding:10px 12px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;box-shadow:0 10px 28px rgba(15,23,42,0.14);">';
        echo '<div style="display:flex;flex-direction:column;gap:6px;">';
        foreach ($columnOptions as $columnKey => $columnLabel) {
            $checked = isset($defaultVisibleColumns[$columnKey]) ? ' checked' : '';
            echo '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;cursor:pointer;">';
            echo '<input class="nfse-fila-column-toggle" type="checkbox" value="' . htmlspecialchars($columnKey, ENT_QUOTES, 'UTF-8') . '"' . $checked . ' />';
            echo '<span>' . htmlspecialchars($columnLabel, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<div style="display:flex;justify-content:space-between;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid #eef2f7;">';
        echo '<button type="button" id="nfse-fila-columns-default" class="btn btn-xs btn-default">Padrão</button>';
        echo '<button type="button" id="nfse-fila-columns-all" class="btn btn-xs btn-default">Todas</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<table id="nfse-fila-table" class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th data-col="correlation" style="width:16%;">Rastreio</th>';
        echo '<th data-col="queue_id" style="width:5%;text-align:center;">Fila ID</th>';
        echo '<th data-col="invoice" style="width:7%;">Invoice</th>';
        echo '<th data-col="dps" style="width:14%;">DPS atual</th>';
        echo '<th data-col="nfse" style="width:6%;text-align:center;">NFS-e</th>';
        echo '<th data-col="queue_status" style="width:8%;text-align:center;">Status fila</th>';
        echo '<th data-col="nota_status" style="width:8%;text-align:center;">Status nota</th>';
        echo '<th data-col="tentativas" style="width:5%;text-align:center;">Tent.</th>';
        echo '<th data-col="ultima" style="width:9%;">Última</th>';
        echo '<th data-col="checks" style="width:4%;text-align:center;">Checks</th>';
        echo '<th data-col="proxima" style="width:9%;">Próxima</th>';
        echo '<th data-col="erro" style="width:15%;">Erro</th>';
        echo '<th data-col="acoes" style="width:10%;">Ações</th>';
        echo '</tr>';

        $truncateError = static function (string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '-';
            }
            $max = 48;
            if (mb_strlen($value) <= $max) {
                return $value;
            }
            return mb_substr($value, 0, $max) . '...';
        };
        $renderStatusBadge = static function (string $value): string {
            $styles = [
                'DONE' => ['bg' => '#e8f5e9', 'color' => '#2e7d32'],
                'RESOLVIDO' => ['bg' => '#e3f2fd', 'color' => '#1565c0'],
                'ERROR' => ['bg' => '#fdecea', 'color' => '#c62828'],
                'WAIT_STATUS' => ['bg' => '#fff8e1', 'color' => '#8a6d3b'],
                'RUNNING' => ['bg' => '#e8f1fb', 'color' => '#23527c'],
                'PENDING' => ['bg' => '#eef2f7', 'color' => '#5f6b7a'],
            ];
            $style = $styles[$value] ?? ['bg' => '#f1f3f5', 'color' => '#555'];

            return '<span style="display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;background:' . $style['bg'] . ';color:' . $style['color'] . ';white-space:nowrap;">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</span>';
        };
        $renderDateCell = static function (string $value, string $meta = ''): string {
            $value = trim($value);
            $meta = trim($meta);
            if ($value === '') {
                return '<span style="color:#999;">-</span>';
            }

            $ts = strtotime($value);
            if ($ts === false) {
                $html = '<div style="line-height:1.3;">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</div>';
                if ($meta !== '') {
                    $html .= '<div style="font-size:11px;color:#777;line-height:1.3;">' . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '</div>';
                }

                return $html;
            }

            $html = '<div style="line-height:1.2;">';
            $html .= '<div>' . htmlspecialchars(date('d/m/Y', $ts), ENT_QUOTES, 'UTF-8') . '</div>';
            $html .= '<div style="font-size:11px;color:#666;">' . htmlspecialchars(date('H:i:s', $ts), ENT_QUOTES, 'UTF-8') . '</div>';
            if ($meta !== '') {
                $html .= '<div style="font-size:11px;color:#777;">' . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $html .= '</div>';

            return $html;
        };
        $containsE2404ReemitMessage = static function (string $value): bool {
            $value = trim($value);
            if ($value === '') {
                return false;
            }

            $normalized = mb_strtolower($value, 'UTF-8');

            return strpos($normalized, 'reemitindo a mesma dps') !== false
                || strpos($normalized, 'e2404 recebido na consulta da dps') !== false;
        };

        foreach ($rows as $r) {
            $id = (int) ($r->id ?? 0);
            $invoiceId = (int) ($r->invoiceid ?? 0);
            $correlationId = trim((string) ($r->correlation_id ?? ''));
            $status = (string) ($r->status ?? '');
            $notaStatus = trim((string) ($r->nota_status ?? ''));
            $notaIdDps = trim((string) ($r->nota_id_dps ?? ''));
            $notaNumeroNf = trim((string) ($r->nota_numero_nf ?? ''));
            $notaChave = trim((string) ($r->nota_chave_acesso ?? ''));
            $tentativas = (string) ($r->tentativas ?? '');
            $ultima = (string) ($r->ultima_tentativa ?? '');
            $checks = (string) ($r->status_checks ?? '');
            $nextCheckAt = (string) ($r->next_check_at ?? '');
            $erroFila = trim((string) ($r->last_error ?? ''));
            $erroNota = trim((string) ($r->nota_erro_api ?? ''));
            $e2404ReemitAttempts = (int) ($r->nota_e2404_reemit_attempts ?? 0);
            $isE2404Reemit = $e2404ReemitAttempts > 0
                && ($notaStatus === 'PROCESSANDO' || $status === 'WAIT_STATUS' || $status === 'RUNNING')
                && ($containsE2404ReemitMessage($erroNota) || $containsE2404ReemitMessage($erroFila));
            $erro = $erroFila !== '' ? $erroFila : $erroNota;

            $nextMeta = '';
            if ($nextCheckAt !== '') {
                $ts = strtotime($nextCheckAt);
                if ($ts !== false) {
                    $delta = $ts - time();
                    if ($delta > 0) {
                        $mins = (int) floor($delta / 60);
                        $secs = (int) ($delta % 60);
                        $suffix = $mins > 0 ? ($mins . 'm ' . $secs . 's') : ($secs . 's');
                        $nextMeta = 'em ' . $suffix;
                    } else {
                        $nextMeta = 'agora';
                    }
                }
            }

            $rowStyle = '';
            if ($status === 'ERROR') {
                $rowStyle = ' style="background:#fffafa;"';
            } elseif ($status === 'RESOLVIDO') {
                $rowStyle = ' style="background:#f4f9ff;"';
            } elseif ($status === 'WAIT_STATUS') {
                $rowStyle = ' style="background:#fffdf5;"';
            }
            if ($isE2404Reemit) {
                $rowStyle = ' style="background:#fff7ed;box-shadow:inset 4px 0 0 #ea580c;"';
            }

            echo '<tr' . $rowStyle . '>';
            echo '<td data-col="correlation" style="vertical-align:top;word-break:break-word;overflow-wrap:anywhere;">';
            if ($correlationId !== '') {
                echo '<div style="display:flex;flex-direction:column;align-items:flex-start;gap:6px;">';
                echo '<span title="' . htmlspecialchars($correlationId, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;max-width:100%;line-height:1.35;color:#1f2937;font-weight:600;">' . htmlspecialchars($truncateError($correlationId), ENT_QUOTES, 'UTF-8') . '</span>';
                echo '<button type="button" class="btn btn-xs btn-default nfse-copy" data-copy="' . htmlspecialchars($correlationId, ENT_QUOTES, 'UTF-8') . '">Copiar</button>';
                echo '</div>';
            } else {
                echo '<span style="color:#999;">-</span>';
            }
            echo '</td>';
            echo '<td data-col="queue_id" style="text-align:center;vertical-align:top;">' . (int) $id . '</td>';
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            echo '<td data-col="invoice" style="vertical-align:top;"><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '" style="font-weight:600;" title="Abre o estado atual da invoice e da nota vinculada">' . (int) $invoiceId . '</a></td>';
            echo '<td data-col="dps" style="vertical-align:top;word-break:break-word;overflow-wrap:anywhere;">';
            if ($notaIdDps !== '') {
                echo '<div style="display:flex;flex-direction:column;align-items:flex-start;gap:6px;">';
                echo '<span title="' . htmlspecialchars($notaIdDps, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;max-width:100%;line-height:1.35;color:#1f2937;">' . htmlspecialchars($truncateError($notaIdDps), ENT_QUOTES, 'UTF-8') . '</span>';
                echo '<button type="button" class="btn btn-xs btn-default nfse-copy" data-copy="' . htmlspecialchars($notaIdDps, ENT_QUOTES, 'UTF-8') . '">Copiar</button>';
                echo '</div>';
            } else {
                echo '<span style="color:#999;">-</span>';
            }
            echo '</td>';
            echo '<td data-col="nfse" style="text-align:center;vertical-align:top;">' . htmlspecialchars($notaNumeroNf !== '' ? $notaNumeroNf : '-', ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td data-col="queue_status" style="text-align:center;vertical-align:top;">';
            echo '<div style="display:flex;flex-direction:column;align-items:center;gap:6px;">';
            echo $renderStatusBadge($status);
            if ($isE2404Reemit) {
                echo '<span style="display:inline-block;padding:4px 9px;border-radius:999px;font-size:10px;font-weight:700;line-height:1.2;background:#ffedd5;color:#9a3412;border:1px solid #fdba74;">Reemitindo mesma DPS</span>';
            }
            echo '</div>';
            echo '</td>';
            echo '<td data-col="nota_status" style="text-align:center;vertical-align:top;">';
            echo '<div style="display:flex;flex-direction:column;align-items:center;gap:6px;">';
            echo ($notaStatus !== '' ? $renderStatusBadge($notaStatus) : '<span style="color:#999;">-</span>');
            if ($isE2404Reemit) {
                echo '<span style="font-size:10px;font-weight:700;color:#9a3412;background:#fff1e6;border:1px solid #fed7aa;border-radius:999px;padding:3px 8px;">Tentativa ' . $e2404ReemitAttempts . '/2</span>';
            }
            echo '</div>';
            echo '</td>';
            echo '<td data-col="tentativas" style="text-align:center;vertical-align:top;">' . htmlspecialchars($tentativas, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td data-col="ultima" style="vertical-align:top;">' . $renderDateCell($ultima) . '</td>';
            echo '<td data-col="checks" style="text-align:center;vertical-align:top;">' . htmlspecialchars($checks, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td data-col="proxima" style="vertical-align:top;">' . $renderDateCell($nextCheckAt, $nextMeta) . '</td>';
            if (trim($erro) === '') {
                echo '<td data-col="erro" style="vertical-align:top;word-break:break-word;overflow-wrap:anywhere;"><span style="color:#999;">-</span></td>';
            } else {
                $short = $truncateError($erro);
                echo '<td data-col="erro" style="vertical-align:top;word-break:break-word;overflow-wrap:anywhere;">';
                echo '<div style="display:flex;flex-direction:column;align-items:flex-start;gap:6px;">';
                if ($isE2404Reemit) {
                    echo '<div style="padding:7px 9px;border-radius:8px;background:#fff1e6;border:1px solid #fed7aa;color:#9a3412;font-size:11px;font-weight:700;line-height:1.35;">Reemitindo a mesma DPS apos E2404 (' . $e2404ReemitAttempts . '/2)</div>';
                }
                echo '<span title="' . htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;max-width:100%;line-height:1.35;color:#444;">' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</span>';
                echo '<button type="button" class="btn btn-xs btn-default nfse-copy" data-copy="' . htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') . '">Copiar erro</button>';
                echo '</div>';
                echo '</td>';
            }
            echo '<td data-col="acoes" style="vertical-align:top;">';
            echo '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
            if ($status === 'WAIT_STATUS') {
                echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=filaCheckNow" style="display:inline-block;margin:0;">';
                if ($token !== '') {
                    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
                }
                echo '<input type="hidden" name="queueid" value="' . (int) $id . '" />';
                if ($invoiceFilter !== '') {
                    echo '<input type="hidden" name="return_invoiceid" value="' . htmlspecialchars($invoiceFilter, ENT_QUOTES, 'UTF-8') . '" />';
                }
                if ($statusFilter !== '') {
                    echo '<input type="hidden" name="return_status" value="' . htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') . '" />';
                }
                echo '<input type="hidden" name="return_updated_from" value="' . htmlspecialchars($updatedFrom, ENT_QUOTES, 'UTF-8') . '" />';
                echo '<input type="hidden" name="return_updated_to" value="' . htmlspecialchars($updatedTo, ENT_QUOTES, 'UTF-8') . '" />';
                echo '<input type="hidden" name="return_page" value="' . $page . '" />';
                echo '<input type="hidden" name="return_per_page" value="' . $perPage . '" />';
                echo '<button type="submit" class="btn btn-xs btn-default">Consultar</button>';
                echo '</form>';
            }
            if (in_array($status, ['ERROR', 'WAIT_STATUS'], true)) {
                echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=filaRetry" style="display:inline-block;margin:0;">';
                if ($token !== '') {
                    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
                }
                echo '<input type="hidden" name="queueid" value="' . (int) $id . '" />';
                if ($invoiceFilter !== '') {
                    echo '<input type="hidden" name="return_invoiceid" value="' . htmlspecialchars($invoiceFilter, ENT_QUOTES, 'UTF-8') . '" />';
                }
                if ($statusFilter !== '') {
                    echo '<input type="hidden" name="return_status" value="' . htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') . '" />';
                }
                echo '<input type="hidden" name="return_updated_from" value="' . htmlspecialchars($updatedFrom, ENT_QUOTES, 'UTF-8') . '" />';
                echo '<input type="hidden" name="return_updated_to" value="' . htmlspecialchars($updatedTo, ENT_QUOTES, 'UTF-8') . '" />';
                echo '<input type="hidden" name="return_page" value="' . $page . '" />';
                echo '<input type="hidden" name="return_per_page" value="' . $perPage . '" />';
                echo '<button type="submit" class="btn btn-xs btn-default">Reprocessar</button>';
                echo '</form>';
            }
            echo '</div>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
        echo '<div style="margin-top:12px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
        $firstItem = $totalRows > 0 ? ($offset + 1) : 0;
        $lastItem = min($offset + $perPage, $totalRows);
        echo '<div style="font-size:14px;color:#374151;">Exibindo <strong style="color:#111827;">' . $firstItem . '-' . $lastItem . '</strong> de <strong style="color:#111827;">' . $totalRows . '</strong> itens filtrados.</div>';
        echo '<div style="display:inline-flex;align-items:center;padding:6px 10px;border:1px solid #d1d5db;border-radius:999px;background:#f9fafb;font-size:13px;font-weight:600;color:#374151;">Total filtrado: ' . $totalRows . '</div>';
        echo '</div>';
        echo '<div style="margin-top:12px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
        echo '<div style="font-size:14px;color:#374151;">Navegação da fila</div>';
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
        echo '<div style="margin-top:12px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;">';
        echo '<form method="get" action="addonmodules.php" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:0;">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="fila" />';
        echo '<input type="hidden" name="invoiceid" value="' . htmlspecialchars($invoiceFilter, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<input type="hidden" name="status" value="' . htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<input type="hidden" name="updated_from" value="' . htmlspecialchars($updatedFrom, ENT_QUOTES, 'UTF-8') . '" />';
        echo '<input type="hidden" name="updated_to" value="' . htmlspecialchars($updatedTo, ENT_QUOTES, 'UTF-8') . '" />';
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
        echo '</div>';
        echo '<script>(function(){function c(t){if(!t){return;}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t);return;}var a=document.createElement(\'textarea\');a.value=t;a.style.position=\'fixed\';a.style.left=\'-9999px\';document.body.appendChild(a);a.focus();a.select();try{document.execCommand(\'copy\');}catch(e){}document.body.removeChild(a);}function g(){var r=[];for(var i=0;i<u.length;i++){if(u[i].checked){r.push(u[i].value);}}return r;}function h(v){for(var i=0;i<u.length;i++){u[i].checked=v.indexOf(u[i].value)!==-1;}}function a(){var v=g();for(var i=0;i<p.length;i++){var n=p[i].getAttribute(\'data-col\')||\'\';p[i].style.display=v.indexOf(n)!==-1?\'\':\'none\';}try{window.localStorage.setItem(\'opennfse_queue_visible_columns\',JSON.stringify(v));}catch(e){}}var b=document.querySelectorAll(\'.nfse-copy[data-copy]\');for(var i=0;i<b.length;i++){b[i].addEventListener(\'click\',function(){c(this.getAttribute(\'data-copy\')||\'\');});}var w=document.getElementById(\'nfse-fila-columns-wrap\');var t=document.getElementById(\'nfse-fila-columns-toggle\');var o=document.getElementById(\'nfse-fila-columns-popover\');var d=document.getElementById(\'nfse-fila-columns-default\');var l=document.getElementById(\'nfse-fila-columns-all\');var u=document.querySelectorAll(\'.nfse-fila-column-toggle\');var p=document.querySelectorAll(\'#nfse-fila-table [data-col]\');var f=[\'invoice\',\'queue_status\',\'tentativas\',\'erro\'];if(!u.length||!p.length){return;}try{var m=window.localStorage.getItem(\'opennfse_queue_visible_columns\');if(m){var x=JSON.parse(m);if(Array.isArray(x)&&x.length){h(x);}}}catch(e){}a();for(var j=0;j<u.length;j++){u[j].addEventListener(\'change\',a);}if(d){d.addEventListener(\'click\',function(){h(f);a();});}if(l){l.addEventListener(\'click\',function(){var v=[];for(var i=0;i<u.length;i++){v.push(u[i].value);}h(v);a();});}if(t&&o){t.addEventListener(\'click\',function(e){e.preventDefault();o.style.display=o.style.display===\'block\'?\'none\':\'block\';});document.addEventListener(\'click\',function(e){if(w&&w.contains(e.target)){return;}o.style.display=\'none\';});}})();</script>';

        Module::ui()->renderFooter();
    }


    public function filaCheckNow(): void
    {
        $queueId = (int) ($_REQUEST['queueid'] ?? 0);
        $queueRepo = new QueueRepository();
        $row = $queueRepo->findById($queueId);
        if (!$row) {
            Module::ui()->renderError('Item da fila não encontrado.');
            return;
        }
        if ((string) ($row['status'] ?? '') !== 'WAIT_STATUS') {
            Module::ui()->renderError('Consulta manual disponível apenas para itens em WAIT_STATUS.');
            return;
        }

        $invoiceId = (int) ($row['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            Module::ui()->renderError('Invoice inválida no item da fila.');
            return;
        }

        $config = (new ConfigRepository())->get();
        $waitInterval = (int) ($config['queue_wait_status_interval_seconds'] ?? 120);
        if ($waitInterval < 30) {
            $waitInterval = 30;
        }
        if ($waitInterval > 3600) {
            $waitInterval = 3600;
        }

        $checks = (int) ($row['status_checks'] ?? 0);
        $factorPow = min($checks, 5);
        $nextInterval = $waitInterval * (2 ** $factorPow);
        if ($nextInterval > 3600) {
            $nextInterval = 3600;
        }

        if (!$queueRepo->markRunningForStatusCheck($queueId)) {
            Module::ui()->renderError('Não foi possível marcar o item como RUNNING para consulta (possível concorrência).');
            return;
        }

        try {
            (new NfseService())->consultarStatus($invoiceId);
            $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
            $status = trim((string) ($nota['status'] ?? ''));
            $chave = trim((string) ($nota['chave_acesso'] ?? ''));
            if ($status === 'EMITIDA' && $chave !== '') {
                $queueRepo->markDone($queueId);
            } elseif ($status === 'PROCESSANDO' || ($status === 'EMITIDA' && $chave === '')) {
                $queueRepo->touchWaitStatus($queueId, $nextInterval);
            } else {
                $err = $nota ? (string) ($nota['erro_api'] ?? '') : '';
                $queueRepo->markError($queueId, $err !== '' ? $err : ('Status final: ' . $status));
            }
            $this->redirectFila(['msg' => 'check_done'], true);
        } catch (\Throwable $e) {
            $classifier = new QueueErrorClassifierService();
            if ($classifier->isRetryable($e)) {
                $queueRepo->touchWaitStatus($queueId, $nextInterval, $e->getMessage());
            } else {
                $queueRepo->markError($queueId, $e->getMessage());
            }
            $this->redirectFila(['msg' => 'check_error'], true);
        }
    }

    public function filaRetry(): void
    {
        $queueId = (int) ($_REQUEST['queueid'] ?? 0);
        $queueRepo = new QueueRepository();
        $row = $queueRepo->findById($queueId);
        if (!$row) {
            Module::ui()->renderError('Item da fila não encontrado.');
            return;
        }
        if (in_array((string) ($row['status'] ?? ''), ['DONE', 'RESOLVIDO'], true)) {
            Module::ui()->renderError('Este item está em status terminal (DONE/RESOLVIDO). Reprocessar está bloqueado para evitar inconsistências e duplicidade.');
            return;
        }
        $queueRepo->resetToPending($queueId);
        Module::ui()->renderSuccess('Item da fila marcado como PENDING.');
        return;
    }

    public function filaProcessNow(): void
    {
        try {
            (new QueueService())->processBatch();
            $this->redirectFila(['msg' => 'process_done'], true);
        } catch (\Throwable $e) {
            $this->redirectFila(['msg' => 'process_error'], true);
        }
    }
}
