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

        echo '<div style="margin-bottom:14px;border:1px solid #ddd;padding:12px;background:#fafafa;">';
        echo '<form id="nfse-fila-filters" method="get" action="addonmodules.php" style="margin:0;">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="fila" />';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px;">';
        echo '<div style="min-width:120px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Invoice ID</div>';
        echo '<input type="text" name="invoiceid" placeholder="Invoice ID" value="' . htmlspecialchars($invoiceFilter, ENT_QUOTES, 'UTF-8') . '" style="width:120px;" />';
        echo '</div>';
        echo '<div style="min-width:180px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Status</div>';
        echo '<select name="status" style="width:180px;">';
        $statusOptions = [
            '' => 'Todos os status',
            'PENDING' => 'PENDING',
            'RUNNING' => 'RUNNING',
            'WAIT_STATUS' => 'WAIT_STATUS',
            'ERROR' => 'ERROR',
            'DONE' => 'DONE',
        ];
        foreach ($statusOptions as $val => $label) {
            $sel = $val === $statusFilter ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
        echo '<div style="display:flex;gap:6px;justify-content:flex-end;align-items:center;flex-wrap:wrap;">';
        echo '<button type="submit" form="nfse-fila-filters" class="btn btn-xs btn-default">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" href="addonmodules.php?module=OpenNfse&action=fila">Limpar</a>';
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
        echo '<button type="submit" class="btn btn-xs btn-primary">Processar a fila manualmente agora</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';


        $q = Capsule::table('mod_opennfse_queue')
            ->orderBy('updated_at', 'desc')
            ->orderBy('created_at', 'desc');
        if ($statusFilter !== '') {
            $q->where('status', $statusFilter);
        }
        if ($invoiceFilter !== '' && ctype_digit($invoiceFilter)) {
            $q->where('invoiceid', (int) $invoiceFilter);
        }
        $rows = $q->limit(100)->get();

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:4%;text-align:center;">ID</th>';
        echo '<th style="width:8%;">Invoice</th>';
        echo '<th style="width:10%;text-align:center;">Status</th>';
        echo '<th style="width:6%;text-align:center;">Tentativas</th>';
        echo '<th style="width:12%;">Última</th>';
        echo '<th style="width:6%;text-align:center;">Checks</th>';
        echo '<th style="width:12%;">Próxima</th>';
        echo '<th style="width:28%;">Erro</th>';
        echo '<th style="width:14%;">Ações</th>';
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

        foreach ($rows as $r) {
            $id = (int) ($r->id ?? 0);
            $invoiceId = (int) ($r->invoiceid ?? 0);
            $status = (string) ($r->status ?? '');
            $tentativas = (string) ($r->tentativas ?? '');
            $ultima = (string) ($r->ultima_tentativa ?? '');
            $checks = (string) ($r->status_checks ?? '');
            $nextCheckAt = (string) ($r->next_check_at ?? '');
            $erro = (string) ($r->last_error ?? '');

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
            } elseif ($status === 'WAIT_STATUS') {
                $rowStyle = ' style="background:#fffdf5;"';
            }

            echo '<tr' . $rowStyle . '>';
            echo '<td style="text-align:center;vertical-align:top;">' . (int) $id . '</td>';
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            echo '<td style="vertical-align:top;"><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '" style="font-weight:600;">' . (int) $invoiceId . '</a></td>';
            echo '<td style="text-align:center;vertical-align:top;">' . $renderStatusBadge($status) . '</td>';
            echo '<td style="text-align:center;vertical-align:top;">' . htmlspecialchars($tentativas, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="vertical-align:top;">' . $renderDateCell($ultima) . '</td>';
            echo '<td style="text-align:center;vertical-align:top;">' . htmlspecialchars($checks, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="vertical-align:top;">' . $renderDateCell($nextCheckAt, $nextMeta) . '</td>';
            if (trim($erro) === '') {
                echo '<td style="vertical-align:top;word-break:break-word;overflow-wrap:anywhere;"><span style="color:#999;">-</span></td>';
            } else {
                $short = $truncateError($erro);
                echo '<td style="vertical-align:top;word-break:break-word;overflow-wrap:anywhere;">';
                echo '<div style="display:flex;flex-direction:column;align-items:flex-start;gap:6px;">';
                echo '<span title="' . htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;max-width:100%;line-height:1.35;color:#444;">' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</span>';
                echo '<button type="button" class="btn btn-xs btn-default nfse-copy" data-copy="' . htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') . '">Copiar erro</button>';
                echo '</div>';
                echo '</td>';
            }
            echo '<td style="vertical-align:top;">';
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
                echo '<button type="submit" class="btn btn-xs btn-default">Reprocessar</button>';
                echo '</form>';
            }
            echo '</div>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
        echo '<script>(function(){function c(t){if(!t){return;}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t);return;}var a=document.createElement(\'textarea\');a.value=t;a.style.position=\'fixed\';a.style.left=\'-9999px\';document.body.appendChild(a);a.focus();a.select();try{document.execCommand(\'copy\');}catch(e){}document.body.removeChild(a);}var b=document.querySelectorAll(\'.nfse-copy[data-copy]\');for(var i=0;i<b.length;i++){b[i].addEventListener(\'click\',function(){c(this.getAttribute(\'data-copy\')||\'\');});}})();</script>';

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
            $status = $nota ? (string) ($nota['status'] ?? '') : '';
            if ($status === 'EMITIDA') {
                $queueRepo->markDone($queueId);
            } elseif ($status === 'PROCESSANDO') {
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
        if ((string) ($row['status'] ?? '') === 'DONE') {
            Module::ui()->renderError('Este item já está DONE (NFS-e emitida). Reprocessar está bloqueado para evitar duplicidade.');
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
