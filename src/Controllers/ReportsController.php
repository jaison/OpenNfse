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
use OpenNfse\Services\FiscalDocumentReportService;
use OpenNfse\Services\InvoiceEmailService;
use OpenNfse\Services\NfseService;
use OpenNfse\Services\QueueErrorClassifierService;
use OpenNfse\Services\QueueService;
use OpenNfse\Services\StorageService;
use OpenNfse\Services\TokenService;
use WHMCS\Database\Capsule;
use OpenNfse\Controllers\Support\AdminHelpersTrait;
use OpenNfse\Views\ReportView;
use OpenNfse\Views\Ui;

final class ReportsController
{
    use AdminHelpersTrait;

    private string $postToken = '';

    /**
     * Botão de POST (exportações, abrir log) com o token da sessão, sem estilo inline.
     *
     * @param array<string, string|int> $params
     */
    private function postButton(string $action, string $label, array $params, string $class = 'nfse-btn nfse-btn--sm'): string
    {
        if ($this->postToken === '') {
            $this->postToken = (new TokenService())->token();
        }

        return ReportView::postButton($action, $label, $params, $this->postToken, $class);
    }

    /**
     * Rodapé com a contagem, a paginação (links com $params + page) e ferramentas extras.
     *
     * @param array<string, string|int> $params
     */
    private function pagedFooter(int $first, int $last, int $total, string $noun, array $params, int $page, int $totalPages, string $extraHtml = '', string $pageParam = 'page'): string
    {
        $pager = '';
        if ($pageParam === 'page') {
            $pager = Ui::pager($params, $page, $totalPages);
        } else {
            $link = static fn (int $p): string => 'addonmodules.php?' . http_build_query($params + [$pageParam => $p], '', '&', PHP_QUERY_RFC3986);
            $pager = '<span class="nfse-pager">'
                . ($page > 1 ? Ui::button('Anterior', ['sm'], ['href' => $link($page - 1)]) : '<span class="nfse-btn nfse-btn--sm" aria-disabled="true">Anterior</span>')
                . '<span class="nfse-pager__current">Página ' . $page . ' de ' . max(1, $totalPages) . '</span>'
                . ($page < $totalPages ? Ui::button('Próxima', ['sm'], ['href' => $link($page + 1)]) : '<span class="nfse-btn nfse-btn--sm" aria-disabled="true">Próxima</span>')
                . '</span>';
        }

        return Ui::tableFooter(Ui::rangeText($first, $last, $total, $noun), $pager . $extraHtml);
    }

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
                'summary' => 'Acompanha notas emitidas e canceladas, com totais e exportações do período.',
            ],
            'auditoria' => [
                'label' => 'Auditoria Emissão',
                'badge' => 'Conferência',
                'summary' => 'Lista faturas pagas em gateways ativos que não geraram NFS-e e também não entraram na fila.',
            ],
            NfseNaoPagasReportController::TAB => [
                'label' => 'NFS-e de faturas não pagas',
                'badge' => 'Revisão',
                'summary' => 'Lista notas emitidas cuja fatura não está paga (Unpaid, Cancelled, Refunded, Collections) e notas emitidas antes do pagamento.',
            ],
            'xml_auditoria' => [
                'label' => 'Auditoria XML',
                'badge' => 'Técnico',
                'summary' => 'Compara a pasta mensal de XMLs com o xml_path atual das notas para encontrar órfãos e referências inconsistentes.',
            ],
            'auditoria_api_dps' => [
                'label' => 'Auditoria DPS > API',
                'badge' => 'API',
                'summary' => 'Confere as DPS do período diretamente na API e verifica lacunas na sequência numérica do emissor.',
            ],
            'historico_fiscal' => [
                'label' => 'Historico Fiscal',
                'badge' => 'Fiscal',
                'summary' => 'Preserva os documentos e eventos fiscais da invoice ao longo do tempo, inclusive emissões, cancelamentos e snapshots de migracao.',
            ],
            'cancelamentos' => [
                'label' => 'Cancelamentos',
                'badge' => 'Auditoria',
                'summary' => 'Mostra cancelamentos do período com foco em conferência fiscal e contábil.',
            ],
            'falhas' => [
                'label' => 'Falhas',
                'badge' => 'Atenção',
                'summary' => 'Concentra erros de emissão, rejeições e problemas que exigem correção manual.',
            ],
            'logs' => [
                'label' => 'Logs',
                'badge' => 'Técnico',
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

        Module::ui()->renderHeader('Relatórios - OpenNfse');
        $this->renderTabs('relatorios');

        $params = $_GET;
        unset($params['module'], $params['action'], $params['tab']);

        $activeMeta = $tabMeta[$active] ?? $tabMeta['emitidas'];
        if ($active === 'auditoria_api') {
            $activeMeta = [
                'label' => 'Auditoria API (NSU)',
                'summary' => 'Compara os documentos distribuídos pela API (por NSU) com o histórico fiscal local do mês.',
            ];
        }

        $navItems = [];
        foreach ($allowed as $key => $label) {
            $p = $params;
            $p['tab'] = $key;
            $url = 'addonmodules.php?module=OpenNfse&action=relatorios';
            if (!empty($p)) {
                $url .= '&' . http_build_query($p, '', '&', PHP_QUERY_RFC3986);
            }
            $navItems[] = [
                'label' => $label,
                'href' => $url,
                'current' => $key === $active,
                'meta' => (string) ($tabMeta[$key]['badge'] ?? ''),
            ];
        }

        echo '<div class="nfse-ui nfse-page">';
        echo '<div class="nfse-withnav">';
        echo Ui::subnav($navItems, 'Relatórios');
        echo '<div class="nfse-withnav__content">';
        echo Ui::sectionHeader((string) ($activeMeta['label'] ?? 'Relatórios'), (string) ($activeMeta['summary'] ?? ''), '', 'nfse-rel-secao');

        if ($active === 'falhas') {
            $this->showRelatorioFalhas(true);
        } elseif ($active === 'cancelamentos') {
            $this->showRelatorioCancelamentos(true);
        } elseif ($active === 'auditoria') {
            $this->showRelatorioAuditoria(true);
        } elseif ($active === NfseNaoPagasReportController::TAB) {
            (new NfseNaoPagasReportController())->render();
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

        echo '</div></div></div>';

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

        $messages = [
            'status_done' => ['ok', 'Consulta de status executada.'],
            'status_error' => ['danger', 'Erro ao consultar status. Verifique os logs do módulo.'],
            'reemitir_gateway_disabled' => ['danger', 'Reemissão manual desativada para o gateway de pagamento desta fatura.'],
            'reemitir_enqueued' => ['ok', 'Reemissão manual enfileirada. O cron processará em breve.'],
            'reemitir_requested' => ['ok', 'Reemissão manual solicitada. Verifique o status e o XML na fatura.'],
            'reemitir_error' => ['danger', 'Erro ao solicitar reemissão manual. Verifique os logs do módulo.'],
            'cancel_done' => ['ok', 'Cancelamento solicitado com sucesso.'],
            'cancel_error' => ['danger', 'Erro ao cancelar NFS-e. Verifique os logs do módulo.'],
        ];
        if (isset($messages[$msg])) {
            echo Ui::callout($messages[$msg][0], $messages[$msg][1]);
        }

        $statusOptions = [
            '' => 'Todos os status',
            'EMITIDA,CANCELADA' => 'Emitidas e Canceladas',
            'EMITIDA' => 'Emitida',
            'CANCELADA' => 'Cancelada',
            'PROCESSANDO' => 'Processando',
            'REJEITADA' => 'Rejeitada',
            'ERRO' => 'Erro',
        ];
        echo Ui::filterBar(
            ['hidden' => ['module' => 'OpenNfse', 'action' => 'relatorios', 'tab' => 'emitidas', 'per_page' => $perPage]],
            Ui::field('Fatura', Ui::input('invoiceid', $invoiceFilter, 'text', ['placeholder' => 'ID da fatura'], 'nfse-input--w-sm'))
            . Ui::field('Data inicial', Ui::input('data_inicial', $dataInicial, 'date'))
            . Ui::field('Data final', Ui::input('data_final', $dataFinal, 'date'))
            . Ui::field('Status', Ui::select('status', $statusOptions, $status, [], 'nfse-input--w-md'))
            . Ui::field('Cliente', Ui::input('cliente', $cliente, 'text', ['placeholder' => 'Cliente (ID ou nome)'], 'nfse-input--w-lg')),
            Ui::button('Filtrar', ['primary']) . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas']),
            'Filtros das notas emitidas'
        );

        $recentCompetencias = $this->buildRecentCompetenciaZipPresets();
        if (!empty($recentCompetencias)) {
            $chips = '';
            foreach ($recentCompetencias as $preset) {
                $chips .= $this->postButton(
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
                    'nfse-chip'
                );
            }
            echo '<section class="nfse-card nfse-rel-shortcuts" aria-labelledby="nfse-rel-competencia">';
            echo '<div class="nfse-rel-shortcuts__text"><div class="nfse-rel-shortcuts__title" id="nfse-rel-competencia">Baixar XMLs por competência</div>';
            echo '<div class="nfse-rel-shortcuts__summary">ZIP dos 3 últimos meses fechados, com as notas emitidas e canceladas de cada mês.</div></div>';
            echo '<div class="nfse-chips" role="group" aria-label="Últimos 3 períodos fechados">' . $chips . '</div>';
            echo '</section>';
        }

        $statusParts = array_values(array_filter(array_map('trim', explode(',', $status)), static fn (string $v): bool => $v !== ''));
        $showSplitTotals = count($statusParts) === 2 && in_array('EMITIDA', $statusParts, true) && in_array('CANCELADA', $statusParts, true);
        $kpis = [['label' => 'Total de notas', 'value' => (int) ($summary['total_notas'] ?? 0)]];
        if ($showSplitTotals) {
            $filtersEmitidas = $filters;
            $filtersEmitidas['status'] = 'EMITIDA';
            $filtersCanceladas = $filters;
            $filtersCanceladas['status'] = 'CANCELADA';
            $sumEmitidas = $repo->summaryNotas($filtersEmitidas);
            $sumCanceladas = $repo->summaryNotas($filtersCanceladas);
            $kpis[] = ['label' => 'Valor emitidas', 'value' => $this->formatMoney((float) ($sumEmitidas['total_valor'] ?? 0), 'R$ ', '')];
            $kpis[] = ['label' => 'Valor canceladas', 'value' => $this->formatMoney((float) ($sumCanceladas['total_valor'] ?? 0), 'R$ ', '')];
        } else {
            $kpis[] = ['label' => 'Valor total', 'value' => $this->formatMoney((float) ($summary['total_valor'] ?? 0), 'R$ ', '')];
        }
        echo Ui::kpis($kpis);

        $rowsHtml = '';
        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $clienteNome = $this->resolveClientName($row);
            $numeroNfse = (string) ($row['numero_nf'] ?? '');
            $statusRow = (string) ($row['status'] ?? '');
            $dataRef = (string) ($row['reference_date'] ?? $row['emitida_em'] ?? $row['nfse_updated_at'] ?? '');
            $dataFmt = $this->formatDate($dataRef, 'd/m/Y');
            $valor = (float) ($row['invoice_total'] ?? 0);
            $prefix = (string) ($row['currency_prefix'] ?? 'R$ ');
            $suffix = (string) ($row['currency_suffix'] ?? '');

            $rowsHtml .= '<tr>'
                . '<td class="nfse-nowrap">' . Ui::h($dataFmt) . '</td>'
                . '<td>' . ($statusRow !== '' ? Ui::statusBadge($statusRow) : ReportView::orDash('')) . '</td>'
                . '<td class="nfse-num">' . ReportView::orDash($numeroNfse) . '</td>'
                . '<td>' . ReportView::invoiceLink($invoiceId) . '</td>'
                . '<td class="nfse-break">' . Ui::h($clienteNome) . '</td>'
                . '<td class="nfse-num nfse-nowrap">' . Ui::h($this->formatMoney($valor, $prefix, $suffix)) . '</td>'
                . '</tr>';
        }

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
        $exportParams = [
            'tab' => 'emitidas',
            'invoiceid' => $invoiceFilter,
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'status' => $status,
            'cliente' => $cliente,
        ];
        $firstItem = $totalNotas > 0 ? ($offset + 1) : 0;
        $lastItem = min($offset + count($rows), $totalNotas);
        $footer = $this->pagedFooter(
            $firstItem,
            $lastItem,
            $totalNotas,
            'registros',
            $baseParams,
            $page,
            $totalPages,
            Ui::pageForm(
                ['module' => 'OpenNfse', 'action' => 'relatorios', 'tab' => 'emitidas', 'invoiceid' => $invoiceFilter, 'data_inicial' => $dataInicial, 'data_final' => $dataFinal, 'status' => $status, 'cliente' => $cliente],
                $page,
                $totalPages,
                $perPage,
                [50, 100, 500]
            )
            . $this->postButton('relatoriosExportZip', 'Baixar XMLs do período escolhido', $exportParams)
            . $this->postButton('relatoriosExport', 'Exportar CSV', $exportParams)
        );

        echo ReportView::tableCard(
            ['Data', 'Status', ['NFS-e', 'nfse-num'], 'Fatura', 'Cliente', ['Valor', 'nfse-num']],
            $rowsHtml,
            'Nenhuma nota encontrada para os critérios selecionados.',
            '',
            '',
            $footer,
            'nfse-rel-secao'
        );
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

        echo Ui::filterBar(
            ['hidden' => ['module' => 'OpenNfse', 'action' => 'relatorios', 'tab' => 'falhas']],
            Ui::field('Data inicial', Ui::input('data_inicial', $dataInicial, 'date'))
            . Ui::field('Data final', Ui::input('data_final', $dataFinal, 'date'))
            . Ui::field('Cliente', Ui::input('cliente', $cliente, 'text', ['placeholder' => 'Cliente (ID ou nome)'], 'nfse-input--w-lg')),
            Ui::button('Filtrar', ['primary']) . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas']),
            'Filtros das falhas'
        );

        $rowsHtml = '';
        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $clienteNome = $this->resolveClientName($row);
            $dataFmt = $this->formatDate((string) ($row['data'] ?? ''), 'd/m/Y');
            $erro = trim((string) ($row['erro'] ?? ''));
            if ($erro === '') {
                $erro = '-';
            }
            if (mb_strlen($erro) > 220) {
                $erro = mb_substr($erro, 0, 220) . '...';
            }

            $rowsHtml .= '<tr>'
                . '<td class="nfse-nowrap">' . Ui::h($dataFmt) . '</td>'
                . '<td>' . ReportView::invoiceLink($invoiceId) . '</td>'
                . '<td class="nfse-break">' . Ui::h($clienteNome) . '</td>'
                . '<td class="nfse-break">' . Ui::h($erro) . '</td>'
                . '</tr>';
        }

        $count = count($rows);
        echo ReportView::tableCard(
            ['Data', 'Fatura', 'Cliente', 'Erro'],
            $rowsHtml,
            'Nenhuma falha encontrada para os critérios selecionados.',
            '',
            '',
            Ui::tableFooter(
                $count > 0 ? 'Exibindo <b>' . $count . '</b> falhas (até 200 mais recentes)' : 'Nenhum resultado',
                $this->postButton('relatoriosExport', 'Exportar CSV', [
                    'tab' => 'falhas',
                    'data_inicial' => $dataInicial,
                    'data_final' => $dataFinal,
                    'cliente' => $cliente,
                ])
            ),
            'nfse-rel-secao'
        );
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

        $this->ensureFiscalHistoryReady();
        $repo = new ReportRepository();
        $rows = $repo->listCancelamentos($filters, 200);

        echo Ui::filterBar(
            ['hidden' => ['module' => 'OpenNfse', 'action' => 'relatorios', 'tab' => 'cancelamentos']],
            Ui::field('Data inicial', Ui::input('data_inicial', $dataInicial, 'date'))
            . Ui::field('Data final', Ui::input('data_final', $dataFinal, 'date'))
            . Ui::field('Cliente', Ui::input('cliente', $cliente, 'text', ['placeholder' => 'Cliente (ID ou nome)'], 'nfse-input--w-lg')),
            Ui::button('Filtrar', ['primary']) . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=cancelamentos']),
            'Filtros dos cancelamentos'
        );

        $rowsHtml = '';
        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $clienteNome = $this->resolveClientName($row);
            $numeroNfse = (string) ($row['numero_nf'] ?? '');
            $emissaoFmt = $this->formatDate((string) ($row['emitida_em'] ?? ''), 'd/m/Y');
            $cancelFmt = $this->formatDate((string) ($row['cancelado_em'] ?? ''), 'd/m/Y');
            $valor = (float) ($row['invoice_total'] ?? 0);
            $prefix = (string) ($row['currency_prefix'] ?? 'R$ ');
            $suffix = (string) ($row['currency_suffix'] ?? '');

            $rowsHtml .= '<tr>'
                . '<td class="nfse-nowrap">' . Ui::h($emissaoFmt) . '</td>'
                . '<td class="nfse-nowrap">' . Ui::h($cancelFmt) . '</td>'
                . '<td class="nfse-num">' . ReportView::orDash($numeroNfse) . '</td>'
                . '<td>' . ReportView::invoiceLink($invoiceId) . '</td>'
                . '<td class="nfse-break">' . Ui::h($clienteNome) . '</td>'
                . '<td class="nfse-num nfse-nowrap">' . Ui::h($this->formatMoney($valor, $prefix, $suffix)) . '</td>'
                . '</tr>';
        }

        $exportParams = [
            'tab' => 'cancelamentos',
            'data_inicial' => $dataInicial,
            'data_final' => $dataFinal,
            'cliente' => $cliente,
        ];
        $count = count($rows);
        echo ReportView::tableCard(
            ['Emissão', 'Cancelamento', ['NFS-e', 'nfse-num'], 'Fatura', 'Cliente', ['Valor', 'nfse-num']],
            $rowsHtml,
            'Nenhum cancelamento encontrado para os critérios selecionados.',
            '',
            '',
            Ui::tableFooter(
                $count > 0 ? 'Exibindo <b>' . $count . '</b> cancelamentos (até 200 mais recentes)' : 'Nenhum resultado',
                $this->postButton('relatoriosExport', 'Exportar CSV', $exportParams)
                . $this->postButton('relatoriosExportZip', 'Baixar XMLs ZIP', $exportParams)
            ),
            'nfse-rel-secao'
        );
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

        echo Ui::filterBar(
            ['hidden' => ['module' => 'OpenNfse', 'action' => 'relatorios', 'tab' => 'auditoria']],
            Ui::field('Mês de referência', Ui::input('mes', $selectedMonth, 'month')),
            Ui::button('Filtrar', ['primary']) . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=auditoria']),
            'Filtro da auditoria de emissão'
        );
        if (empty($gatewayKeys)) {
            echo Ui::callout('warn', 'Nenhum gateway ativo', 'Nenhum gateway ativo e habilitado no addon foi encontrado para a auditoria.');
        } else {
            echo Ui::callout(
                'danger',
                'Revisão manual: faturas pagas sem NFS-e',
                'Exibindo o período de ' . $periodLabel . ' para conferir faturas pagas que não foram emitidas NFS-e. Revise e emita manualmente cada uma delas se for o caso.'
            );
        }

        echo Ui::kpis([
            ['label' => 'Total de faturas', 'value' => (int) ($summary['total_invoices'] ?? 0)],
            ['label' => 'Valor total', 'value' => $this->formatMoney((float) ($summary['total_valor'] ?? 0), 'R$ ', '')],
        ]);

        $rowsHtml = '';
        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoice_id'] ?? 0);
            $clienteNome = $this->resolveClientName($row);
            $paidAt = $this->formatDate((string) ($row['paid_at'] ?? ''), 'd/m/Y H:i');
            $gatewayKey = strtolower(trim((string) ($row['paymentmethod'] ?? '')));
            $gatewayLabel = $gatewayMap[$gatewayKey] ?? ($gatewayKey !== '' ? $gatewayKey : '-');
            $valor = (float) ($row['invoice_total'] ?? 0);
            $prefix = (string) ($row['currency_prefix'] ?? 'R$ ');
            $suffix = (string) ($row['currency_suffix'] ?? '');

            $rowsHtml .= '<tr>'
                . '<td>' . ReportView::invoiceLink($invoiceId) . '</td>'
                . '<td class="nfse-break">' . Ui::h($clienteNome) . '</td>'
                . '<td class="nfse-nowrap">' . Ui::h($paidAt) . '</td>'
                . '<td class="nfse-break">' . Ui::h($gatewayLabel) . '</td>'
                . '<td class="nfse-num nfse-nowrap">' . Ui::h($this->formatMoney($valor, $prefix, $suffix)) . '</td>'
                . '</tr>';
        }

        echo ReportView::tableCard(
            ['Fatura', 'Cliente', 'Pagamento', 'Gateway', ['Valor', 'nfse-num']],
            $rowsHtml,
            'Nenhuma fatura encontrada para os critérios selecionados.',
            '',
            '',
            '',
            'nfse-rel-secao'
        );
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

        if ($flashMessage === 'orphan_cancel_done') {
            echo Ui::callout('ok', 'NFS-e órfã cancelada', 'O evento foi registrado no histórico fiscal.');
        }

        echo Ui::filterBar(
            ['hidden' => ['module' => 'OpenNfse', 'action' => 'relatorios', 'tab' => 'xml_auditoria']],
            Ui::field('Mês de referência', Ui::input('mes', $selectedMonth, 'month')),
            Ui::button('Auditar', ['primary']) . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=xml_auditoria']),
            'Filtro da auditoria XML'
        );

        echo ReportView::infoCard(
            'Escopo da auditoria',
            [
                ['Pasta', (string) ($audit['relative_dir'] ?? '-')],
                ['Mês', $periodLabel],
                ['Ambiente', (string) ($audit['environment_label'] ?? '-')],
                ['Série', (string) ($audit['serie_label'] ?? '-')],
            ],
            'Compara a pasta com o campo <code>xml_path</code> atual da base. Arquivos <code>cancelamento_nfse_*</code> são contabilizados separadamente e não entram como órfãos técnicos.'
        );

        $warnCount = static fn (int $n): string => $n > 0 ? 'warn' : '';
        echo Ui::kpis([
            ['label' => 'Arquivos no disco', 'value' => (int) ($audit['physical_count'] ?? 0)],
            ['label' => 'Cancelamentos no disco', 'value' => (int) ($audit['cancelamento_file_count'] ?? 0)],
            ['label' => 'XMLs referenciados', 'value' => (int) ($audit['db_reference_count'] ?? 0)],
            ['label' => 'Órfãos no disco', 'value' => (int) ($audit['orphan_count'] ?? 0), 'tone' => $warnCount((int) ($audit['orphan_count'] ?? 0))],
            ['label' => 'Referências quebradas', 'value' => (int) ($audit['missing_reference_count'] ?? 0), 'tone' => (int) ($audit['missing_reference_count'] ?? 0) > 0 ? 'danger' : ''],
            ['label' => 'Status inconsistente', 'value' => (int) ($audit['unexpected_status_count'] ?? 0), 'tone' => $warnCount((int) ($audit['unexpected_status_count'] ?? 0))],
            ['label' => 'Referências duplicadas', 'value' => (int) ($audit['duplicate_reference_count'] ?? 0), 'tone' => $warnCount((int) ($audit['duplicate_reference_count'] ?? 0))],
        ]);

        $statusSummary = $this->buildXmlAuditStatusSummary((array) ($audit['status_counts'] ?? []));
        $statusText = $statusSummary !== '' ? 'Status atuais das notas que apontam para esta pasta: ' . $statusSummary . '.' : '';
        if ($issueCount > 0) {
            echo Ui::callout('warn', 'Foram encontradas ' . $issueCount . ' divergências técnicas entre filesystem e base para este mês', $statusText);
        } else {
            echo Ui::callout('ok', 'Nenhuma divergência técnica entre a pasta mensal e o xml_path atual das notas', $statusText);
        }

        $noteColumns = ['Data', 'Fatura', 'Cliente', 'Status', ['NFS-e', 'nfse-num'], 'xml_path'];
        $noteRows = function (array $rows): string {
            $html = '';
            foreach ($rows as $row) {
                $dataRef = (string) ($row['reference_date'] ?? $row['emitida_em'] ?? $row['nfse_updated_at'] ?? '');
                $status = (string) ($row['status'] ?? '');
                $html .= '<tr>'
                    . '<td class="nfse-nowrap">' . Ui::h($this->formatDate($dataRef, 'd/m/Y')) . '</td>'
                    . '<td>' . ReportView::invoiceLink((int) ($row['invoiceid'] ?? 0)) . '</td>'
                    . '<td class="nfse-break">' . Ui::h($this->resolveClientName($row)) . '</td>'
                    . '<td>' . ($status !== '' ? Ui::statusBadge($status) : ReportView::orDash('')) . '</td>'
                    . '<td class="nfse-num">' . ReportView::orDash((string) ($row['numero_nf'] ?? '')) . '</td>'
                    . '<td class="nfse-break nfse-mono">' . ReportView::orDash((string) ($row['xml_path_normalized'] ?? $row['xml_path'] ?? '')) . '</td>'
                    . '</tr>';
            }

            return $html;
        };

        $unexpectedRows = (array) ($audit['unexpected_status_rows'] ?? []);
        echo ReportView::tableCard($noteColumns, $noteRows($unexpectedRows), 'Nenhuma nota inconsistente encontrada.', 'Notas com XML salvo e status diferente de Emitida/Cancelada', count($unexpectedRows) . ' notas', '', 'nfse-xml-inconsistentes');

        $missingRows = (array) ($audit['missing_reference_rows'] ?? []);
        echo ReportView::tableCard($noteColumns, $noteRows($missingRows), 'Nenhuma referência quebrada encontrada.', 'Referências quebradas na base', count($missingRows) . ' notas', '', 'nfse-xml-quebradas');

        $orphanFiles = (array) ($audit['orphan_files'] ?? []);
        $orphanHtml = '';
        foreach ($orphanFiles as $file) {
            $xmlPath = (string) ($file['relative_path'] ?? '');
            $action = '<span class="nfse-muted">-</span>';
            if ($xmlPath !== '') {
                $action = '<form class="nfse-inline-form" method="get" action="addonmodules.php">'
                    . Ui::hidden(['module' => 'OpenNfse', 'action' => 'cancelOrphanXmlForm', 'xml_path' => $xmlPath, 'mes' => $selectedMonth])
                    . Ui::button('Cancelar', ['sm', 'danger'])
                    . '</form>';
            }
            $orphanHtml .= '<tr>'
                . '<td class="nfse-break">' . ReportView::orDash((string) ($file['filename'] ?? '')) . '</td>'
                . '<td class="nfse-break nfse-mono">' . ReportView::orDash($xmlPath) . '</td>'
                . '<td class="nfse-nowrap">' . ReportView::orDash((string) ($file['modified_at'] ?? '')) . '</td>'
                . '<td class="nfse-num nfse-nowrap">' . Ui::h($this->formatAuditBytes((int) ($file['size'] ?? 0))) . '</td>'
                . '<td>' . $action . '</td>'
                . '</tr>';
        }
        echo ReportView::tableCard(['Arquivo', 'Caminho', 'Modificado em', ['Tamanho', 'nfse-num'], 'Ação'], $orphanHtml, 'Nenhum arquivo órfão encontrado.', 'Arquivos órfãos no disco', count($orphanFiles) . ' arquivos', '', 'nfse-xml-orfaos');

        $duplicateReferences = (array) ($audit['duplicate_references'] ?? []);
        $duplicateHtml = '';
        foreach ($duplicateReferences as $duplicate) {
            $invoiceLinks = [];
            foreach ((array) ($duplicate['rows'] ?? []) as $row) {
                $invoiceId = (int) ($row['invoiceid'] ?? 0);
                if ($invoiceId <= 0) {
                    continue;
                }
                $invoiceLinks[] = ReportView::invoiceLink($invoiceId);
            }

            $duplicateHtml .= '<tr>'
                . '<td class="nfse-break nfse-mono">' . ReportView::orDash((string) ($duplicate['xml_path'] ?? '')) . '</td>'
                . '<td class="nfse-num">' . (int) ($duplicate['count'] ?? 0) . '</td>'
                . '<td class="nfse-break">' . (!empty($invoiceLinks) ? implode(', ', $invoiceLinks) : '<span class="nfse-muted">-</span>') . '</td>'
                . '</tr>';
        }
        echo ReportView::tableCard(['xml_path', ['Ocorrências', 'nfse-num'], 'Faturas'], $duplicateHtml, 'Nenhuma referência duplicada encontrada.', 'Referências duplicadas ao mesmo xml_path', count($duplicateReferences) . ' caminhos', '', 'nfse-xml-duplicadas');
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
        $this->renderTabs('relatorios', 'Cancelar NFS-e órfã', 'Cancelamento de um XML que não é a nota atual da fatura');

        $selectedMonth = trim((string) ($_REQUEST['mes'] ?? ''));
        $backUrl = 'addonmodules.php?module=OpenNfse&action=relatorios&tab=xml_auditoria';
        if ($selectedMonth !== '') {
            $backUrl .= '&mes=' . rawurlencode($selectedMonth);
        }

        $token = (new TokenService())->token();

        echo '<div class="nfse-ui nfse-page">';
        echo Ui::sectionHeader(
            'Dados do XML órfão',
            'Confira a nota antes de cancelar.',
            Ui::button('Voltar para Auditoria XML', ['ghost'], ['href' => $backUrl]),
            'nfse-orfa-dados'
        );
        echo Ui::callout('warn', 'Operação fiscal', 'Esta ação cancela a NFS-e representada pelo XML órfão, sem alterar automaticamente a nota operacional atual da invoice.');

        echo ReportView::infoCard('', [
            ['Arquivo', (string) ($context['filename'] ?? '-')],
            ['Fatura', (string) (($context['invoiceid'] ?? 0) > 0 ? (string) $context['invoiceid'] : '-')],
            ['NFS-e', (string) (($context['numero_nf'] ?? '') !== '' ? $context['numero_nf'] : '-')],
            ['Emitida em', $this->formatDate((string) ($context['emitida_em'] ?? ''), 'd/m/Y H:i:s')],
            ['Caminho', (string) ($context['xml_path'] ?? '-')],
            ['ID DPS', (string) (($context['id_dps'] ?? '') !== '' ? $context['id_dps'] : '-')],
            ['Chave de acesso', (string) ($context['chave_acesso'] ?? '-')],
        ]);

        echo '<form method="post" action="addonmodules.php?module=OpenNfse&amp;action=cancelOrphanXml">';
        if ($token !== '') {
            echo Ui::hidden(['token' => $token]);
        }
        echo Ui::hidden(['xml_path' => (string) ($context['xml_path'] ?? '')]);
        if ($selectedMonth !== '') {
            echo Ui::hidden(['mes' => $selectedMonth]);
        }
        echo '<section class="nfse-card" aria-labelledby="nfse-orfa-motivo">';
        echo '<div class="nfse-card__head"><h3 class="nfse-card__title" id="nfse-orfa-motivo">Motivo do cancelamento</h3></div>';
        echo '<div class="nfse-form">';
        echo Ui::formRow(
            'Código do motivo',
            Ui::select('codigo_motivo', [
                '1' => '1 - Erro na emissão da NFS-e (será emitida outra nota correta)',
                '2' => '2 - Serviço não prestado / cancelamento da prestação',
                '9' => '9 - NFS-e emitida indevidamente',
            ], '9', ['id' => 'codigo_motivo'], 'nfse-input--full'),
            '',
            ['id' => 'codigo_motivo']
        );
        echo Ui::formRow('Motivo', Ui::input('motivo', 'Emissão indevida', 'text', ['id' => 'motivo'], 'nfse-input--full'), '', ['id' => 'motivo']);
        echo Ui::formRow('Descrição', Ui::input('descricao', 'NFS-e cancelada por ter sido emitida indevidamente, pois outra nota fiscal já foi emitida corretamente para a mesma operação.', 'text', ['id' => 'descricao'], 'nfse-input--full'), '', ['id' => 'descricao']);
        echo '</div>';
        echo Ui::saveBar(
            Ui::button('Voltar', ['ghost'], ['href' => $backUrl])
            . '<button type="submit" class="nfse-btn nfse-btn--danger" onclick="return confirm(\'Cancelar esta NFS-e órfã é uma operação fiscal. Continuar?\');">Cancelar NFS-e órfã</button>'
        );
        echo '</section>';
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
        echo '</form>';
        echo '</div>';

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

        $typeOptions = [
            '' => 'Todos os registros',
            'EMISSAO' => 'Emissão',
            'CANCELAMENTO' => 'Cancelamento',
            'SNAPSHOT' => 'Snapshot legado',
        ];
        echo Ui::filterBar(
            ['hidden' => ['module' => 'OpenNfse', 'action' => 'relatorios', 'tab' => 'historico_fiscal']],
            Ui::field('Mês de referência', Ui::input('mes', $selectedMonth, 'month'))
            . Ui::field('Tipo', Ui::select('tipo_registro', $typeOptions, $tipoRegistro, [], 'nfse-input--w-md'))
            . Ui::field('Fatura', Ui::input('invoiceid', $invoiceFilter, 'text', ['placeholder' => 'ID da fatura'], 'nfse-input--w-sm')),
            Ui::button('Filtrar', ['primary']) . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=historico_fiscal']),
            'Filtros do histórico fiscal'
        );

        echo Ui::kpis([
            ['label' => 'Documentos no período', 'value' => (int) ($summary['total_docs'] ?? 0)],
            ['label' => 'Emissões', 'value' => (int) ($summary['total_emissoes'] ?? 0)],
            ['label' => 'Cancelamentos', 'value' => (int) ($summary['total_cancelamentos'] ?? 0)],
            ['label' => 'Snapshots legado', 'value' => (int) ($summary['total_snapshots'] ?? 0)],
        ]);

        $rowsHtml = '';
        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $eventDate = $this->resolveFiscalHistoryEventDate($row);
            $xmlBadges = [];
            if (trim((string) ($row['xml_path'] ?? '')) !== '') {
                $xmlBadges[] = 'NF';
            }
            if (trim((string) ($row['cancel_xml_path'] ?? '')) !== '') {
                $xmlBadges[] = 'CAN';
            }
            $statusFiscal = (string) ($row['status_fiscal'] ?? '');

            $rowsHtml .= '<tr>'
                . '<td class="nfse-nowrap">' . Ui::h($this->formatDate($eventDate, 'd/m/Y H:i')) . '</td>'
                . '<td>' . ReportView::orDash((string) ($row['tipo_registro'] ?? '')) . '</td>'
                . '<td>' . ($statusFiscal !== '' ? Ui::statusBadge($statusFiscal) : ReportView::orDash('')) . '</td>'
                . '<td class="nfse-num">' . ReportView::orDash((string) ($row['numero_nf'] ?? '')) . '</td>'
                . '<td class="nfse-break">' . Ui::h($this->resolveClientName($row)) . '</td>'
                . '<td>' . ReportView::invoiceLink($invoiceId) . '</td>'
                . '<td class="nfse-num nfse-nowrap">' . Ui::h($this->formatMoney((float) ($row['invoice_total'] ?? 0), (string) ($row['currency_prefix'] ?? 'R$ '), (string) ($row['currency_suffix'] ?? ''))) . '</td>'
                . '<td>' . ReportView::orDash((string) ($row['origem'] ?? '')) . '</td>'
                . '<td class="nfse-nowrap">' . ReportView::orDash(implode(' / ', $xmlBadges)) . '</td>'
                . '</tr>';
        }

        $baseParams = [
            'module' => 'OpenNfse',
            'action' => 'relatorios',
            'tab' => 'historico_fiscal',
            'mes' => $selectedMonth,
            'tipo_registro' => $tipoRegistro,
            'invoiceid' => $invoiceFilter,
        ];
        $firstItem = $totalDocs > 0 ? ($offset + 1) : 0;
        $lastItem = min($offset + count($rows), $totalDocs);
        echo ReportView::tableCard(
            ['Data', 'Tipo', 'Status', ['NFS-e', 'nfse-num'], 'Cliente', 'Fatura', ['Valor', 'nfse-num'], 'Origem', 'XMLs'],
            $rowsHtml,
            'Nenhum registro fiscal encontrado para os critérios selecionados.',
            '',
            '',
            $this->pagedFooter($firstItem, $lastItem, $totalDocs, 'registros', $baseParams, $page, $totalPages),
            'nfse-rel-secao'
        );
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

        $filterHidden = ['module' => 'OpenNfse', 'action' => 'relatorios', 'tab' => 'auditoria_api_dps'];
        if ($runId > 0 && is_array($run) && (string) ($run['audit_month'] ?? '') === $selectedMonth) {
            $filterHidden['run_id'] = $runId;
        }
        echo Ui::filterBar(
            ['hidden' => $filterHidden],
            Ui::field('Mês de referência', Ui::input('mes', $selectedMonth, 'month')),
            Ui::button('Atualizar')
            . Ui::button('Iniciar auditoria assíncrona', ['primary'], ['name' => 'start_auditoria', 'value' => '1'])
            . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=auditoria_api_dps']),
            'Filtro da auditoria DPS'
        );

        echo ReportView::infoCard(
            'Por que esta função existe',
            [],
            'Esta auditoria existe para conferir se as DPS geradas localmente foram reconhecidas pela API, identificar divergências entre a chave local e a chave retornada pela consulta oficial, e apontar possíveis lacunas na sequência da DPS que indiquem tentativas perdidas, falhas de persistência ou saltos sem evidência técnica.'
        );

        if ($auditError !== null) {
            echo Ui::callout('danger', 'Falha ao executar a auditoria API por DPS', $auditError);
            return;
        }

        if (!is_array($run)) {
            echo Ui::callout('info', 'Nenhuma execução neste mês', 'Selecione o mês desejado e clique em "Iniciar auditoria assíncrona" para criar uma execução em lotes da conferência por DPS.');
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
        $issueTone = static fn (int $n, string $tone): string => $n > 0 ? $tone : '';
        echo Ui::kpis([
            ['label' => 'DPS locais', 'value' => (int) ($run['total_items'] ?? 0)],
            ['label' => 'Processadas', 'value' => (int) ($run['processed_items'] ?? 0)],
            ['label' => 'OK', 'value' => $statusCounts['OK'], 'tone' => $issueTone($statusCounts['OK'], 'ok')],
            ['label' => 'Sem chave local', 'value' => $statusCounts['LOCAL_SEM_CHAVE']],
            ['label' => 'Sem chave na API', 'value' => $statusCounts['SEM_CHAVE_API'], 'tone' => $issueTone($statusCounts['SEM_CHAVE_API'], 'warn')],
            ['label' => 'Não encontradas', 'value' => $statusCounts['NAO_ENCONTRADA'], 'tone' => $issueTone($statusCounts['NAO_ENCONTRADA'], 'warn')],
            ['label' => 'Divergentes', 'value' => $statusCounts['CHAVE_DIVERGENTE'], 'tone' => $issueTone($statusCounts['CHAVE_DIVERGENTE'], 'danger')],
            ['label' => 'Erros API', 'value' => $statusCounts['ERRO_API'], 'tone' => $issueTone($statusCounts['ERRO_API'], 'danger')],
            ['label' => 'Sem ID DPS', 'value' => $statusCounts['SEM_ID_DPS']],
        ]);

        $status = (string) ($run['status'] ?? 'pending');
        $progressPercent = (int) ($run['total_items'] ?? 0) > 0
            ? round((((int) ($run['processed_items'] ?? 0)) / max(1, (int) ($run['total_items'] ?? 0))) * 100)
            : 100;
        $isRunning = $status === 'running' || $status === 'pending';
        $lastRunError = trim((string) ($run['last_error'] ?? ''));
        echo '<section class="nfse-card nfse-card--pad nfse-rel-info" aria-labelledby="nfse-dps-execucao">';
        echo '<div class="nfse-sechead"><div class="nfse-sechead__text"><h3 class="nfse-card__title" id="nfse-dps-execucao">Execução assíncrona</h3></div>';
        echo '<div class="nfse-card__summary">Run ID <b>' . $runId . '</b> · Status <b id="dps-audit-status-label">' . Ui::h($status) . '</b></div></div>';
        echo ReportView::progress((int) $progressPercent, 'dps-audit-progress-bar');
        echo '<div id="dps-audit-progress-text" class="nfse-rel-note">' . (int) ($run['processed_items'] ?? 0) . ' de ' . (int) ($run['total_items'] ?? 0) . ' DPS processadas (' . $progressPercent . '%).</div>';
        if ($isRunning) {
            echo '<div id="dps-audit-runtime-hint" class="nfse-rel-hint">Processamento em andamento por lotes curtos. Mantenha esta página aberta até a conclusão, pois os próximos lotes e as retentativas automáticas dependem desta aba ativa no navegador.</div>';
        }
        echo '<div id="dps-audit-retry-state" class="nfse-rel-hint"' . ($isRunning ? '' : ' hidden') . '></div>';
        echo '<div id="dps-audit-last-error" class="nfse-rel-error"' . ($lastRunError !== '' ? '' : ' hidden') . '>' . Ui::h($lastRunError) . '</div>';
        echo '</section>';

        echo ReportView::infoCard('Conferência da sequência DPS', [
            ['Primeiro número no período', (string) (($run['first_number'] ?? null) !== null ? $run['first_number'] : '-')],
            ['Último número no período', (string) (($run['last_number'] ?? null) !== null ? $run['last_number'] : '-')],
            ['Lacunas', (int) ($run['gap_count'] ?? 0)],
            ['Último sequencial atual', (string) (($run['current_sequence_last_number'] ?? null) !== null ? $run['current_sequence_last_number'] : '-')],
        ]);

        $statusLabelMap = [
            'OK' => ['OK', 'ok'],
            'DIVERGENCIA_CANCELADA' => ['Diverg. cancelada', 'emitida'],
            'DIVERGENCIA_REEMITIDA' => ['Diverg. reemitida', 'emitida'],
            'LOCAL_SEM_CHAVE' => ['Local sem chave', 'neutro'],
            'SEM_CHAVE_API' => ['API sem chave', 'warn'],
            'NAO_ENCONTRADA' => ['Não encontrada', 'warn'],
            'CHAVE_DIVERGENTE' => ['Chave divergente', 'danger'],
            'ERRO_API' => ['Erro API', 'erro'],
            'SEM_ID_DPS' => ['Sem ID DPS', 'neutro'],
        ];

        $dpsPerPage = 100;
        $dpsTotal = $repo->countResults($runId, 'DPS');
        $dpsPages = max(1, (int) ceil($dpsTotal / $dpsPerPage));
        if ($dpsPage > $dpsPages) {
            $dpsPage = $dpsPages;
        }
        $dpsRows = $repo->listResults($runId, 'DPS', $dpsPerPage, ($dpsPage - 1) * $dpsPerPage);

        $dpsHtml = '';
        foreach ($dpsRows as $row) {
            $statusKey = (string) ($row['audit_status'] ?? 'SEM_ID_DPS');
            $statusMeta = $statusLabelMap[$statusKey] ?? [$statusKey, ''];
            $eventDate = (string) (($row['event_date'] ?? '') !== '' ? $row['event_date'] : (($row['created_at'] ?? '') !== '' ? $row['created_at'] : ''));
            $localChave = trim((string) ($row['local_chave_acesso'] ?? ''));
            $apiChave = trim((string) ($row['api_chave_acesso'] ?? ''));
            $message = trim((string) ($row['audit_message'] ?? ''));

            $dpsHtml .= '<tr>'
                . '<td class="nfse-nowrap">' . Ui::h($this->formatDate($eventDate, 'd/m/Y H:i')) . '</td>'
                . '<td>' . ReportView::invoiceLink((int) ($row['invoiceid'] ?? 0)) . '</td>'
                . '<td class="nfse-break">' . Ui::h($this->resolveClientName($row)) . '</td>'
                . '<td class="nfse-break nfse-mono">' . ReportView::orDash((string) ($row['id_dps'] ?? '')) . '</td>'
                . '<td class="nfse-num">' . ReportView::orDash((string) (($row['numero_dps'] ?? null) !== null ? $row['numero_dps'] : '')) . '</td>'
                . '<td class="nfse-num">' . ReportView::orDash((string) ($row['numero_nf'] ?? '')) . '</td>'
                . '<td><div class="nfse-cell-stack">' . Ui::badge((string) $statusMeta[0], (string) $statusMeta[1])
                . ($message !== '' ? '<span class="nfse-cell-meta">' . Ui::h($message) . '</span>' : '') . '</div></td>'
                . '<td class="nfse-break nfse-mono"><div>Local: ' . Ui::h($localChave !== '' ? $localChave : '-') . '</div><div>API: ' . Ui::h($apiChave !== '' ? $apiChave : '-') . '</div></td>'
                . '</tr>';
        }
        $baseDpsParams = [
            'module' => 'OpenNfse',
            'action' => 'relatorios',
            'tab' => 'auditoria_api_dps',
            'mes' => $selectedMonth,
            'run_id' => $runId,
            'gap_page' => $gapPage,
        ];
        $dpsFirst = $dpsTotal > 0 ? (($dpsPage - 1) * $dpsPerPage + 1) : 0;
        $dpsLast = min(($dpsPage - 1) * $dpsPerPage + count($dpsRows), $dpsTotal);
        echo ReportView::tableCard(
            ['Data', 'Fatura', 'Cliente', 'ID DPS', ['Seq.', 'nfse-num'], ['NFS-e', 'nfse-num'], 'Status', 'Chave local / API'],
            $dpsHtml,
            'Nenhuma emissão com DPS foi encontrada no período selecionado.',
            'Conferência local vs API por DPS',
            '',
            $this->pagedFooter($dpsFirst, $dpsLast, $dpsTotal, 'DPS', $baseDpsParams, $dpsPage, $dpsPages, '', 'dps_page'),
            'nfse-dps-conferencia'
        );

        $gapPerPage = 100;
        $gapTotal = $repo->countResults($runId, 'GAP');
        $gapPages = max(1, (int) ceil($gapTotal / $gapPerPage));
        if ($gapPage > $gapPages) {
            $gapPage = $gapPages;
        }
        $gapRows = $repo->listResults($runId, 'GAP', $gapPerPage, ($gapPage - 1) * $gapPerPage);
        $gapHtml = '';
        foreach ($gapRows as $row) {
            $gapHtml .= '<tr>'
                . '<td class="nfse-num">' . ReportView::orDash((string) ($row['numero_dps'] ?? '')) . '</td>'
                . '<td class="nfse-break nfse-mono">' . ReportView::orDash((string) ($row['id_dps'] ?? '')) . '</td>'
                . '<td>' . ReportView::orDash((string) ($row['evidence_classification'] ?? '')) . '</td>'
                . '<td class="nfse-num">' . (int) ($row['evidence_count'] ?? 0) . '</td>'
                . '<td class="nfse-break">' . Ui::h((string) ($row['audit_message'] ?? '')) . '</td>'
                . '</tr>';
        }
        $baseGapParams = [
            'module' => 'OpenNfse',
            'action' => 'relatorios',
            'tab' => 'auditoria_api_dps',
            'mes' => $selectedMonth,
            'run_id' => $runId,
            'dps_page' => $dpsPage,
        ];
        $gapFirst = $gapTotal > 0 ? (($gapPage - 1) * $gapPerPage + 1) : 0;
        $gapLast = min(($gapPage - 1) * $gapPerPage + count($gapRows), $gapTotal);
        echo ReportView::tableCard(
            [['Seq.', 'nfse-num'], 'ID DPS esperado', 'Classificação', ['Evidências', 'nfse-num'], 'Leitura'],
            $gapHtml,
            'Nenhuma lacuna foi identificada na sequência das DPS do período.',
            'Lacunas na sequência DPS',
            '',
            $this->pagedFooter($gapFirst, $gapLast, $gapTotal, 'lacunas', $baseGapParams, $gapPage, $gapPages, '', 'gap_page'),
            'nfse-dps-lacunas'
        );

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
      lastErrorBox.hidden = true;
      lastErrorBox.textContent = '';
      return;
    }
    lastErrorBox.hidden = false;
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
      retryState.hidden = false;
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
        retryState.hidden = true;
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

        echo Ui::filterBar(
            ['hidden' => ['module' => 'OpenNfse', 'action' => 'relatorios', 'tab' => 'auditoria_api']],
            Ui::field('Mês de referência', Ui::input('mes', $selectedMonth, 'month')),
            Ui::button('Filtrar')
            . Ui::button('Sincronizar NSU agora', ['primary'], ['name' => 'sync_nsu', 'value' => '1'])
            . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=auditoria_api']),
            'Filtro da auditoria API'
        );

        if ($syncError !== null) {
            echo Ui::callout('danger', 'Falha ao sincronizar a distribuição da API', $syncError);
        } elseif (is_array($syncResult)) {
            $processedCount = (int) ($syncResult['processed_count'] ?? 0);
            echo Ui::callout(
                $processedCount > 0 ? 'ok' : 'warn',
                $processedCount > 0 ? 'Sincronização concluída' : 'Sincronização concluída sem retorno de documentos',
                'Foram processados ' . $processedCount . ' documentos em ' . (int) ($syncResult['batch_count'] ?? 0) . ' lote(s), usando o modo ' . (string) ($syncResult['mode'] ?? 'com_cnpj') . '.'
            );
        }

        echo ReportView::infoCard('Estado da sincronização', [
            ['Ambiente', $environment !== '' ? $environment : 'producao'],
            ['CNPJ consulta', (string) (($state['cnpj_consulta'] ?? '') !== '' ? $state['cnpj_consulta'] : '-')],
            ['Último NSU', (int) ($state['ultimo_nsu'] ?? 0)],
            ['Maior NSU conhecido', (int) ($state['maior_nsu'] ?? 0)],
            ['Último sync', (string) (($state['ultimo_sync_em'] ?? '') !== '' ? $this->formatDate((string) $state['ultimo_sync_em'], 'd/m/Y H:i') : '-')],
            ['Modo usado', (string) (($state['last_sync_mode'] ?? '') !== '' ? $state['last_sync_mode'] : '-')],
        ]);

        if (!empty($diagnostics)) {
            $note = '';
            if (!empty($diagnostics['tested_at'])) {
                $note = 'Último teste em <b>' . Ui::h($this->formatDate((string) $diagnostics['tested_at'], 'd/m/Y H:i:s')) . '</b>, partindo do NSU <b>' . (int) ($diagnostics['initial_nsu'] ?? 0) . '</b>.';
            }
            $joinMessages = static function (array $messages): string {
                $parts = [];
                foreach ($messages as $message) {
                    if (!is_array($message)) {
                        continue;
                    }
                    $text = trim((string) (($message['mensagem'] ?? '') !== '' ? $message['mensagem'] : ($message['descricao'] ?? '')));
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }

                return !empty($parts) ? implode(' | ', $parts) : '-';
            };

            echo '<section class="nfse-card nfse-card--pad nfse-rel-info" aria-labelledby="nfse-api-diagnostico">';
            echo '<h3 class="nfse-card__title" id="nfse-api-diagnostico">Diagnóstico da distribuição</h3>';
            if ($note !== '') {
                echo '<div class="nfse-rel-note">' . $note . '</div>';
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

                echo '<div class="nfse-rel-sub">';
                echo '<div class="nfse-rel-sub__title">' . Ui::h($diagLabel) . '</div>';
                echo ReportView::keyValues([
                    ['Modo', (string) ($diag['mode'] ?? '-')],
                    ['CNPJ', (string) (($diag['cnpj_consulta'] ?? '') !== '' ? $diag['cnpj_consulta'] : '-')],
                    ['Processados', (int) ($diag['processed_count'] ?? 0)],
                    ['Lotes', (int) ($diag['batch_count'] ?? 0)],
                    ['Respostas vazias', (int) ($diag['empty_response_count'] ?? 0)],
                    ['Último NSU', (int) ($diag['ultimo_nsu'] ?? 0)],
                    ['Maior NSU', (int) ($diag['maior_nsu'] ?? 0)],
                ]);
                if (!empty($diag['error_message'])) {
                    echo Ui::callout('danger', 'Erro', (string) $diag['error_message']);
                }
                $alerts = is_array($diag['alertas'] ?? null) ? $diag['alertas'] : [];
                if (!empty($alerts)) {
                    echo '<div class="nfse-rel-note"><b>Alertas:</b> ' . Ui::h($joinMessages($alerts)) . '</div>';
                }
                $errors = is_array($diag['errors'] ?? null) ? $diag['errors'] : [];
                if (!empty($errors)) {
                    echo '<div class="nfse-rel-note nfse-text-danger"><b>Erros:</b> ' . Ui::h($joinMessages($errors)) . '</div>';
                }
                if (!empty($diag['raw_response_summary'])) {
                    echo '<div class="nfse-rel-note nfse-break"><b>Resumo bruto:</b> ' . Ui::h((string) $diag['raw_response_summary']) . '</div>';
                }
                echo '</div>';
            }
            echo '</section>';
        }

        $missingLocal = (array) ($comparison['missing_local'] ?? []);
        $missingApi = (array) ($comparison['missing_api'] ?? []);
        echo Ui::kpis([
            ['label' => 'API no período', 'value' => (int) ($apiSummary['total_docs'] ?? 0)],
            ['label' => 'Local no período', 'value' => (int) ($localSummary['total_docs'] ?? 0)],
            ['label' => 'API sem local', 'value' => count($missingLocal), 'tone' => count($missingLocal) > 0 ? 'warn' : ''],
            ['label' => 'Local sem API', 'value' => count($missingApi), 'tone' => count($missingApi) > 0 ? 'danger' : ''],
            ['label' => 'Correspondências', 'value' => (int) ($comparison['matched_count'] ?? 0)],
        ]);

        $localHtml = '';
        foreach ($missingLocal as $row) {
            $localHtml .= '<tr>'
                . '<td class="nfse-nowrap">' . Ui::h($this->formatDate((string) ($row['referencia_em'] ?? ''), 'd/m/Y H:i')) . '</td>'
                . '<td>' . ReportView::orDash((string) ($row['tipo_documento'] ?? '')) . '</td>'
                . '<td class="nfse-num">' . (int) ($row['nsu'] ?? 0) . '</td>'
                . '<td class="nfse-break nfse-mono">' . ReportView::orDash((string) ($row['chave_acesso'] ?? '')) . '</td>'
                . '<td class="nfse-num">' . ReportView::orDash((string) ($row['numero_nf'] ?? '')) . '</td>'
                . '<td>' . ReportView::orDash((string) ($row['tipo_evento'] ?? '')) . '</td>'
                . '<td class="nfse-break nfse-mono">' . ReportView::orDash((string) ($row['_compare_key'] ?? '')) . '</td>'
                . '</tr>';
        }
        echo ReportView::tableCard(
            ['Data', 'Tipo', ['NSU', 'nfse-num'], 'Chave', ['NFS-e', 'nfse-num'], 'Evento', 'Chave de comparação'],
            $localHtml,
            'Nenhum documento da API ficou sem correspondente local no período.',
            'Documentos recebidos da API sem correspondente local',
            count($missingLocal) . ' documentos',
            '',
            'nfse-api-sem-local'
        );

        $apiHtml = '';
        foreach ($missingApi as $row) {
            $apiHtml .= '<tr>'
                . '<td class="nfse-nowrap">' . Ui::h($this->formatDate((string) ($row['_event_date'] ?? ''), 'd/m/Y H:i')) . '</td>'
                . '<td>' . ReportView::orDash((string) ($row['tipo_registro'] ?? '')) . '</td>'
                . '<td class="nfse-break nfse-mono">' . ReportView::orDash((string) ($row['chave_acesso'] ?? '')) . '</td>'
                . '<td class="nfse-num">' . ReportView::orDash((string) ($row['numero_nf'] ?? '')) . '</td>'
                . '<td>' . ReportView::invoiceLink((int) ($row['invoiceid'] ?? 0)) . '</td>'
                . '<td class="nfse-break">' . Ui::h($this->resolveClientName($row)) . '</td>'
                . '<td class="nfse-break nfse-mono">' . ReportView::orDash((string) ($row['_compare_key'] ?? '')) . '</td>'
                . '</tr>';
        }
        echo ReportView::tableCard(
            ['Data', 'Tipo', 'Chave', ['NFS-e', 'nfse-num'], 'Fatura', 'Cliente', 'Chave de comparação'],
            $apiHtml,
            'Nenhum registro local ficou sem correspondente na API distribuida para o período.',
            'Histórico local sem correspondente na API distribuída',
            count($missingApi) . ' registros',
            '',
            'nfse-api-sem-api'
        );
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

        $tipoSelect = ['' => 'Todos os tipos'];
        foreach ($tipoOptions as $t) {
            $tipoSelect[$t] = $t;
        }
        echo Ui::filterBar(
            ['hidden' => ['module' => 'OpenNfse', 'action' => 'relatorios', 'tab' => 'logs']],
            Ui::field('Data inicial', Ui::input('data_inicial', $dataInicial, 'date'))
            . Ui::field('Data final', Ui::input('data_final', $dataFinal, 'date'))
            . Ui::field('Fatura', Ui::input('invoiceid', $invoiceFilter, 'text', ['placeholder' => 'ID da fatura'], 'nfse-input--w-sm'))
            . Ui::field('Tipo', Ui::select('tipo', $tipoSelect, $tipoFilter, [], 'nfse-input--w-md'))
            . Ui::field('Buscar em request/response', Ui::input('q', $qFilter, 'text', ['placeholder' => 'Texto no request ou response'], 'nfse-input--w-lg')),
            Ui::button('Filtrar', ['primary']) . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=logs']),
            'Filtros dos logs'
        );

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
        $preview = static function (string $value, string $label): string {
            if ($value === '') {
                return '<span class="nfse-muted">-</span>';
            }

            return '<textarea class="nfse-code-preview" readonly rows="1" aria-label="' . Ui::h($label) . '">' . Ui::h($value) . '</textarea>';
        };

        $rowsHtml = '';
        foreach ($rows as $r) {
            $id = (int) ($r->id ?? 0);
            $tipo = (string) ($r->tipo ?? '');
            $created = (string) ($r->created_at ?? '');
            $req = (string) ($r->request ?? '');
            $resp = (string) ($r->response ?? '');
            $notaInvoiceId = (int) ($r->nota_invoiceid ?? 0);

            $invoiceId = $notaInvoiceId > 0 ? $notaInvoiceId : ($extractInvoiceId($req) ?? $extractInvoiceId($resp) ?? 0);

            $rowsHtml .= '<tr>'
                . '<td class="nfse-num">' . $id . '</td>'
                . '<td class="nfse-nowrap">' . Ui::h($created) . '</td>'
                . '<td class="nfse-break">' . Ui::h($tipo) . '</td>'
                . '<td>' . ReportView::invoiceLink($invoiceId) . '</td>'
                . '<td>' . $preview($req, 'Request do log ' . $id) . '</td>'
                . '<td>' . $preview($resp, 'Response do log ' . $id) . '</td>'
                . '<td>' . $this->postButton('logView', 'Abrir', ['id' => $id]) . '</td>'
                . '</tr>';
        }

        $totalPages = (int) ceil($total / $perPage);
        if ($totalPages < 1) {
            $totalPages = 1;
        }
        if ($page > $totalPages) {
            $page = $totalPages;
        }

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
        $firstItem = $total > 0 ? ($offset + 1) : 0;
        $lastItem = min($offset + count($rows), $total);
        echo ReportView::tableCard(
            [['ID', 'nfse-num'], 'Data', 'Tipo', 'Fatura', 'Request', 'Response', ''],
            $rowsHtml,
            'Nenhum log encontrado para os critérios selecionados.',
            '',
            '',
            $this->pagedFooter(
                $firstItem,
                $lastItem,
                $total,
                'logs',
                $baseParams,
                $page,
                $totalPages,
                $this->postButton('relatoriosExport', 'Exportar CSV', [
                    'tab' => 'logs',
                    'data_inicial' => $dataInicial,
                    'data_final' => $dataFinal,
                    'invoiceid' => $invoiceFilter,
                    'tipo' => $tipoFilter,
                    'q' => $qFilter,
                ])
            ),
            'nfse-rel-secao'
        );
    }


    public function exportRelatoriosCsv(): void
    {
        $tab = trim((string) ($_REQUEST['tab'] ?? 'emitidas'));
        $allowed = ['emitidas' => true, 'falhas' => true, 'cancelamentos' => true, 'auditoria' => true, 'logs' => true, NfseNaoPagasReportController::TAB => true];
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

        if ($tab === NfseNaoPagasReportController::TAB) {
            (new NfseNaoPagasReportController())->writeCsv($out);
            fclose($out);
            exit;
        }

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
            $this->ensureFiscalHistoryReady();
            $rows = (new ReportRepository())->listCancelamentos([
                'data_inicial' => $dataInicial,
                'data_final' => $dataFinal,
                'cliente' => $cliente,
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
        $useCompetenciaLayout = $zipLayout === 'competencia' && $tab === 'emitidas';
        $this->ensureFiscalHistoryReady();
        $repo = new ReportRepository();
        $documentService = new FiscalDocumentReportService();
        if ($useCompetenciaLayout) {
            // Por competência, um documento por chave de acesso, a partir do histórico fiscal.
            $notaRows = $repo->listNotas($filters, 5000);
            $documents = $documentService->buildCompetenciaZipDocuments(
                $repo->listHistoryDocuments($filters, 20000),
                $notaRows,
                explode(',', $status),
                $repo->listHistoryChaves(array_column($notaRows, 'chave_acesso'))
            );
        } else {
            $rows = $tab === 'cancelamentos'
                ? $repo->listCancelamentos($filters, 5000)
                : $repo->listNotas($filters, 5000);
            $documents = [];
            foreach ($rows as $row) {
                $documents[] = ['directory' => '', 'paths' => $this->extractZipXmlPathsFromRow($row), 'row' => $row];
            }
        }
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

        if ($useCompetenciaLayout) {
            foreach (FiscalDocumentReportService::competenciaZipDirectories() as $directory) {
                $zip->addEmptyDir($directory);
            }
        }

        $added = 0;
        foreach ($documents as $document) {
            $row = $document['row'];
            $directory = $document['directory'];
            foreach ($document['paths'] as $xmlPath) {
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

        Module::ui()->renderHeader('OpenNfse');
        $this->renderTabs('relatorios', 'Detalhe do log');

        $invoiceId = (int) ($row->invoiceid ?? 0);
        $back = 'addonmodules.php?module=OpenNfse&action=relatorios&tab=logs';
        echo '<div class="nfse-ui nfse-page">';
        echo Ui::sectionHeader('Log #' . (int) ($row->id ?? $id), (string) ($row->tipo ?? ''), Ui::button('Voltar', ['ghost'], ['href' => $back]), 'nfse-log-titulo');
        echo ReportView::infoCard('', [
            ['Tipo', (string) ($row->tipo ?? '')],
            ['Data', (string) ($row->created_at ?? '')],
            ['Fatura', $invoiceId > 0 ? ReportView::invoiceLink($invoiceId) : '-', $invoiceId > 0],
        ]);
        echo '<section class="nfse-card nfse-card--pad" aria-labelledby="nfse-log-titulo"><div class="nfse-split2">';
        echo '<div class="nfse-split2__item"><label class="nfse-split2__title" for="nfse-log-request">Request</label>';
        echo '<textarea id="nfse-log-request" class="nfse-code" readonly>' . Ui::h((string) ($row->request ?? '')) . '</textarea></div>';
        echo '<div class="nfse-split2__item"><label class="nfse-split2__title" for="nfse-log-response">Response</label>';
        echo '<textarea id="nfse-log-response" class="nfse-code" readonly>' . Ui::h((string) ($row->response ?? '')) . '</textarea></div>';
        echo '</div></section>';
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

        Module::ui()->renderHeader('OpenNfse');
        $this->renderTabs('relatorios', 'Logs da nota');

        $back = 'invoices.php?action=edit&id=' . $invoiceId;
        echo '<div class="nfse-ui nfse-page">';
        echo Ui::sectionHeader('Fatura #' . $invoiceId, 'Até 100 registros mais recentes da nota desta fatura.', Ui::button('Voltar para a fatura', ['ghost'], ['href' => $back]));
        if (count($rows) === 0) {
            echo Ui::callout('info', 'Nenhum log registrado para esta nota.');
        }

        foreach ($rows as $r) {
            $tipo = (string) ($r->tipo ?? '');
            $created = (string) ($r->created_at ?? '');
            $req = (string) ($r->request ?? '');
            $resp = (string) ($r->response ?? '');

            echo '<section class="nfse-card">';
            echo '<div class="nfse-card__head"><h3 class="nfse-card__title">' . Ui::h($tipo) . '</h3><span class="nfse-card__summary">' . Ui::h($created) . '</span></div>';
            echo '<div class="nfse-card__body nfse-split2">';
            if ($req !== '') {
                echo '<div class="nfse-split2__item"><div class="nfse-split2__title">Request</div><pre class="nfse-code">' . Ui::h($req) . '</pre></div>';
            }
            if ($resp !== '') {
                echo '<div class="nfse-split2__item"><div class="nfse-split2__title">Response</div><pre class="nfse-code">' . Ui::h($resp) . '</pre></div>';
            }
            echo '</div>';
            echo '</section>';
        }
        echo '</div>';

        Module::ui()->renderFooter();
    }

}
