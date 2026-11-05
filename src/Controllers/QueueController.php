<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Module;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\GroupServiceCodeRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\PaymentGatewaySettingsRepository;
use OpenNfse\Repositories\QueueRepository;
use OpenNfse\Repositories\ReportRepository;
use OpenNfse\Repositories\ServiceNbsCatalogRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use OpenNfse\Repositories\WhmcsPaymentGatewayRepository;
use OpenNfse\Services\CronLockService;
use OpenNfse\Services\CryptoService;
use OpenNfse\Services\InvoiceEmailService;
use OpenNfse\Services\InvoiceLockService;
use OpenNfse\Services\NfseService;
use OpenNfse\Services\QueueErrorClassifierService;
use OpenNfse\Services\QueueService;
use OpenNfse\Services\QueueStatePolicy;
use OpenNfse\Services\StorageService;
use OpenNfse\Services\TokenService;
use WHMCS\Database\Capsule;
use OpenNfse\Controllers\Support\AdminHelpersTrait;
use OpenNfse\Views\Ui;

final class QueueController
{
    use AdminHelpersTrait;

    /** Status da fila e da nota → linha destacada na tabela. */
    private const ROW_TONES = [
        'ERROR' => 'danger',
        'RESOLVIDO' => 'info',
        'WAIT_STATUS' => 'info',
    ];

    public function showFila(): void
    {
        Module::ui()->renderHeader('OpenNfse');
        $this->renderTabs('fila');

        $token = (new TokenService())->token();
        echo '<div class="nfse-ui nfse-page">';

        $msg = (string) ($_REQUEST['msg'] ?? '');
        if ($msg === 'check_done') {
            echo Ui::callout('ok', 'Consulta executada', 'Verifique o status na lista e/ou na fatura.');
        } elseif ($msg === 'check_error') {
            echo Ui::callout('danger', 'Erro ao consultar status', 'Verifique os logs do módulo.');
        } elseif ($msg === 'process_done') {
            echo Ui::callout('ok', 'Processamento manual da fila executado');
        } elseif ($msg === 'process_error') {
            echo Ui::callout('danger', 'Erro ao processar a fila manualmente', 'Verifique os logs do módulo.');
        } elseif ($msg === 'process_busy') {
            echo Ui::callout('warn', 'Processamento já em andamento', 'O cron ou outro administrador está processando a fila. Aguarde alguns instantes e atualize a tela.');
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

        $statusOptions = Ui::statusOptions(['PENDING', 'RUNNING', 'WAIT_STATUS', 'ERROR', 'RESOLVIDO', 'DONE']);
        echo Ui::filterBar(
            ['id' => 'nfse-fila-filters', 'method' => 'get', 'action' => 'addonmodules.php', 'hidden' => ['module' => 'OpenNfse', 'action' => 'fila', 'per_page' => $perPage]],
            Ui::field('Fatura', Ui::input('invoiceid', $invoiceFilter, 'text', ['placeholder' => 'ID da fatura', 'inputmode' => 'numeric'], 'nfse-input--w-sm'))
            . Ui::field('Status', Ui::select('status', $statusOptions, $statusFilter, [], 'nfse-input--w-md'))
            . Ui::field('Atualizado de', Ui::input('updated_from', $updatedFrom, 'date'))
            . Ui::field('Atualizado até', Ui::input('updated_to', $updatedTo, 'date')),
            Ui::button('Filtrar', ['primary'])
            . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=fila']),
            'Filtros da fila'
        );

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

        $errorTotal = (int) ($statusTotals['ERROR'] ?? 0);
        echo Ui::kpis([
            ['label' => 'Itens filtrados', 'value' => $totalRows],
            ['label' => 'Pendentes', 'value' => (int) ($statusTotals['PENDING'] ?? 0)],
            ['label' => 'Aguardando status', 'value' => (int) ($statusTotals['WAIT_STATUS'] ?? 0)],
            ['label' => 'Erros', 'value' => $errorTotal, 'tone' => $errorTotal > 0 ? 'danger' : ''],
            ['label' => 'Concluídos', 'value' => (int) (($statusTotals['DONE'] ?? 0) + ($statusTotals['RESOLVIDO'] ?? 0))],
        ]);

        $returnFields = [];
        if ($invoiceFilter !== '') {
            $returnFields['return_invoiceid'] = $invoiceFilter;
        }
        if ($statusFilter !== '') {
            $returnFields['return_status'] = $statusFilter;
        }
        $returnFields['return_updated_from'] = $updatedFrom;
        $returnFields['return_updated_to'] = $updatedTo;
        $returnFields['return_page'] = $page;
        $returnFields['return_per_page'] = $perPage;
        $tokenField = $token !== '' ? ['token' => $token] : [];

        $processForm = '<form class="nfse-inline-form" method="post" action="addonmodules.php?module=OpenNfse&amp;action=filaProcessNow">'
            . Ui::hidden($tokenField + $returnFields)
            . Ui::button('Processar a fila agora', ['primary'])
            . '</form>';

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
            'invoice' => 'Fatura',
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

        $columnsMenu = '<div id="nfse-fila-columns-wrap" class="nfse-menu">';
        $columnsMenu .= '<button type="button" id="nfse-fila-columns-toggle" class="nfse-btn nfse-btn--sm" aria-haspopup="true" aria-expanded="false" aria-controls="nfse-fila-columns-popover">Colunas</button>';
        $columnsMenu .= '<div id="nfse-fila-columns-popover" class="nfse-menu__panel" hidden><div class="nfse-menu__list">';
        foreach ($columnOptions as $columnKey => $columnLabel) {
            $checked = isset($defaultVisibleColumns[$columnKey]) ? ' checked' : '';
            $columnsMenu .= '<label class="nfse-check"><input class="nfse-fila-column-toggle" type="checkbox" value="' . Ui::h($columnKey) . '"' . $checked . ' />'
                . '<span>' . Ui::h($columnLabel) . '</span></label>';
        }
        $columnsMenu .= '</div><div class="nfse-menu__foot">';
        $columnsMenu .= '<button type="button" id="nfse-fila-columns-default" class="nfse-btn nfse-btn--sm">Padrão</button>';
        $columnsMenu .= '<button type="button" id="nfse-fila-columns-all" class="nfse-btn nfse-btn--sm">Todas</button>';
        $columnsMenu .= '</div></div></div>';

        echo Ui::sectionHeader(
            'Itens da fila',
            '"Processar a fila agora" executa um lote na hora, sem esperar o próximo cron.',
            $columnsMenu . $processForm,
            'nfse-fila-itens'
        );

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
        $dash = '<span class="nfse-muted">-</span>';
        $renderDateCell = static function (string $value, string $meta = '') use ($dash): string {
            $value = trim($value);
            $meta = trim($meta);
            if ($value === '') {
                return $dash;
            }

            $ts = strtotime($value);
            $html = '<div class="nfse-nowrap">' . Ui::h($ts === false ? $value : date('d/m/Y', $ts)) . '</div>';
            if ($ts !== false) {
                $html .= '<div class="nfse-cell-meta">' . Ui::h(date('H:i:s', $ts)) . '</div>';
            }
            if ($meta !== '') {
                $html .= '<div class="nfse-cell-meta">' . Ui::h($meta) . '</div>';
            }

            return $html;
        };
        $renderCopyCell = static function (string $value, string $buttonLabel, bool $strong = false) use ($truncateError, $dash): string {
            if ($value === '') {
                return $dash;
            }

            return '<div class="nfse-cell-stack">'
                . '<span class="nfse-break' . ($strong ? ' nfse-mono' : '') . '" title="' . Ui::h($value) . '">' . Ui::h($truncateError($value)) . '</span>'
                . '<button type="button" class="nfse-btn nfse-btn--sm nfse-copy" data-copy="' . Ui::h($value) . '">' . Ui::h($buttonLabel) . '</button>'
                . '</div>';
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

        echo '<section class="nfse-card nfse-card--table" aria-labelledby="nfse-fila-itens">';
        echo '<div class="nfse-table-wrap"><table id="nfse-fila-table" class="nfse-table">';
        echo '<thead><tr>';
        echo '<th data-col="correlation">Rastreio</th>';
        echo '<th data-col="queue_id" class="nfse-num">Fila ID</th>';
        echo '<th data-col="invoice">Fatura</th>';
        echo '<th data-col="dps">DPS atual</th>';
        echo '<th data-col="nfse" class="nfse-num">NFS-e</th>';
        echo '<th data-col="queue_status">Status fila</th>';
        echo '<th data-col="nota_status">Status nota</th>';
        echo '<th data-col="tentativas" class="nfse-center">Tent.</th>';
        echo '<th data-col="ultima">Última</th>';
        echo '<th data-col="checks" class="nfse-num">Checks</th>';
        echo '<th data-col="proxima">Próxima</th>';
        echo '<th data-col="erro">Erro</th>';
        echo '<th data-col="acoes">Ações</th>';
        echo '</tr></thead><tbody>';

        if (count($rows) === 0) {
            echo Ui::emptyRow(count($columnOptions), 'Nenhum item da fila com estes filtros.');
        }

        foreach ($rows as $r) {
            $id = (int) ($r->id ?? 0);
            $invoiceId = (int) ($r->invoiceid ?? 0);
            $correlationId = trim((string) ($r->correlation_id ?? ''));
            $status = (string) ($r->status ?? '');
            $notaStatus = trim((string) ($r->nota_status ?? ''));
            $notaIdDps = trim((string) ($r->nota_id_dps ?? ''));
            $notaNumeroNf = trim((string) ($r->nota_numero_nf ?? ''));
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

            $rowTone = $isE2404Reemit ? 'warn' : (self::ROW_TONES[$status] ?? '');
            echo '<tr' . ($rowTone !== '' ? ' class="nfse-row--' . $rowTone . '"' : '') . '>';
            echo '<td data-col="correlation">' . $renderCopyCell($correlationId, 'Copiar', true) . '</td>';
            echo '<td data-col="queue_id" class="nfse-num">' . $id . '</td>';
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            echo '<td data-col="invoice"><a href="' . Ui::h($invoiceUrl) . '" title="Abre o estado atual da fatura e da nota vinculada"><b>' . $invoiceId . '</b></a></td>';
            echo '<td data-col="dps">' . $renderCopyCell($notaIdDps, 'Copiar', true) . '</td>';
            echo '<td data-col="nfse" class="nfse-num">' . ($notaNumeroNf !== '' ? Ui::h($notaNumeroNf) : $dash) . '</td>';
            echo '<td data-col="queue_status"><div class="nfse-cell-stack">' . Ui::statusBadge($status);
            if ($isE2404Reemit) {
                echo Ui::badge('Reemitindo mesma DPS', 'warn');
            }
            echo '</div></td>';
            echo '<td data-col="nota_status"><div class="nfse-cell-stack">' . ($notaStatus !== '' ? Ui::statusBadge($notaStatus) : $dash);
            if ($isE2404Reemit) {
                echo Ui::badge('Tentativa ' . $e2404ReemitAttempts . '/2', 'warn');
            }
            echo '</div></td>';
            echo '<td data-col="tentativas" class="nfse-center">' . Ui::h(QueueStatePolicy::attemptsLabel((int) $tentativas)) . '</td>';
            echo '<td data-col="ultima">' . $renderDateCell($ultima) . '</td>';
            echo '<td data-col="checks" class="nfse-num">' . Ui::h($checks) . '</td>';
            echo '<td data-col="proxima">' . $renderDateCell($nextCheckAt, $nextMeta) . '</td>';
            echo '<td data-col="erro">';
            if (trim($erro) === '') {
                echo $dash;
            } else {
                echo '<div class="nfse-cell-stack">';
                if ($isE2404Reemit) {
                    echo '<span class="nfse-cell-meta nfse-text-danger">Reemitindo a mesma DPS apos E2404 (' . $e2404ReemitAttempts . '/2)</span>';
                }
                echo '<span class="nfse-cell-inline"><span class="nfse-break" title="' . Ui::h($erro) . '">' . Ui::h($truncateError($erro)) . '</span>';
                echo '<button type="button" class="nfse-btn nfse-btn--icon nfse-btn--xs nfse-copy" data-copy="' . Ui::h($erro) . '" aria-label="Copiar erro" title="Copiar erro">' . Ui::icon('copy') . '</button></span>';
                echo '</div>';
            }
            echo '</td>';
            echo '<td data-col="acoes"><div class="nfse-actions">';
            if ($status === 'WAIT_STATUS') {
                echo '<form class="nfse-inline-form" method="post" action="addonmodules.php?module=OpenNfse&amp;action=filaCheckNow">';
                echo Ui::hidden($tokenField + ['queueid' => $id] + $returnFields);
                echo Ui::button('Consultar agora', ['sm']);
                echo '</form>';
            }
            $canRetry = QueueStatePolicy::manualRetryBlockReason((array) $r, time()) === null;
            if ($canRetry) {
                echo '<form class="nfse-inline-form" method="post" action="addonmodules.php?module=OpenNfse&amp;action=filaRetry"'
                    . ' onsubmit="return confirm(\'Reiniciar as tentativas automáticas deste item?\');">';
                echo Ui::hidden($tokenField + ['queueid' => $id] + $returnFields);
                echo Ui::button('Reprocessar', ['sm']);
                echo '</form>';
            }
            echo '</div></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        $firstItem = $totalRows > 0 ? ($offset + 1) : 0;
        $lastItem = min($offset + $perPage, $totalRows);
        echo Ui::tableFooter(
            Ui::rangeText($firstItem, $lastItem, $totalRows, 'itens filtrados'),
            Ui::pager($baseParams, $page, $totalPages)
            . Ui::pageForm(
                ['module' => 'OpenNfse', 'action' => 'fila', 'invoiceid' => $invoiceFilter, 'status' => $statusFilter, 'updated_from' => $updatedFrom, 'updated_to' => $updatedTo],
                $page,
                $totalPages,
                $perPage,
                [50, 100, 500]
            )
        );
        echo '</section>';
        echo '<script>(function(){function c(t){if(!t){return;}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t);return;}var a=document.createElement(\'textarea\');a.value=t;a.style.position=\'fixed\';a.style.left=\'-9999px\';document.body.appendChild(a);a.focus();a.select();try{document.execCommand(\'copy\');}catch(e){}document.body.removeChild(a);}function g(){var r=[];for(var i=0;i<u.length;i++){if(u[i].checked){r.push(u[i].value);}}return r;}function h(v){for(var i=0;i<u.length;i++){u[i].checked=v.indexOf(u[i].value)!==-1;}}function a(){var v=g();for(var i=0;i<p.length;i++){var n=p[i].getAttribute(\'data-col\')||\'\';p[i].style.display=v.indexOf(n)!==-1?\'\':\'none\';}try{window.localStorage.setItem(\'opennfse_queue_visible_columns\',JSON.stringify(v));}catch(e){}}var b=document.querySelectorAll(\'.nfse-copy[data-copy]\');for(var i=0;i<b.length;i++){b[i].addEventListener(\'click\',function(){c(this.getAttribute(\'data-copy\')||\'\');});}var w=document.getElementById(\'nfse-fila-columns-wrap\');var t=document.getElementById(\'nfse-fila-columns-toggle\');var o=document.getElementById(\'nfse-fila-columns-popover\');var d=document.getElementById(\'nfse-fila-columns-default\');var l=document.getElementById(\'nfse-fila-columns-all\');var u=document.querySelectorAll(\'.nfse-fila-column-toggle\');var p=document.querySelectorAll(\'#nfse-fila-table [data-col]\');var f=[\'invoice\',\'queue_status\',\'tentativas\',\'erro\'];if(!u.length||!p.length){return;}try{var m=window.localStorage.getItem(\'opennfse_queue_visible_columns\');if(m){var x=JSON.parse(m);if(Array.isArray(x)&&x.length){h(x);}}}catch(e){}a();for(var j=0;j<u.length;j++){u[j].addEventListener(\'change\',a);}if(d){d.addEventListener(\'click\',function(){h(f);a();});}if(l){l.addEventListener(\'click\',function(){var v=[];for(var i=0;i<u.length;i++){v.push(u[i].value);}h(v);a();});}if(t&&o){t.addEventListener(\'click\',function(e){e.preventDefault();o.hidden=!o.hidden;t.setAttribute(\'aria-expanded\',o.hidden?\'false\':\'true\');});document.addEventListener(\'click\',function(e){if(w&&w.contains(e.target)){return;}o.hidden=true;t.setAttribute(\'aria-expanded\',\'false\');});}})();</script>';

        echo '</div>';
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
            Module::ui()->renderError('Fatura inválida no item da fila.');
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
            (new NfseService())->consultarStatus($invoiceId, (string) ($row['correlation_id'] ?? ''));
            $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
            $status = trim((string) ($nota['status'] ?? ''));
            $chave = trim((string) ($nota['chave_acesso'] ?? ''));
            if ($status === 'EMITIDA' && $chave !== '') {
                // O e-mail automático já foi tratado por consultarStatus() quando a nota passou a EMITIDA.
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
        $blockReason = QueueStatePolicy::manualRetryBlockReason($row, time());
        if ($blockReason !== null) {
            Module::ui()->renderError($blockReason);
            return;
        }

        $status = (string) ($row['status'] ?? '');
        $invoiceId = (int) ($row['invoiceid'] ?? 0);

        // RUNNING travado: só reprocessa se nenhum lote estiver vivo (lock do cron livre), para não
        // mexer num item que ainda está na vez de um lote lento. Ordem cron -> fatura, como no lote.
        $cronLock = null;
        $cronLockName = '';
        if ($status === 'RUNNING') {
            $cronLock = new CronLockService();
            $cronLockName = $cronLock->cronLockName();
            if (!$cronLock->acquire($cronLockName)) {
                Module::ui()->renderError('Processamento da fila em andamento. Tente novamente em instantes.');
                return;
            }
        }

        try {
            $this->resetItemForManualRetry($queueRepo, $row, $queueId, $status, $invoiceId);
        } finally {
            if ($cronLock !== null) {
                $cronLock->release($cronLockName);
            }
        }
    }

    private function resetItemForManualRetry(
        QueueRepository $queueRepo,
        array $row,
        int $queueId,
        string $status,
        int $invoiceId
    ): void {
        $lock = new InvoiceLockService();
        if (!$lock->acquire($invoiceId)) {
            Module::ui()->renderError(InvoiceLockService::BUSY_MESSAGE);
            return;
        }

        try {
            // Uma fatura só pode ter um item ativo: um ERROR não volta se já houver outro em andamento.
            if ($status === 'ERROR' && $queueRepo->hasActiveOtherThan($invoiceId, $queueId)) {
                Module::ui()->renderError(
                    'Já existe outro item ativo na fila para esta fatura. Reprocessar este item criaria duplicidade.'
                );
                return;
            }

            $adminId = (int) ($_SESSION['adminid'] ?? 0);
            $correlationId = trim((string) ($row['correlation_id'] ?? ''));
            $logRepo = new LogRepository();
            $logRepo->insert(
                null,
                'QUEUE_MANUAL_RETRY',
                json_encode([
                    'queue_id' => $queueId,
                    'invoiceid' => $invoiceId,
                    'status_anterior' => $status,
                    'tentativas_anteriores' => (int) ($row['tentativas'] ?? 0),
                    'last_error_anterior' => $row['last_error'] ?? null,
                    'admin_id' => $adminId > 0 ? $adminId : null,
                ], JSON_UNESCAPED_UNICODE),
                null,
                $correlationId !== '' ? $correlationId : null
            );

            if (!$queueRepo->resetToPending($queueId, $status)) {
                $logRepo->insert(
                    null,
                    'QUEUE_MANUAL_RETRY_CONFLICT',
                    json_encode(
                        ['queue_id' => $queueId, 'invoiceid' => $invoiceId, 'status_anterior' => $status],
                        JSON_UNESCAPED_UNICODE
                    ),
                    'O status do item mudou antes do reprocessar.',
                    $correlationId !== '' ? $correlationId : null
                );
                Module::ui()->renderError(
                    'O status do item mudou durante a operação. Atualize a tela da fila e tente de novo.'
                );
                return;
            }
        } finally {
            $lock->release($invoiceId);
        }

        Module::ui()->renderSuccess(
            'Tentativas automáticas reiniciadas: o item voltou para PENDING e será processado no próximo ciclo da fila.'
        );
    }

    public function filaProcessNow(): void
    {
        // Mesmo lock do cron: nunca dois processamentos da fila ao mesmo tempo.
        $lock = new CronLockService();
        $lockName = $lock->cronLockName();
        if (!$lock->acquire($lockName)) {
            $this->redirectFila(['msg' => 'process_busy'], true);
            return;
        }

        $ok = true;
        try {
            (new QueueService())->processBatch();
        } catch (\Throwable $e) {
            $ok = false;
        } finally {
            $lock->release($lockName);
        }

        // O redirect encerra o script (exit), por isso fica fora do try/finally.
        $this->redirectFila(['msg' => $ok ? 'process_done' : 'process_error'], true);
    }
}
