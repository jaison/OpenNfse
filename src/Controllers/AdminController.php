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
use OpenNfse\Services\UpdateCheckService;
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
            'checkUpdates' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para verificação de atualizações do módulo.',
                'handler' => [$this->configController, 'checkUpdates'],
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
        $dailySeries = $repo->dashboardDailySeries((string) ($metrics['range_start'] ?? ''), (string) ($metrics['range_end'] ?? ''));
        $config = (new ConfigRepository())->get();
        $updateStatus = (new UpdateCheckService())->getStatus($config);

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
        $systemUrl = '';
        if (isset($GLOBALS['CONFIG']['SystemURL']) && is_string($GLOBALS['CONFIG']['SystemURL'])) {
            $systemUrl = rtrim((string) $GLOBALS['CONFIG']['SystemURL'], '/');
        }
        $logoUrl = $systemUrl !== '' ? $systemUrl . '/modules/addons/OpenNfse/assets/opennsfe_logo.png' : '';
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
        $errosResolvidosPeriodo = (int) ($metrics['erros_resolvidos_periodo'] ?? 0);
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
            string $background = '#fff',
            string $valueStyle = ''
        ) use ($h): void {
            echo '<a href="' . $h($href) . '" style="flex:1 1 180px;min-width:180px;text-decoration:none;color:inherit;">';
            echo '<div style="height:100%;border:1px solid #ddd;border-left:4px solid ' . $h($accent) . ';padding:14px;background:' . $h($background) . ';box-sizing:border-box;">';
            echo '<div style="font-size:12px;color:#666;margin-bottom:6px;">' . $h($title) . '</div>';
            $resolvedValueStyle = 'font-size:28px;line-height:1.1;font-weight:700;color:#2f2f2f;margin-bottom:6px;';
            if (trim($valueStyle) !== '') {
                $resolvedValueStyle .= trim($valueStyle);
            }
            echo '<div style="' . $h($resolvedValueStyle) . '">' . $h($value) . '</div>';
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
        $renderRecentList = function (string $title, array $items, string $emptyMessage, callable $rowRenderer, string $wrapperStyle = '') use ($h): void {
            $resolvedWrapperStyle = $wrapperStyle !== '' ? $wrapperStyle : 'flex:1 1 320px;min-width:320px;border:1px solid #ddd;background:#fff;';
            echo '<div style="' . $h($resolvedWrapperStyle) . '">';
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
        if (!empty($updateStatus['update_available'])) {
            $latestVersion = trim((string) ($updateStatus['latest_version'] ?? ''));
            $currentVersion = trim((string) ($updateStatus['current_version'] ?? ''));
            $summary = trim((string) ($updateStatus['summary'] ?? ''));
            $message = trim((string) ($updateStatus['message'] ?? ''));
            $downloadUrl = trim((string) ($updateStatus['download_url'] ?? ''));
            $changelogUrl = trim((string) ($updateStatus['changelog_url'] ?? ''));
            $configUrl = 'addonmodules.php?module=OpenNfse&action=config&tab=processamento';
            $lastCheckedAt = $this->formatDate((string) ($updateStatus['last_checked_at'] ?? ''), 'd/m/Y H:i');

            echo '<div style="margin-bottom:14px;border:1px solid #f0c36d;border-left:4px solid #d9822b;padding:14px;background:#fff8e8;">';
            echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">';
            echo '<div style="min-width:280px;flex:1 1 520px;">';
            echo '<div style="font-size:16px;font-weight:700;color:#8a4b08;margin-bottom:6px;">Atualização disponível para o OpenNFS-e</div>';
            echo '<div style="font-size:13px;color:#5f4b32;line-height:1.5;margin-bottom:8px;">';
            echo 'Versão atual: <strong>' . $h($currentVersion !== '' ? $currentVersion : '—') . '</strong> ';
            echo '• Última versão: <strong>' . $h($latestVersion !== '' ? $latestVersion : '—') . '</strong>';
            if ($lastCheckedAt !== '—') {
                echo ' • Última checagem: <strong>' . $h($lastCheckedAt) . '</strong>';
            }
            echo '</div>';
            if ($summary !== '') {
                echo '<div style="font-size:12px;color:#6b5a45;line-height:1.5;">' . $h($summary) . '</div>';
            }
            if ($message !== '') {
                echo '<div style="font-size:12px;color:#6b5a45;line-height:1.5;margin-top:6px;"><strong>Notas da versão:</strong> ' . $h($message) . '</div>';
            }
            echo '</div>';
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
            echo '<a class="btn btn-sm btn-warning" href="' . $h($configUrl) . '">Ver detalhes</a>';
            if ($downloadUrl !== '') {
                echo '<a class="btn btn-sm btn-default" href="' . $h($downloadUrl) . '" target="_blank" rel="noopener noreferrer">Baixar atualização</a>';
            }
            if ($changelogUrl !== '' && $changelogUrl !== $downloadUrl) {
                echo '<a class="btn btn-sm btn-default" href="' . $h($changelogUrl) . '" target="_blank" rel="noopener noreferrer">Ver changelog</a>';
            }
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        echo '<div style="margin-bottom:14px;border:1px solid #ddd;padding:12px;background:#fafafa;">';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">';
        echo '<div style="display:flex;align-items:center;gap:12px;">';
        if ($logoUrl !== '') {
            echo '<img src="' . $h($logoUrl) . '" alt="OpenNfse" style="width:42px;height:42px;object-fit:contain;display:block;" />';
        }
        echo '<div>';
        echo '<div style="font-size:18px;font-weight:700;color:#333;line-height:1.1;">OpenNfse</div>';
        echo '<div style="font-size:12px;color:#666;margin-top:3px;">Dashboard operacional</div>';
        echo '</div>';
        echo '</div>';
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
        $renderMetricCard('Valor emitido no período', $this->formatMoney($valorTotal, 'R$ ', ''), 'Soma das NFS-e emitidas no intervalo', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams + ['status' => 'EMITIDA'], '', '&', PHP_QUERY_RFC3986), '#23527c', '#f7fbff', 'font-size:clamp(18px,2.3vw,28px);white-space:nowrap;letter-spacing:-0.02em;');
        echo '</div>';

        $ultimaEmissaoLabel = 'Nenhuma emissão encontrada no período';
        if ($ultimaEmissao !== null) {
            $ultimaEmissaoLabel = 'Invoice #' . (int) ($ultimaEmissao['invoiceid'] ?? 0) . ' em ' . $this->formatDate((string) ($ultimaEmissao['data'] ?? ''), 'd/m/Y H:i');
        }
        $ultimoErroLabel = 'Nenhum erro encontrado no período';
        if ($ultimoErro !== null) {
            $ultimoErroLabel = strtoupper(trim((string) ($ultimoErro['status'] ?? 'ERRO'))) . ' na invoice #' . (int) ($ultimoErro['invoiceid'] ?? 0) . ' em ' . $this->formatDate((string) ($ultimoErro['data'] ?? ''), 'd/m/Y H:i');
        }
        $dailyChartMax = 1;
        foreach ($dailySeries as $seriesDay) {
            $dailyChartMax = max(
                $dailyChartMax,
                (int) ($seriesDay['emitidas'] ?? 0),
                (int) ($seriesDay['canceladas'] ?? 0),
                (int) ($seriesDay['erros_resolvidos'] ?? 0)
            );
        }
        $dailyChartSvgWidth = max(760, 62 + (count($dailySeries) * 30));
        $dailyChartSvgHeight = 320;
        $dailyChartPaddingLeft = 44;
        $dailyChartPaddingRight = 18;
        $dailyChartPaddingTop = 18;
        $dailyChartPaddingBottom = 42;
        $dailyChartPlotWidth = max(620, $dailyChartSvgWidth - $dailyChartPaddingLeft - $dailyChartPaddingRight);
        $dailyChartPlotHeight = max(180, $dailyChartSvgHeight - $dailyChartPaddingTop - $dailyChartPaddingBottom);
        $dailyChartStep = count($dailySeries) > 1 ? ($dailyChartPlotWidth / (count($dailySeries) - 1)) : 0.0;
        $dailyChartBarWidth = (int) max(8, min(16, floor((count($dailySeries) > 1 ? $dailyChartStep : $dailyChartPlotWidth / 3) * 0.45)));
        $dailyChartBaseY = $dailyChartPaddingTop + $dailyChartPlotHeight;
        $chartY = static function (int $value) use ($dailyChartMax, $dailyChartPaddingTop, $dailyChartPlotHeight): float {
            return $dailyChartPaddingTop + $dailyChartPlotHeight - (($value / max(1, $dailyChartMax)) * $dailyChartPlotHeight);
        };
        $buildSmoothPath = static function (array $points): string {
            $count = count($points);
            if ($count === 0) {
                return '';
            }
            if ($count === 1) {
                return 'M ' . round((float) $points[0]['x'], 2) . ' ' . round((float) $points[0]['y'], 2);
            }

            $path = 'M ' . round((float) $points[0]['x'], 2) . ' ' . round((float) $points[0]['y'], 2);
            for ($i = 1; $i < $count - 1; $i++) {
                $midX = (((float) $points[$i]['x']) + ((float) $points[$i + 1]['x'])) / 2;
                $midY = (((float) $points[$i]['y']) + ((float) $points[$i + 1]['y'])) / 2;
                $path .= ' Q ' . round((float) $points[$i]['x'], 2) . ' ' . round((float) $points[$i]['y'], 2) . ' ' . round($midX, 2) . ' ' . round($midY, 2);
            }

            $path .= ' Q ' . round((float) $points[$count - 2]['x'], 2) . ' ' . round((float) $points[$count - 2]['y'], 2) . ' ' . round((float) $points[$count - 1]['x'], 2) . ' ' . round((float) $points[$count - 1]['y'], 2);

            return $path;
        };
        $buildAreaPath = static function (array $points, float $baseY, callable $lineBuilder): string {
            if (empty($points)) {
                return '';
            }

            $first = $points[0];
            $last = $points[count($points) - 1];
            return $lineBuilder($points)
                . ' L ' . round((float) $last['x'], 2) . ' ' . round($baseY, 2)
                . ' L ' . round((float) $first['x'], 2) . ' ' . round($baseY, 2)
                . ' Z';
        };
        $emitidasBars = [];
        $canceladasPoints = [];
        $errosResolvidosPoints = [];
        foreach (array_values($dailySeries) as $index => $seriesDay) {
            $x = count($dailySeries) > 1
                ? $dailyChartPaddingLeft + ($index * $dailyChartStep)
                : $dailyChartPaddingLeft + ($dailyChartPlotWidth / 2);
            $emitidasValue = (int) ($seriesDay['emitidas'] ?? 0);
            $canceladasValue = (int) ($seriesDay['canceladas'] ?? 0);
            $errosResolvidosValue = (int) ($seriesDay['erros_resolvidos'] ?? 0);

            $emitidasTop = $chartY($emitidasValue);
            $canceladasY = $chartY($canceladasValue);
            $errosResolvidosY = $chartY($errosResolvidosValue);

            $emitidasBars[] = [
                'x' => $x,
                'top' => $emitidasTop,
                'height' => max(0, $dailyChartBaseY - $emitidasTop),
                'value' => $emitidasValue,
                'label' => (string) ($seriesDay['label'] ?? ''),
            ];
            $canceladasPoints[] = [
                'x' => $x,
                'y' => $canceladasY,
                'value' => $canceladasValue,
                'label' => (string) ($seriesDay['label'] ?? ''),
            ];
            $errosResolvidosPoints[] = [
                'x' => $x,
                'y' => $errosResolvidosY,
                'value' => $errosResolvidosValue,
                'label' => (string) ($seriesDay['label'] ?? ''),
            ];
        }
        $canceladasPath = $buildSmoothPath($canceladasPoints);
        $errosResolvidosLinePath = $buildSmoothPath($errosResolvidosPoints);
        $errosResolvidosAreaPath = $buildAreaPath($errosResolvidosPoints, (float) $dailyChartBaseY, $buildSmoothPath);

        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:15px;">';
        $renderMiniCard('Total processadas', (string) $movimentadas, 'Notas movimentadas no período selecionado', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams + ['status' => 'EMITIDA,CANCELADA'], '', '&', PHP_QUERY_RFC3986), '#2f2f2f');
        $renderMiniCard('Taxa de sucesso', number_format($taxaSucesso, 1, ',', '.') . '%', 'Emitidas vs. itens com erro no período', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986), '#2d6ca2');
        $renderMiniCard('XMLs armazenados', (string) $xmls, 'Arquivos XML emitidos no período', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams + ['status' => 'EMITIDA,CANCELADA'], '', '&', PHP_QUERY_RFC3986), '#5f6b7a');
        $renderMiniCard('Aguardando status', (string) $aguardandoStatus, 'Itens da fila em consulta de status', 'addonmodules.php?module=OpenNfse&action=fila&status=WAIT_STATUS', '#8a6d3b');
        $renderMiniCard('Última emissão', $ultimaEmissao !== null ? ('#' . (int) ($ultimaEmissao['invoiceid'] ?? 0)) : '-', $ultimaEmissaoLabel, $ultimaEmissao !== null ? ('invoices.php?action=edit&id=' . (int) ($ultimaEmissao['invoiceid'] ?? 0)) : 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986), '#2e7d32');
        $renderMiniCard('Último erro', $ultimoErro !== null ? ('#' . (int) ($ultimoErro['invoiceid'] ?? 0)) : '-', $ultimoErroLabel, $ultimoErro !== null ? ('invoices.php?action=edit&id=' . (int) ($ultimoErro['invoiceid'] ?? 0)) : 'addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986), '#c62828');
        echo '</div>';

        echo '<div style="display:flex;gap:15px;align-items:stretch;flex-wrap:wrap;margin-bottom:15px;">';
        echo '<div style="flex:1 1 260px;min-width:260px;border:1px solid #ddd;background:#fff;">';
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
        }, 'flex:1 1 260px;min-width:260px;border:1px solid #ddd;background:#fff;');
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
        }, 'flex:1 1 260px;min-width:260px;border:1px solid #ddd;background:#fff;');
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
        }, 'flex:1 1 260px;min-width:260px;border:1px solid #ddd;background:#fff;');
        echo '</div>';

        echo '<div style="margin-bottom:10px;border:1px solid #ddd;background:#fff;">';
        echo '<div style="padding:12px 14px;border-bottom:1px solid #eee;background:#fafafa;">';
        echo '<div style="font-size:13px;font-weight:700;color:#333;">Gráfico do período</div>';
        echo '<div style="font-size:11px;color:#777;margin-top:4px;">Visualização diária sobreposta: barras para Emitidas, linha para Canceladas e curva/onda para Erros/Resolvidos.</div>';
        echo '</div>';
        echo '<div style="padding:16px;">';
        echo '<div style="overflow-x:auto;padding-bottom:4px;">';
        echo '<div style="min-width:' . $h((string) $dailyChartSvgWidth) . 'px;padding:14px;border:1px solid #f0f0f0;background:linear-gradient(180deg,#ffffff 0%,#fafafa 100%);">';
        echo '<svg viewBox="0 0 ' . $h((string) $dailyChartSvgWidth) . ' ' . $h((string) $dailyChartSvgHeight) . '" style="width:100%;height:auto;display:block;" role="img" aria-label="Gráfico diário do período">';
        for ($gridIndex = 0; $gridIndex <= 4; $gridIndex++) {
            $gridValue = (int) round(($dailyChartMax / 4) * (4 - $gridIndex));
            $gridY = $dailyChartPaddingTop + (($dailyChartPlotHeight / 4) * $gridIndex);
            echo '<line x1="' . $h((string) $dailyChartPaddingLeft) . '" y1="' . $h((string) round($gridY, 2)) . '" x2="' . $h((string) ($dailyChartPaddingLeft + $dailyChartPlotWidth)) . '" y2="' . $h((string) round($gridY, 2)) . '" stroke="#e6e6e6" stroke-width="1" />';
            echo '<text x="' . $h((string) ($dailyChartPaddingLeft - 8)) . '" y="' . $h((string) round($gridY + 4, 2)) . '" text-anchor="end" font-size="11" fill="#777">' . $h((string) $gridValue) . '</text>';
        }
        if ($errosResolvidosAreaPath !== '') {
            echo '<path d="' . $h($errosResolvidosAreaPath) . '" fill="rgba(245,124,0,0.18)" stroke="none" />';
        }
        foreach ($emitidasBars as $index => $bar) {
            $seriesDay = $dailySeries[$index] ?? [];
            $dateKey = (string) ($seriesDay['date'] ?? '');
            $emitidasUrl = 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query(['data_inicial' => $dateKey, 'data_final' => $dateKey, 'status' => 'EMITIDA'], '', '&', PHP_QUERY_RFC3986);
            echo '<a xlink:href="' . $h($emitidasUrl) . '">';
            echo '<rect x="' . $h((string) round(((float) $bar['x']) - ($dailyChartBarWidth / 2), 2)) . '" y="' . $h((string) round((float) $bar['top'], 2)) . '" width="' . $h((string) $dailyChartBarWidth) . '" height="' . $h((string) round((float) $bar['height'], 2)) . '" rx="4" ry="4" fill="rgba(46,125,50,0.45)" stroke="rgba(46,125,50,0.9)" stroke-width="1"><title>Emitidas em ' . $h((string) ($bar['label'] ?? '')) . ': ' . $h((string) ($bar['value'] ?? 0)) . '</title></rect>';
            echo '</a>';
        }
        if ($errosResolvidosLinePath !== '') {
            echo '<path d="' . $h($errosResolvidosLinePath) . '" fill="none" stroke="#f57c00" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />';
        }
        if ($canceladasPath !== '') {
            echo '<path d="' . $h($canceladasPath) . '" fill="none" stroke="#c62828" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />';
        }
        foreach ($errosResolvidosPoints as $point) {
            echo '<circle cx="' . $h((string) round((float) $point['x'], 2)) . '" cy="' . $h((string) round((float) $point['y'], 2)) . '" r="3.5" fill="#f57c00" stroke="#fff" stroke-width="1.5"><title>Erros/Resolvidos em ' . $h((string) ($point['label'] ?? '')) . ': ' . $h((string) ($point['value'] ?? 0)) . '</title></circle>';
        }
        foreach ($canceladasPoints as $point) {
            echo '<circle cx="' . $h((string) round((float) $point['x'], 2)) . '" cy="' . $h((string) round((float) $point['y'], 2)) . '" r="3" fill="#c62828" stroke="#fff" stroke-width="1.5"><title>Canceladas em ' . $h((string) ($point['label'] ?? '')) . ': ' . $h((string) ($point['value'] ?? 0)) . '</title></circle>';
        }
        $labelStep = max(1, (int) ceil(max(1, count($dailySeries)) / 10));
        foreach (array_values($dailySeries) as $index => $seriesDay) {
            if ($index % $labelStep !== 0 && $index !== count($dailySeries) - 1) {
                continue;
            }
            $x = count($dailySeries) > 1
                ? $dailyChartPaddingLeft + ($index * $dailyChartStep)
                : $dailyChartPaddingLeft + ($dailyChartPlotWidth / 2);
            echo '<text x="' . $h((string) round($x, 2)) . '" y="' . $h((string) ($dailyChartBaseY + 22)) . '" text-anchor="middle" font-size="10" fill="#666">' . $h((string) ($seriesDay['label'] ?? '')) . '</text>';
        }
        echo '</svg>';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:14px;">';
        echo '<div style="display:flex;align-items:center;gap:6px;font-size:11px;color:#666;"><span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:#2e7d32;"></span><span>Emitidas</span></div>';
        echo '<div style="display:flex;align-items:center;gap:6px;font-size:11px;color:#666;"><span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:#c62828;"></span><span>Canceladas</span></div>';
        echo '<div style="display:flex;align-items:center;gap:6px;font-size:11px;color:#666;"><span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:#f57c00;"></span><span>Erros/Resolvidos</span></div>';
        echo '</div>';
        echo '<div style="font-size:11px;color:#777;margin-top:10px;">Cada ponto representa um dia do período. As barras permanecem clicáveis para abrir o relatório filtrado naquela data.</div>';
        echo '</div>';
        echo '</div>';

        Module::ui()->renderFooter();
    }
}
