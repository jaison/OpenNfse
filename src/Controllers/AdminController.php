<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Controllers\Support\AdminHelpersTrait;
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

final class AdminController
{
    use AdminHelpersTrait;

    private SequenciaisController $sequenciaisController;

    private ConfigController $configController;

    private ReportsController $reportsController;

    private NotasController $notasController;

    private QueueController $queueController;

    public function __construct()
    {
        $this->sequenciaisController = new SequenciaisController();
        $this->configController = new ConfigController($this->sequenciaisController);
        $this->reportsController = new ReportsController();
        $this->notasController = new NotasController();
        $this->queueController = new QueueController();
    }

    public function handle(string $action): void
    {
        Module::migrator()->up();

        $routes = [
            'dashboard' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => [$this, 'showDashboard'],
            ],
            'relatorios' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => [$this->reportsController, 'showRelatorios'],
            ],
            'relatoriosExport' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para exportação de relatórios.',
                'handler' => [$this->reportsController, 'exportRelatoriosCsv'],
            ],
            'relatoriosExportZip' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para exportação ZIP.',
                'handler' => [$this->reportsController, 'exportRelatoriosZip'],
            ],
            'logView' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para visualização detalhada de log.',
                'handler' => [$this->reportsController, 'showLogView'],
            ],
            'codigos' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => static function (): void {
                    header('Location: addonmodules.php?module=OpenNfse&action=config&tab=codigos');
                    exit;
                },
            ],
            'saveCodigos' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para salvar o mapeamento de códigos.',
                'handler' => [$this->configController, 'saveCodigosProdutosServicos'],
            ],
            'saveServiceNbsCatalog' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para cadastrar relação de código de serviço e NBS.',
                'handler' => [$this->configController, 'saveServiceNbsCatalog'],
            ],
            'updateServiceNbsCatalog' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para atualizar relação de código de serviço e NBS.',
                'handler' => [$this->configController, 'updateServiceNbsCatalog'],
            ],
            'deleteServiceNbsCatalog' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para exclusão do catálogo de código de serviço e NBS.',
                'handler' => [$this->configController, 'deleteServiceNbsCatalog'],
            ],
            'saveDanfseConfig' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para configuração do DANFS-e.',
                'handler' => [$this->configController, 'saveDanfseConfig'],
            ],
            'validateCertificate' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para validação de certificado.',
                'handler' => [$this->configController, 'validateCertificate'],
            ],
            'testConnection' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para teste de conexão.',
                'handler' => [$this->configController, 'testConnection'],
            ],
            'syncIbgeMunicipios' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para sincronização dos municípios IBGE.',
                'handler' => [$this->configController, 'syncIbgeMunicipios'],
            ],
            'approveIbgeHash' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para aprovação do hash dos municípios IBGE.',
                'handler' => [$this->configController, 'approveIbgeHash'],
            ],
            'relEmitidas' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => function (): void {
                    $this->redirectRelatorios('emitidas');
                },
            ],
            'relFalhas' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => function (): void {
                    $this->redirectRelatorios('falhas');
                },
            ],
            'relCancelamentos' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => function (): void {
                    $this->redirectRelatorios('cancelamentos');
                },
            ],
            'config' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => [$this->configController, 'showConfig'],
            ],
            'notas' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => [$this->notasController, 'showNotas'],
            ],
            'fila' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => [$this->queueController, 'showFila'],
            ],
            'logs' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => [$this->reportsController, 'showLogs'],
            ],
            'sequenciais' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => static function (): void {
                    header('Location: addonmodules.php?module=OpenNfse&action=config&tab=sequenciais');
                    exit;
                },
            ],
            'sequenciaisSet' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para ajuste manual de sequência.',
                'handler' => [$this->sequenciaisController, 'sequenciaisSet'],
            ],
            'sequenciaisBump' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para incremento de sequência.',
                'handler' => [$this->sequenciaisController, 'sequenciaisBump'],
            ],
            'sequenciaisInit' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para inicialização de sequência.',
                'handler' => [$this->sequenciaisController, 'sequenciaisInit'],
            ],
            'cancelForm' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => [$this->notasController, 'showCancelForm'],
            ],
            'cancel' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para cancelamento de NFS-e.',
                'handler' => [$this->notasController, 'cancelNfse'],
            ],
            'filaCheckNow' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para consulta manual da fila.',
                'handler' => [$this->queueController, 'filaCheckNow'],
            ],
            'filaProcessNow' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para processamento manual da fila.',
                'handler' => [$this->queueController, 'filaProcessNow'],
            ],
            'filaRetry' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para reprocessar item da fila.',
                'handler' => [$this->queueController, 'filaRetry'],
            ],
            'reemitir' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para reemissão de NFS-e.',
                'handler' => [$this->notasController, 'reemitir'],
            ],
            'emit' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para emissão de NFS-e.',
                'handler' => [$this->notasController, 'emit'],
            ],
            'status' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para consulta de status.',
                'handler' => [$this->notasController, 'status'],
            ],
            'downloadXml' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para download do XML.',
                'handler' => function (): void {
                    $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
                    $this->notasController->downloadXml($invoiceId);
                },
            ],
            'downloadPdf' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para download do PDF.',
                'handler' => function (): void {
                    $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
                    $this->notasController->downloadPdf($invoiceId);
                },
            ],
            'sendEmail' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para envio por e-mail da NFS-e.',
                'handler' => function (): void {
                    $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
                    $this->notasController->sendEmail($invoiceId);
                },
            ],
            'saveConfig' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para salvar configuração.',
                'handler' => [$this->configController, 'saveConfig'],
            ],
        ];

        if (!isset($routes[$action])) {
            $this->configController->showConfig();
            return;
        }

        $route = $routes[$action];

        if ($route['method'] === 'POST') {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                throw new NfseModuleException($route['requiresTokenMessage'] ?? 'Método inválido.');
            }
        }

        if ($route['requiresToken']) {
            (new TokenService())->validate();
        }

        ($route['handler'])();
    }

    public function showDashboard(): void
    {
        $periodo = trim((string) ($_GET['periodo'] ?? 'mes_atual'));
        $dataInicial = trim((string) ($_GET['data_inicial'] ?? ''));
        $dataFinal = trim((string) ($_GET['data_final'] ?? ''));

        $today = date('Y-m-d');
        if ($periodo === 'hoje') {
            $dataInicial = $today;
            $dataFinal = $today;
        } elseif ($periodo === '7_dias') {
            $dataInicial = date('Y-m-d', strtotime('-6 days'));
            $dataFinal = $today;
        } elseif ($periodo === 'mes_anterior') {
            $dataInicial = date('Y-m-01', strtotime('first day of last month'));
            $dataFinal = date('Y-m-t', strtotime('last day of last month'));
        } elseif ($periodo !== 'personalizado') {
            $periodo = 'mes_atual';
            $dataInicial = date('Y-m-01');
            $dataFinal = date('Y-m-t');
        }

        $repo = new ReportRepository();
        $metrics = $repo->dashboardOverview($dataInicial, $dataFinal);
        $recentEmitidas = $repo->dashboardRecentEmitidas((string) ($metrics['range_start'] ?? ''), (string) ($metrics['range_end'] ?? ''), 5);
        $recentErros = $repo->dashboardRecentIssues((string) ($metrics['range_start'] ?? ''), (string) ($metrics['range_end'] ?? ''), 5);
        $recentCanceladas = $repo->dashboardRecentCancelamentos((string) ($metrics['range_start'] ?? ''), (string) ($metrics['range_end'] ?? ''), 5);

        Module::ui()->renderHeader('Dashboard - OpenNFS-e');
        $this->renderTabs('dashboard');

        $rangeStart = $this->formatDate((string) ($metrics['range_start'] ?? ''), 'd/m/Y');
        $rangeEnd = $this->formatDate((string) ($metrics['range_end'] ?? ''), 'd/m/Y');
        $h = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $dashboardUrl = static function (array $params = []): string {
            $base = [
                'module' => 'OpenNfse',
                'action' => 'dashboard',
            ];

            if (!empty($params)) {
                $base = array_merge($base, $params);
            }

            return 'addonmodules.php?' . http_build_query($base, '', '&', PHP_QUERY_RFC3986);
        };
        $relatorioParams = [
            'data_inicial' => (string) ($metrics['range_start'] ?? ''),
            'data_final' => (string) ($metrics['range_end'] ?? ''),
        ];
        $emitidas = (int) ($metrics['emitidas'] ?? 0);
        $canceladas = (int) ($metrics['canceladas'] ?? 0);
        $rejeitadas = (int) ($metrics['rejeitadas'] ?? 0);
        $pendentes = (int) ($metrics['pendentes'] ?? 0);
        $aguardandoStatus = (int) ($metrics['aguardando_status'] ?? 0);
        $comErro = (int) ($metrics['com_erro'] ?? 0);
        $comErroPeriodo = (int) ($metrics['com_erro_periodo'] ?? 0);
        $movimentadas = (int) ($metrics['movimentadas'] ?? 0);
        $xmls = (int) ($metrics['xmls'] ?? 0);
        $valorTotal = (float) ($metrics['valor_total'] ?? 0);
        $taxaSucesso = (float) ($metrics['taxa_sucesso'] ?? 0);
        $ultimaEmissao = is_array($metrics['ultima_emissao'] ?? null) ? $metrics['ultima_emissao'] : null;
        $ultimoErro = is_array($metrics['ultimo_erro'] ?? null) ? $metrics['ultimo_erro'] : null;

        $renderMetricCard = static function (
            string $title,
            string $value,
            string $subtitle,
            string $href,
            string $accent,
            string $background = '#fff'
        ) use ($h): void {
            echo '<a href="' . $h($href) . '" style="flex:1 1 180px;min-width:180px;text-decoration:none;color:inherit;">';
            echo '<div style="height:100%;border:1px solid #ddd;border-left:4px solid ' . $h($accent) . ';padding:14px;background:' . $h($background) . ';box-sizing:border-box;">';
            echo '<div style="font-size:12px;color:#666;margin-bottom:6px;">' . $h($title) . '</div>';
            echo '<div style="font-size:28px;line-height:1.1;font-weight:700;color:#2f2f2f;margin-bottom:6px;">' . $h($value) . '</div>';
            echo '<div style="font-size:11px;color:#777;line-height:1.35;">' . $h($subtitle) . '</div>';
            echo '</div>';
            echo '</a>';
        };
        $renderMiniCard = static function (
            string $title,
            string $value,
            string $subtitle,
            string $href,
            string $accent
        ) use ($h): void {
            echo '<a href="' . $h($href) . '" style="flex:1 1 220px;min-width:220px;text-decoration:none;color:inherit;">';
            echo '<div style="height:100%;border:1px solid #ddd;padding:12px;background:#fff;box-sizing:border-box;">';
            echo '<div style="font-size:12px;color:#666;margin-bottom:5px;">' . $h($title) . '</div>';
            echo '<div style="font-size:20px;font-weight:700;color:' . $h($accent) . ';margin-bottom:4px;">' . $h($value) . '</div>';
            echo '<div style="font-size:11px;color:#777;line-height:1.35;">' . $h($subtitle) . '</div>';
            echo '</div>';
            echo '</a>';
        };
        $renderRecentList = function (string $title, array $items, string $emptyMessage, callable $rowRenderer) use ($h): void {
            echo '<div style="flex:1 1 320px;min-width:320px;border:1px solid #ddd;background:#fff;">';
            echo '<div style="padding:12px 14px;border-bottom:1px solid #eee;background:#fafafa;font-size:13px;font-weight:700;color:#333;">' . $h($title) . '</div>';
            if (empty($items)) {
                echo '<div style="padding:14px;color:#777;font-size:12px;">' . $h($emptyMessage) . '</div>';
            } else {
                foreach ($items as $item) {
                    $rowRenderer($item);
                }
            }
            echo '</div>';
        };

        $rangeSummary = 'Período selecionado: ' . $rangeStart . ' a ' . $rangeEnd;
        echo '<div style="margin-bottom:14px;border:1px solid #ddd;padding:12px;background:#fafafa;">';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">';
        echo '<div style="font-size:15px;font-weight:700;color:#333;">Dashboard operacional</div>';
        echo '<div style="font-size:12px;color:#666;">' . $h($rangeSummary) . '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
        $presetButtons = [
            'hoje' => 'Hoje',
            '7_dias' => '7 dias',
            'mes_atual' => 'Este mês',
            'mes_anterior' => 'Mês anterior',
        ];
        foreach ($presetButtons as $key => $label) {
            $isActive = $periodo === $key;
            $style = 'display:inline-block;padding:6px 10px;border:1px solid #ccc;border-radius:3px;text-decoration:none;';
            $style .= $isActive ? 'background:#2d6ca2;color:#fff;border-color:#2d6ca2;' : 'background:#fff;color:#333;';
            echo '<a href="' . $h($dashboardUrl(['periodo' => $key])) . '" style="' . $h($style) . '">' . $h($label) . '</a>';
        }
        echo '<form method="get" action="addonmodules.php" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap;margin-left:auto;">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="dashboard" />';
        echo '<input type="hidden" name="periodo" value="personalizado" />';
        echo '<div><div style="font-size:11px;color:#666;margin-bottom:4px;">Data inicial</div><input type="date" name="data_inicial" value="' . $h((string) ($metrics['range_start'] ?? '')) . '" style="width:150px;" /></div>';
        echo '<div><div style="font-size:11px;color:#666;margin-bottom:4px;">Data final</div><input type="date" name="data_final" value="' . $h((string) ($metrics['range_end'] ?? '')) . '" style="width:150px;" /></div>';
        echo '<button type="submit" class="btn btn-xs btn-default">Aplicar</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:15px;">';
        $renderMetricCard('Emitidas no período', (string) $emitidas, 'Abre Relatórios > Emitidas filtrado', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams + ['status' => 'EMITIDA'], '', '&', PHP_QUERY_RFC3986), '#2e7d32', '#f7fcf8');
        $renderMetricCard('Canceladas no período', (string) $canceladas, 'Abre Relatórios > Cancelamentos filtrado', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=cancelamentos&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986), '#a67c00', '#fffdf6');
        $renderMetricCard('Rejeitadas no período', (string) $rejeitadas, 'Abre Notas filtrado por rejeitadas', 'addonmodules.php?module=OpenNfse&action=notas&status=REJEITADA&updated_from=' . rawurlencode((string) ($metrics['range_start'] ?? '')) . '&updated_to=' . rawurlencode((string) ($metrics['range_end'] ?? '')), '#8e44ad', '#fbf8fd');
        $renderMetricCard('Pendentes agora', (string) $pendentes, 'Fila ativa com itens aguardando processamento', 'addonmodules.php?module=OpenNfse&action=fila', '#c77d02', '#fffaf2');
        $renderMetricCard('Com erro agora', (string) $comErro, 'Pendências operacionais que exigem atenção', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986), '#c62828', '#fff8f8');
        $renderMetricCard('Valor emitido no período', $this->formatMoney($valorTotal, 'R$ ', ''), 'Soma das NFS-e emitidas no intervalo', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams + ['status' => 'EMITIDA'], '', '&', PHP_QUERY_RFC3986), '#23527c', '#f7fbff');
        echo '</div>';

        $ultimaEmissaoLabel = 'Nenhuma emissão encontrada no período';
        if ($ultimaEmissao !== null) {
            $ultimaEmissaoLabel = 'Invoice #' . (int) ($ultimaEmissao['invoiceid'] ?? 0) . ' em ' . $this->formatDate((string) ($ultimaEmissao['data'] ?? ''), 'd/m/Y H:i');
        }
        $ultimoErroLabel = 'Nenhum erro encontrado no período';
        if ($ultimoErro !== null) {
            $ultimoErroLabel = strtoupper(trim((string) ($ultimoErro['status'] ?? 'ERRO'))) . ' na invoice #' . (int) ($ultimoErro['invoiceid'] ?? 0) . ' em ' . $this->formatDate((string) ($ultimoErro['data'] ?? ''), 'd/m/Y H:i');
        }

        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:15px;">';
        $renderMiniCard('Total processadas', (string) $movimentadas, 'Notas movimentadas no período selecionado', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams + ['status' => 'EMITIDA,CANCELADA'], '', '&', PHP_QUERY_RFC3986), '#2f2f2f');
        $renderMiniCard('Taxa de sucesso', number_format($taxaSucesso, 1, ',', '.') . '%', 'Emitidas vs. itens com erro no período', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986), '#2d6ca2');
        $renderMiniCard('XMLs armazenados', (string) $xmls, 'Arquivos XML emitidos no período', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams + ['status' => 'EMITIDA,CANCELADA'], '', '&', PHP_QUERY_RFC3986), '#5f6b7a');
        $renderMiniCard('Aguardando status', (string) $aguardandoStatus, 'Itens da fila em consulta de status', 'addonmodules.php?module=OpenNfse&action=fila&status=WAIT_STATUS', '#8a6d3b');
        $renderMiniCard('Última emissão', $ultimaEmissao !== null ? ('#' . (int) ($ultimaEmissao['invoiceid'] ?? 0)) : '-', $ultimaEmissaoLabel, $ultimaEmissao !== null ? ('invoices.php?action=edit&id=' . (int) ($ultimaEmissao['invoiceid'] ?? 0)) : 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986), '#2e7d32');
        $renderMiniCard('Último erro', $ultimoErro !== null ? ('#' . (int) ($ultimoErro['invoiceid'] ?? 0)) : '-', $ultimoErroLabel, $ultimoErro !== null ? ('invoices.php?action=edit&id=' . (int) ($ultimoErro['invoiceid'] ?? 0)) : 'addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986), '#c62828');
        echo '</div>';

        echo '<div style="display:flex;gap:15px;align-items:stretch;flex-wrap:wrap;margin-bottom:15px;">';
        echo '<div style="flex:1 1 360px;min-width:360px;border:1px solid #ddd;background:#fff;">';
        echo '<div style="padding:12px 14px;border-bottom:1px solid #eee;background:#fafafa;font-size:13px;font-weight:700;color:#333;">Exigem atenção</div>';
        echo '<div style="padding:10px 14px;">';
        $attentionItems = [
            [
                'label' => 'Com erro agora',
                'count' => $comErro,
                'href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986),
                'color' => '#c62828',
                'desc' => 'Falhas operacionais abertas',
            ],
            [
                'label' => 'Pendentes agora',
                'count' => $pendentes,
                'href' => 'addonmodules.php?module=OpenNfse&action=fila',
                'color' => '#c77d02',
                'desc' => 'Fila aguardando processamento',
            ],
            [
                'label' => 'Aguardando status',
                'count' => $aguardandoStatus,
                'href' => 'addonmodules.php?module=OpenNfse&action=fila&status=WAIT_STATUS',
                'color' => '#8a6d3b',
                'desc' => 'Notas em consulta de status',
            ],
            [
                'label' => 'Rejeitadas no período',
                'count' => $rejeitadas,
                'href' => 'addonmodules.php?module=OpenNfse&action=notas&status=REJEITADA&updated_from=' . rawurlencode((string) ($metrics['range_start'] ?? '')) . '&updated_to=' . rawurlencode((string) ($metrics['range_end'] ?? '')),
                'color' => '#8e44ad',
                'desc' => 'Exigem correção manual',
            ],
        ];
        foreach ($attentionItems as $item) {
            echo '<a href="' . $h((string) $item['href']) . '" style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #f0f0f0;text-decoration:none;color:inherit;">';
            echo '<div><div style="font-size:13px;font-weight:600;color:#333;">' . $h((string) $item['label']) . '</div><div style="font-size:11px;color:#777;">' . $h((string) $item['desc']) . '</div></div>';
            echo '<span style="display:inline-block;min-width:30px;text-align:center;padding:3px 8px;border-radius:999px;background:' . $h((string) $item['color']) . ';color:#fff;font-size:11px;font-weight:700;">' . $h((string) $item['count']) . '</span>';
            echo '</a>';
        }
        echo '</div>';
        echo '</div>';

        echo '<div style="flex:1 1 360px;min-width:360px;border:1px solid #ddd;background:#fff;">';
        echo '<div style="padding:12px 14px;border-bottom:1px solid #eee;background:#fafafa;font-size:13px;font-weight:700;color:#333;">Ações rápidas</div>';
        echo '<div style="padding:12px 14px;display:flex;gap:8px;flex-wrap:wrap;">';
        echo '<a class="btn btn-xs btn-default" href="addonmodules.php?module=OpenNfse&action=fila&status=ERROR">Ir para fila com erro</a>';
        echo '<a class="btn btn-xs btn-default" href="addonmodules.php?module=OpenNfse&action=notas&status=REJEITADA&updated_from=' . $h((string) ($metrics['range_start'] ?? '')) . '&updated_to=' . $h((string) ($metrics['range_end'] ?? '')) . '">Ir para notas rejeitadas</a>';
        echo $this->renderPostActionButton('relatoriosExportZip', 'Baixar XMLs do período', ['tab' => 'emitidas'] + $relatorioParams + ['status' => 'EMITIDA,CANCELADA'], 'btn btn-xs btn-default');
        echo $this->renderPostActionButton('relatoriosExport', 'Exportar CSV emitidas', ['tab' => 'emitidas'] + $relatorioParams + ['status' => 'EMITIDA,CANCELADA'], 'btn btn-xs btn-default');
        echo '<a class="btn btn-xs btn-default" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas&' . $h(http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986)) . '">Abrir falhas do período</a>';
        echo '<a class="btn btn-xs btn-default" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=logs&data_inicial=' . $h((string) ($metrics['range_start'] ?? '')) . '&data_final=' . $h((string) ($metrics['range_end'] ?? '')) . '">Abrir logs do período</a>';
        echo '</div>';
        echo '<div style="padding:0 14px 14px 14px;font-size:11px;color:#777;line-height:1.4;">Atalhos para o fechamento mensal, investigação de falhas e acompanhamento da fila.</div>';
        echo '</div>';
        echo '</div>';

        echo '<div style="display:flex;gap:15px;align-items:flex-start;flex-wrap:wrap;margin-bottom:10px;">';
        $renderRecentList('Últimas emissões', $recentEmitidas, 'Nenhuma emissão no período.', function (array $item) use ($h) {
            $invoiceId = (int) ($item['invoiceid'] ?? 0);
            $numeroNf = trim((string) ($item['numero_nf'] ?? ''));
            $client = $this->resolveClientName($item);
            $data = $this->formatDate((string) ($item['data'] ?? ''), 'd/m/Y H:i');
            echo '<a href="' . $h('invoices.php?action=edit&id=' . $invoiceId) . '" style="display:block;padding:10px 14px;border-top:1px solid #f2f2f2;text-decoration:none;color:inherit;">';
            echo '<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;">';
            echo '<div><div style="font-size:13px;font-weight:600;color:#333;">Invoice #' . $h((string) $invoiceId) . ($numeroNf !== '' ? ' • NFS-e ' . $h($numeroNf) : '') . '</div><div style="font-size:11px;color:#777;">' . $h($client) . '</div></div>';
            echo '<div style="font-size:11px;color:#666;white-space:nowrap;">' . $h($data) . '</div>';
            echo '</div>';
            echo '</a>';
        });
        $renderRecentList('Últimos erros', $recentErros, 'Nenhum erro no período.', function (array $item) use ($h) {
            $invoiceId = (int) ($item['invoiceid'] ?? 0);
            $client = $this->resolveClientName($item);
            $status = trim((string) ($item['status'] ?? 'ERROR'));
            $erro = trim((string) ($item['erro'] ?? ''));
            if ($erro === '') {
                $erro = '-';
            }
            if (mb_strlen($erro) > 70) {
                $erro = mb_substr($erro, 0, 70) . '...';
            }
            $data = $this->formatDate((string) ($item['data'] ?? ''), 'd/m/Y H:i');
            echo '<a href="' . $h($invoiceId > 0 ? ('invoices.php?action=edit&id=' . $invoiceId) : 'addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas') . '" style="display:block;padding:10px 14px;border-top:1px solid #f2f2f2;text-decoration:none;color:inherit;">';
            echo '<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;margin-bottom:4px;">';
            echo '<div style="font-size:13px;font-weight:600;color:#333;">' . $h($status) . ' • Invoice #' . $h((string) $invoiceId) . '</div>';
            echo '<div style="font-size:11px;color:#666;white-space:nowrap;">' . $h($data) . '</div>';
            echo '</div>';
            echo '<div style="font-size:11px;color:#777;margin-bottom:4px;">' . $h($client) . '</div>';
            echo '<div style="font-size:11px;color:#555;line-height:1.4;">' . $h($erro) . '</div>';
            echo '</a>';
        });
        $renderRecentList('Últimos cancelamentos', $recentCanceladas, 'Nenhum cancelamento no período.', function (array $item) use ($h) {
            $invoiceId = (int) ($item['invoiceid'] ?? 0);
            $numeroNf = trim((string) ($item['numero_nf'] ?? ''));
            $client = $this->resolveClientName($item);
            $data = $this->formatDate((string) ($item['data'] ?? ''), 'd/m/Y H:i');
            echo '<a href="' . $h('invoices.php?action=edit&id=' . $invoiceId) . '" style="display:block;padding:10px 14px;border-top:1px solid #f2f2f2;text-decoration:none;color:inherit;">';
            echo '<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;">';
            echo '<div><div style="font-size:13px;font-weight:600;color:#333;">Invoice #' . $h((string) $invoiceId) . ($numeroNf !== '' ? ' • NFS-e ' . $h($numeroNf) : '') . '</div><div style="font-size:11px;color:#777;">' . $h($client) . '</div></div>';
            echo '<div style="font-size:11px;color:#666;white-space:nowrap;">' . $h($data) . '</div>';
            echo '</div>';
            echo '</a>';
        });
        echo '</div>';

        Module::ui()->renderFooter();
    }
}
