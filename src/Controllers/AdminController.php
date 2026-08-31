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
                'handler' => function (): void {
                    $this->redirectRelatorioEmitidas([], true);
                },
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
            'cancelOrphanXmlForm' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => [$this->reportsController, 'showCancelOrphanXmlForm'],
            ],
            'cancelOrphanXml' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para cancelamento de XML órfão.',
                'handler' => [$this->reportsController, 'cancelOrphanXml'],
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
        $recentErros = $repo->dashboardRecentIssues((string) ($metrics['range_start'] ?? ''), (string) ($metrics['range_end'] ?? ''), 4);
        $recentCanceladas = $repo->dashboardRecentCancelamentos((string) ($metrics['range_start'] ?? ''), (string) ($metrics['range_end'] ?? ''), 5);
        $dailySeries = $repo->dashboardDailySeries((string) ($metrics['range_start'] ?? ''), (string) ($metrics['range_end'] ?? ''));
        $config = (new ConfigRepository())->get();
        $updateStatus = (new UpdateCheckService())->getStatus($config);
        $certificateInfo = $this->configController->evaluateCertificateFromConfig($config);
        $auditoriaSummary = $this->reportsController->getAuditoriaDashboardSummary(
            null,
            (string) ($metrics['range_start'] ?? ''),
            (string) ($metrics['range_end'] ?? '')
        );

        Module::ui()->renderHeader('Dashboard - OpenNfse');
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
        $filaParams = [
            'updated_from' => (string) ($metrics['range_start'] ?? ''),
            'updated_to' => (string) ($metrics['range_end'] ?? ''),
        ];
        $emitidas = (int) ($metrics['emitidas'] ?? 0);
        $canceladas = (int) ($metrics['canceladas'] ?? 0);
        $rejeitadas = (int) ($metrics['rejeitadas'] ?? 0);
        $pendentes = (int) ($metrics['pendentes'] ?? 0);
        $aguardandoStatus = (int) ($metrics['aguardando_status'] ?? 0);
        $comErroPeriodo = (int) ($metrics['com_erro_periodo'] ?? 0);
        $movimentadas = (int) ($metrics['movimentadas'] ?? 0);
        $xmls = (int) ($metrics['xmls'] ?? 0);
        $valorTotal = (float) ($metrics['valor_total'] ?? 0);
        $taxaSucesso = (float) ($metrics['taxa_sucesso'] ?? 0);
        $ultimaEmissao = is_array($metrics['ultima_emissao'] ?? null) ? $metrics['ultima_emissao'] : null;
        $ultimoErro = is_array($metrics['ultimo_erro'] ?? null) ? $metrics['ultimo_erro'] : null;
        $todayDate = date('Y-m-d');
        $currentVersionBadge = trim((string) ($updateStatus['current_version'] ?? ''));
        if ($currentVersionBadge !== '') {
            $currentVersionBadge = 'v' . ltrim($currentVersionBadge, "vV ");
        } else {
            $currentVersionBadge = 'v—';
        }
        $certificateValidToRaw = trim((string) ($certificateInfo['valid_to'] ?? ''));
        $certificateValidToLabel = $certificateValidToRaw !== '' ? trim((string) strtok($certificateValidToRaw, ' ')) : 'Não configurado';
        $certificateDaysLeft = isset($certificateInfo['days_left']) && $certificateInfo['days_left'] !== '' ? (int) $certificateInfo['days_left'] : null;
        $certificateExpiresSoon = $certificateDaysLeft !== null && $certificateDaysLeft <= 30;
        $certificateExpired = trim((string) ($certificateInfo['status'] ?? '')) === 'expired';
        $certificateAttention = $certificateExpired || $certificateExpiresSoon;
        $certificateSummaryClass = 'opennfse-dashboard__summary-item';
        if ($certificateAttention) {
            $certificateSummaryClass .= ' opennfse-dashboard__summary-item--alert';
        }

        $renderMetricCard = static function (
            string $title,
            string $value,
            string $subtitle,
            string $href,
            string $accent,
            string $valueClass = '',
            bool $secondary = false
        ) use ($h): void {
            $tileClass = 'opennfse-dashboard__tile';
            if ($secondary) {
                $tileClass .= ' opennfse-dashboard__tile--secondary';
            }
            $cardClass = 'opennfse-dashboard__card opennfse-dashboard__metric-card';
            if ($secondary) {
                $cardClass .= ' opennfse-dashboard__metric-card--secondary';
            }
            $resolvedValueClass = 'opennfse-dashboard__metric-value';
            if (trim($valueClass) !== '') {
                $resolvedValueClass .= ' ' . trim($valueClass);
            }

            echo '<a href="' . $h($href) . '" class="' . $h($tileClass) . '" style="--accent:' . $h($accent) . ';">';
            echo '<div class="' . $h($cardClass) . '">';
            echo '<div class="opennfse-dashboard__metric-title">' . $h($title) . '</div>';
            echo '<div class="' . $h($resolvedValueClass) . '">' . $h($value) . '</div>';
            if (trim($subtitle) !== '') {
                echo '<div class="opennfse-dashboard__metric-meta">' . $h($subtitle) . '</div>';
            }
            echo '</div>';
            echo '</a>';
        };
        $renderRecentList = function (
            string $title,
            array $items,
            string $emptyMessage,
            callable $rowRenderer,
            string $panelClass = '',
            string $hint = '',
            string $emptyClass = '',
            string $headerActionHtml = ''
        ) use ($h): void {
            $resolvedPanelClass = trim('opennfse-dashboard__panel ' . $panelClass);
            $resolvedEmptyClass = trim('opennfse-dashboard__empty ' . $emptyClass);
            echo '<div class="opennfse-dashboard__tile opennfse-dashboard__tile--panel">';
            echo '<div class="' . $h($resolvedPanelClass) . '">';
            echo '<div class="opennfse-dashboard__panel-header">';
            echo '<div class="opennfse-dashboard__panel-title">' . $h($title) . '</div>';
            if ($headerActionHtml !== '') {
                echo $headerActionHtml;
            } elseif ($hint !== '') {
                echo '<div class="opennfse-dashboard__panel-hint">' . $h($hint) . '</div>';
            }
            echo '</div>';
            if (empty($items)) {
                echo '<div class="' . $h($resolvedEmptyClass) . '">' . $h($emptyMessage) . '</div>';
            } else {
                echo '<div class="opennfse-dashboard__list-body">';
                foreach ($items as $item) {
                    $rowRenderer($item);
                }
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
        };
        $normalizeIssueMessage = static function (string $error): string {
            $normalized = trim((string) preg_replace('/\s+/', ' ', $error));
            if ($normalized === '') {
                return '-';
            }

            if (preg_match('/Client error:\s*`[A-Z]+\s+[^`]+`\s+resulted in a\s+`(\d{3}\s+[A-Za-z ]+)`/i', $normalized, $matches) === 1) {
                $statusSummary = trim((string) ($matches[1] ?? ''));
                if ($statusSummary !== '') {
                    return 'Erro na requisição: ' . $statusSummary;
                }
            }

            if (preg_match('/Server error:\s*`[A-Z]+\s+[^`]+`\s+resulted in a\s+`(\d{3}\s+[A-Za-z ]+)`/i', $normalized, $matches) === 1) {
                $statusSummary = trim((string) ($matches[1] ?? ''));
                if ($statusSummary !== '') {
                    return 'Erro na requisição: ' . $statusSummary;
                }
            }

            $normalized = (string) preg_replace_callback('/https?:\/\/[^\s]+/i', static function (array $matches): string {
                $host = parse_url((string) ($matches[0] ?? ''), PHP_URL_HOST);
                if (is_string($host) && trim($host) !== '') {
                    return $host;
                }

                return 'URL omitida';
            }, $normalized);
            $normalized = str_replace([' for url ', ' for URI ', ' for uri '], ' - ', $normalized);
            $normalized = trim((string) preg_replace('/\s+/', ' ', $normalized), " \t\n\r\0\x0B-");

            if (mb_strlen($normalized) > 110) {
                $normalized = rtrim(mb_substr($normalized, 0, 107)) . '...';
            }

            return $normalized;
        };
        $taxaSucessoBase = $emitidas + $comErroPeriodo;
        $taxaSucessoMeta = $taxaSucessoBase > 0
            ? ($emitidas . ' de ' . $taxaSucessoBase . ' no período')
            : 'Sem operações consideradas no período';
        $chartEndDate = (string) ($metrics['range_end'] ?? '');
        if ($chartEndDate === '' || $chartEndDate > $todayDate) {
            $chartEndDate = $todayDate;
        }
        $chartRangeLimitedToToday = $chartEndDate !== '' && $chartEndDate !== (string) ($metrics['range_end'] ?? '');
        $chartSeries = array_values(array_filter(
            $dailySeries,
            static function (array $seriesDay) use ($chartEndDate): bool {
                $dateKey = (string) ($seriesDay['date'] ?? '');
                if ($dateKey === '' || $chartEndDate === '') {
                    return false;
                }

                return $dateKey <= $chartEndDate;
            }
        ));
        $auditoriaTotalInvoices = (int) ($auditoriaSummary['total_invoices'] ?? 0);
        $auditoriaHasGateways = (bool) ($auditoriaSummary['has_gateways'] ?? false);
        $auditoriaDescription = $auditoriaHasGateways ? 'Faturas pagas sem nota' : 'Sem gateways ativos para auditoria';
        $auditoriaUrl = 'addonmodules.php?module=OpenNfse&action=relatorios&tab=auditoria&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986);
        if (!empty($updateStatus['update_available'])) {
            $latestVersion = trim((string) ($updateStatus['latest_version'] ?? ''));
            $currentVersion = trim((string) ($updateStatus['current_version'] ?? ''));
            $summary = trim((string) ($updateStatus['summary'] ?? ''));
            $message = trim((string) ($updateStatus['message'] ?? ''));
            $downloadUrl = trim((string) ($updateStatus['download_url'] ?? ''));
            $changelogUrl = trim((string) ($updateStatus['changelog_url'] ?? ''));
            $configUrl = 'addonmodules.php?module=OpenNfse&action=config&tab=processamento';
            $lastCheckedAt = $this->formatDate((string) ($updateStatus['last_checked_at'] ?? ''), 'd/m/Y H:i');

            echo '<div class="opennfse-dashboard">';
            echo '<div class="opennfse-dashboard__banner">';
            echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">';
            echo '<div style="min-width:280px;flex:1 1 520px;">';
            echo '<div style="font-size:16px;font-weight:700;color:#8a4b08;margin-bottom:6px;">Atualização disponível para o OpenNfse</div>';
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
        } else {
            echo '<div class="opennfse-dashboard">';
        }

        echo '<div class="opennfse-dashboard__header">';
        echo '<div class="opennfse-dashboard__header-top">';
        echo '<div class="opennfse-dashboard__brand">';
        if ($logoUrl !== '') {
            echo '<img src="' . $h($logoUrl) . '" alt="OpenNfse" class="opennfse-dashboard__brand-logo" />';
        }
        echo '<div>';
        echo '<div class="opennfse-dashboard__brand-line"><div class="opennfse-dashboard__brand-name">OpenNfse</div><span class="opennfse-dashboard__version-badge">' . $h($currentVersionBadge) . '</span></div>';
        echo '<div class="opennfse-dashboard__brand-subtitle">Visão geral da operação fiscal</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="opennfse-dashboard__filters">';
        echo '<div class="opennfse-dashboard__preset-group">';
        $presetButtons = [
            'hoje' => 'Hoje',
            '7_dias' => '7 dias',
            'mes_atual' => 'Este mês',
            'mes_anterior' => 'Mês anterior',
        ];
        foreach ($presetButtons as $key => $label) {
            $isActive = $periodo === $key;
            $buttonClass = 'opennfse-dashboard__preset';
            if ($isActive) {
                $buttonClass .= ' is-active';
            }
            echo '<a href="' . $h($dashboardUrl(['periodo' => $key])) . '" class="' . $h($buttonClass) . '">' . $h($label) . '</a>';
        }
        echo '</div>';
        echo '<form method="get" action="addonmodules.php" class="opennfse-dashboard__date-form">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="dashboard" />';
        echo '<input type="hidden" name="periodo" value="personalizado" />';
        echo '<div class="opennfse-dashboard__date-field"><div class="opennfse-dashboard__date-label">Data inicial</div><input type="date" name="data_inicial" value="' . $h((string) ($metrics['range_start'] ?? '')) . '" class="opennfse-dashboard__date-input" /></div>';
        echo '<div class="opennfse-dashboard__date-field"><div class="opennfse-dashboard__date-label">Data final</div><input type="date" name="data_final" value="' . $h((string) ($metrics['range_end'] ?? '')) . '" class="opennfse-dashboard__date-input" /></div>';
        echo '<button type="submit" class="btn btn-default btn-sm">Aplicar</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div class="opennfse-dashboard__kpi-grid">';
        $renderMetricCard('Emitidas', (string) $emitidas, '', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams + ['status' => 'EMITIDA'], '', '&', PHP_QUERY_RFC3986), '#2e7d32');
        $renderMetricCard('Canceladas', (string) $canceladas, '', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=cancelamentos&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986), '#c75b12');
        $renderMetricCard('Rejeitadas', (string) $rejeitadas, '', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams + ['status' => 'REJEITADA'], '', '&', PHP_QUERY_RFC3986), '#8e44ad');
        $renderMetricCard('Pendentes', (string) $pendentes, '', 'addonmodules.php?module=OpenNfse&action=fila&' . http_build_query($filaParams, '', '&', PHP_QUERY_RFC3986), '#c77d02');
        $renderMetricCard('Com erro', (string) $comErroPeriodo, '', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986), '#c62828');
        $renderMetricCard('Valor emitido', $this->formatMoney($valorTotal, 'R$ ', ''), '', 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams + ['status' => 'EMITIDA'], '', '&', PHP_QUERY_RFC3986), '#23527c', 'opennfse-dashboard__metric-value--money');
        echo '</div>';

        echo '<div class="opennfse-dashboard__summary-strip">';
        echo '<div class="opennfse-dashboard__summary-item">';
        echo '<span class="opennfse-dashboard__summary-separator" aria-hidden="true">|</span>';
        echo '<span class="opennfse-dashboard__summary-value">' . $h((string) $movimentadas) . '</span>';
        echo '<span class="opennfse-dashboard__summary-label">Total processadas</span>';
        echo '</div>';
        echo '<div class="opennfse-dashboard__summary-item">';
        echo '<span class="opennfse-dashboard__summary-separator" aria-hidden="true">|</span>';
        echo '<span class="opennfse-dashboard__summary-value">' . $h(number_format($taxaSucesso, 1, ',', '.') . '%') . '</span>';
        echo '<span class="opennfse-dashboard__summary-label">Taxa de sucesso</span>';
        echo '</div>';
        echo '<div class="opennfse-dashboard__summary-item">';
        echo '<span class="opennfse-dashboard__summary-separator" aria-hidden="true">|</span>';
        echo '<span class="opennfse-dashboard__summary-value">' . $h((string) $xmls) . '</span>';
        echo '<span class="opennfse-dashboard__summary-label">XMLs armazenados</span>';
        echo '</div>';
        echo '<div class="' . $h($certificateSummaryClass) . '">';
        echo '<span class="opennfse-dashboard__summary-separator" aria-hidden="true">|</span>';
        if ($certificateAttention) {
            echo '<span class="opennfse-dashboard__summary-icon" aria-hidden="true">!</span>';
        }
        echo '<span class="opennfse-dashboard__summary-label">Vencimento do certificado:</span>';
        echo '<span class="opennfse-dashboard__summary-value">' . $h($certificateValidToLabel) . '</span>';
        echo '</div>';
        echo '<span class="opennfse-dashboard__summary-separator opennfse-dashboard__summary-separator--tail" aria-hidden="true">|</span>';
        echo '</div>';

        $chartPointCount = count($chartSeries);
        $dailyChartMax = 1;
        foreach ($chartSeries as $seriesDay) {
            $dailyChartMax = max(
                $dailyChartMax,
                (int) ($seriesDay['emitidas'] ?? 0),
                (int) ($seriesDay['canceladas'] ?? 0),
                (int) ($seriesDay['erros_resolvidos'] ?? 0)
            );
        }
        $dailyChartMinWidth = $chartPointCount <= 10 ? 980 : 760;
        $dailyChartSvgWidth = max($dailyChartMinWidth, 62 + ($chartPointCount * 30));
        $dailyChartSvgHeight = 180;
        $dailyChartPaddingLeft = $chartPointCount <= 10 ? 58 : 40;
        $dailyChartPaddingRight = $chartPointCount <= 10 ? 26 : 18;
        $dailyChartPaddingTop = $chartPointCount <= 10 ? 12 : 10;
        $dailyChartPaddingBottom = $chartPointCount <= 10 ? 36 : 24;
        $dailyChartPlotWidth = max(620, $dailyChartSvgWidth - $dailyChartPaddingLeft - $dailyChartPaddingRight);
        $dailyChartPlotHeight = max(100, $dailyChartSvgHeight - $dailyChartPaddingTop - $dailyChartPaddingBottom);
        $dailyChartStep = $chartPointCount > 1 ? ($dailyChartPlotWidth / ($chartPointCount - 1)) : 0.0;
        $dailyChartBarWidth = (int) max(8, min(16, floor(($chartPointCount > 1 ? $dailyChartStep : $dailyChartPlotWidth / 3) * 0.45)));
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
        foreach (array_values($chartSeries) as $index => $seriesDay) {
            $x = $chartPointCount > 1
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

        echo '<div class="opennfse-dashboard__lists-layout">';
        echo '<div class="opennfse-dashboard__tile opennfse-dashboard__tile--panel">';
        echo '<div class="opennfse-dashboard__panel opennfse-dashboard__panel--attention">';
        echo '<div class="opennfse-dashboard__panel-header">';
        echo '<div class="opennfse-dashboard__panel-title">Exigem atenção</div>';
        echo '</div>';
        echo '<div class="opennfse-dashboard__attention-body">';
        $attentionItems = [
            [
                'label' => 'Com erro no período',
                'count' => $comErroPeriodo,
                'href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986),
                'color' => '#c62828',
                'desc' => 'Falhas operacionais no intervalo',
            ],
            [
                'label' => 'Pendentes no período',
                'count' => $pendentes,
                'href' => 'addonmodules.php?module=OpenNfse&action=fila&' . http_build_query($filaParams, '', '&', PHP_QUERY_RFC3986),
                'color' => '#c77d02',
                'desc' => 'Fila movimentada no intervalo',
            ],
            [
                'label' => 'Aguardando status no período',
                'count' => $aguardandoStatus,
                'href' => 'addonmodules.php?module=OpenNfse&action=fila&status=WAIT_STATUS&' . http_build_query($filaParams, '', '&', PHP_QUERY_RFC3986),
                'color' => '#8a6d3b',
                'desc' => 'Consultas de status no intervalo',
            ],
            [
                'label' => 'Rejeitadas no período',
                'count' => $rejeitadas,
                'href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams + ['status' => 'REJEITADA'], '', '&', PHP_QUERY_RFC3986),
                'color' => '#8e44ad',
                'desc' => 'Exigem correção manual',
            ],
            [
                'label' => 'Não emitidas',
                'count' => $auditoriaTotalInvoices,
                'href' => $auditoriaUrl,
                'color' => '#c0392b',
                'desc' => $auditoriaDescription,
            ],
        ];
        foreach ($attentionItems as $item) {
            echo '<a href="' . $h((string) $item['href']) . '" class="opennfse-dashboard__attention-item">';
            echo '<div><div class="opennfse-dashboard__attention-title">' . $h((string) $item['label']) . '</div><div class="opennfse-dashboard__attention-desc">' . $h((string) $item['desc']) . '</div></div>';
            echo '<span class="opennfse-dashboard__count-badge" style="--accent:' . $h((string) $item['color']) . ';">' . $h((string) $item['count']) . '</span>';
            echo '</a>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
        $emitidasListAction = '<a href="' . $h('addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986)) . '" class="opennfse-dashboard__panel-action">Ver todos</a>';
        $renderRecentList('Últimas emissões', $recentEmitidas, '— Nenhuma emissão no período', function (array $item) use ($h) {
            $invoiceId = (int) ($item['invoiceid'] ?? 0);
            $numeroNf = trim((string) ($item['numero_nf'] ?? ''));
            $client = $this->resolveClientName($item);
            $data = $this->formatDate((string) ($item['data'] ?? ''), 'd/m/Y H:i');
            echo '<a href="' . $h('invoices.php?action=edit&id=' . $invoiceId) . '" class="opennfse-dashboard__list-row">';
            echo '<div class="opennfse-dashboard__list-row-top">';
            echo '<div><div class="opennfse-dashboard__list-primary">Invoice #' . $h((string) $invoiceId) . ($numeroNf !== '' ? ' • NFS-e ' . $h($numeroNf) : '') . '</div><div class="opennfse-dashboard__list-secondary">' . $h($client) . '</div></div>';
            echo '<div class="opennfse-dashboard__list-tertiary">' . $h($data) . '</div>';
            echo '</div>';
            echo '</a>';
        }, '', '', 'opennfse-dashboard__empty--neutral', $emitidasListAction);
        $cancelamentosListAction = '<a href="' . $h('addonmodules.php?module=OpenNfse&action=relatorios&tab=cancelamentos&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986)) . '" class="opennfse-dashboard__panel-action">Ver todos</a>';
        $renderRecentList('Últimos cancelamentos', $recentCanceladas, '— Nenhum cancelamento no período', function (array $item) use ($h) {
            $invoiceId = (int) ($item['invoiceid'] ?? 0);
            $numeroNf = trim((string) ($item['numero_nf'] ?? ''));
            $client = $this->resolveClientName($item);
            $data = $this->formatDate((string) ($item['data'] ?? ''), 'd/m/Y H:i');
            echo '<a href="' . $h('invoices.php?action=edit&id=' . $invoiceId) . '" class="opennfse-dashboard__list-row">';
            echo '<div class="opennfse-dashboard__list-row-top">';
            echo '<div><div class="opennfse-dashboard__list-primary">Invoice #' . $h((string) $invoiceId) . ($numeroNf !== '' ? ' • NFS-e ' . $h($numeroNf) : '') . '</div><div class="opennfse-dashboard__list-secondary">' . $h($client) . '</div></div>';
            echo '<div class="opennfse-dashboard__list-tertiary">' . $h($data) . '</div>';
            echo '</div>';
            echo '</a>';
        }, '', '', 'opennfse-dashboard__empty--neutral', $cancelamentosListAction);
        $errosListAction = '<a href="' . $h('addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas&' . http_build_query($relatorioParams, '', '&', PHP_QUERY_RFC3986)) . '" class="opennfse-dashboard__panel-action">Ver todos</a>';
        $renderRecentList('Últimos erros', $recentErros, '✓ Nenhum erro no período', function (array $item) use ($h, $normalizeIssueMessage) {
            $invoiceId = (int) ($item['invoiceid'] ?? 0);
            $client = $this->resolveClientName($item);
            $status = trim((string) ($item['status'] ?? 'ERROR'));
            $statusUpper = strtoupper($status);
            $statusBadgeClass = 'opennfse-dashboard__status-badge';
            if ($statusUpper === 'RESOLVIDO') {
                $statusBadgeClass .= ' opennfse-dashboard__status-badge--resolved';
            }
            $erro = trim((string) ($item['erro'] ?? ''));
            $erro = $normalizeIssueMessage($erro);
            $data = $this->formatDate((string) ($item['data'] ?? ''), 'd/m/Y H:i');
            $invoiceLabel = $invoiceId > 0 ? ('Invoice #' . $invoiceId) : 'Registro operacional';
            echo '<a href="' . $h($invoiceId > 0 ? ('invoices.php?action=edit&id=' . $invoiceId) : 'addonmodules.php?module=OpenNfse&action=relatorios&tab=falhas') . '" class="opennfse-dashboard__list-row">';
            echo '<div class="opennfse-dashboard__list-row-top">';
            echo '<div>';
            echo '<div class="opennfse-dashboard__status-row"><span class="opennfse-dashboard__list-primary">' . $h($invoiceLabel) . '</span><span class="' . $h($statusBadgeClass) . '">' . $h($status) . '</span></div>';
            echo '<div class="opennfse-dashboard__list-secondary">' . $h($client) . '</div>';
            echo '<div class="opennfse-dashboard__error-message">' . $h($erro) . '</div>';
            echo '</div>';
            echo '<div class="opennfse-dashboard__list-tertiary">' . $h($data) . '</div>';
            echo '</div>';
            echo '</a>';
        }, '', '', 'opennfse-dashboard__empty--positive', $errosListAction);
        echo '</div>';

        echo '<div class="opennfse-dashboard__panel opennfse-dashboard__chart">';
        echo '<div class="opennfse-dashboard__panel-header">';
        echo '<div class="opennfse-dashboard__panel-title">Emissões no período</div>';
        $chartInfoTitle = 'Cada ponto representa um dia do período. As barras continuam clicáveis para abrir o relatório filtrado naquela data.';
        if ($chartRangeLimitedToToday) {
            $chartInfoTitle = 'O gráfico mostra dias corridos até hoje quando o filtro inclui datas futuras. ' . $chartInfoTitle;
        }
        echo '<div class="opennfse-dashboard__panel-hint"><span class="opennfse-dashboard__info-pill" title="' . $h($chartInfoTitle) . '">i</span></div>';
        echo '</div>';
        echo '<div class="opennfse-dashboard__chart-body">';
        if (empty($chartSeries)) {
            echo '<div class="opennfse-dashboard__empty opennfse-dashboard__empty--neutral">— Sem dias corridos no intervalo até hoje</div>';
        } else {
            echo '<div class="opennfse-dashboard__chart-scroll">';
            echo '<div class="opennfse-dashboard__chart-frame" style="min-width:' . $h((string) $dailyChartSvgWidth) . 'px;">';
            echo '<svg viewBox="0 0 ' . $h((string) $dailyChartSvgWidth) . ' ' . $h((string) $dailyChartSvgHeight) . '" style="width:100%;height:auto;display:block;" role="img" aria-label="Gráfico diário do período">';
            for ($gridIndex = 0; $gridIndex <= 4; $gridIndex++) {
                $gridValue = (int) round(($dailyChartMax / 4) * (4 - $gridIndex));
                $gridY = $dailyChartPaddingTop + (($dailyChartPlotHeight / 4) * $gridIndex);
                echo '<line x1="' . $h((string) $dailyChartPaddingLeft) . '" y1="' . $h((string) round($gridY, 2)) . '" x2="' . $h((string) ($dailyChartPaddingLeft + $dailyChartPlotWidth)) . '" y2="' . $h((string) round($gridY, 2)) . '" stroke="rgba(100,116,139,0.09)" stroke-width="1" />';
                $yAxisLabelX = $dailyChartPaddingLeft - ($chartPointCount <= 10 ? 12 : 8);
                echo '<text x="' . $h((string) $yAxisLabelX) . '" y="' . $h((string) round($gridY + 4, 2)) . '" text-anchor="end" font-size="8" font-weight="400" fill="#7c8798">' . $h((string) $gridValue) . '</text>';
            }
            if ($errosResolvidosAreaPath !== '') {
                echo '<path d="' . $h($errosResolvidosAreaPath) . '" fill="rgba(217,119,6,0.04)" stroke="none" />';
            }
            foreach ($emitidasBars as $index => $bar) {
                $seriesDay = $chartSeries[$index] ?? [];
                $dateKey = (string) ($seriesDay['date'] ?? '');
                $emitidasUrl = 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas&' . http_build_query(['data_inicial' => $dateKey, 'data_final' => $dateKey, 'status' => 'EMITIDA'], '', '&', PHP_QUERY_RFC3986);
                echo '<a xlink:href="' . $h($emitidasUrl) . '">';
                echo '<rect x="' . $h((string) round(((float) $bar['x']) - ($dailyChartBarWidth / 2), 2)) . '" y="' . $h((string) round((float) $bar['top'], 2)) . '" width="' . $h((string) $dailyChartBarWidth) . '" height="' . $h((string) round((float) $bar['height'], 2)) . '" rx="3" ry="3" fill="rgba(46,125,50,0.16)" stroke="rgba(46,125,50,0.65)" stroke-width="1"><title>Emitidas em ' . $h((string) ($bar['label'] ?? '')) . ': ' . $h((string) ($bar['value'] ?? 0)) . '</title></rect>';
                echo '</a>';
            }
            if ($errosResolvidosLinePath !== '') {
                echo '<path d="' . $h($errosResolvidosLinePath) . '" fill="none" stroke="#d97706" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />';
            }
            if ($canceladasPath !== '') {
                echo '<path d="' . $h($canceladasPath) . '" fill="none" stroke="#c94a4a" stroke-opacity="0.72" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />';
            }
            foreach ($errosResolvidosPoints as $point) {
                echo '<circle cx="' . $h((string) round((float) $point['x'], 2)) . '" cy="' . $h((string) round((float) $point['y'], 2)) . '" r="2" fill="#d97706"><title>Erros/Resolvidos em ' . $h((string) ($point['label'] ?? '')) . ': ' . $h((string) ($point['value'] ?? 0)) . '</title></circle>';
            }
            foreach ($canceladasPoints as $point) {
                echo '<circle cx="' . $h((string) round((float) $point['x'], 2)) . '" cy="' . $h((string) round((float) $point['y'], 2)) . '" r="2" fill="#c94a4a" fill-opacity="0.9"><title>Canceladas em ' . $h((string) ($point['label'] ?? '')) . ': ' . $h((string) ($point['value'] ?? 0)) . '</title></circle>';
            }
            $labelStep = max(1, (int) ceil(max(1, $chartPointCount) / 10));
            foreach (array_values($chartSeries) as $index => $seriesDay) {
                if ($index % $labelStep !== 0 && $index !== $chartPointCount - 1) {
                    continue;
                }
                $x = $chartPointCount > 1
                    ? $dailyChartPaddingLeft + ($index * $dailyChartStep)
                    : $dailyChartPaddingLeft + ($dailyChartPlotWidth / 2);
                $xAxisLabelY = $dailyChartBaseY + ($chartPointCount <= 10 ? 24 : 18);
                echo '<text x="' . $h((string) round($x, 2)) . '" y="' . $h((string) $xAxisLabelY) . '" text-anchor="middle" font-size="8" font-weight="400" fill="#7c8798">' . $h((string) ($seriesDay['label'] ?? '')) . '</text>';
            }
            echo '</svg>';
            echo '</div>';
            echo '</div>';
            echo '<div class="opennfse-dashboard__chart-legend">';
            echo '<div class="opennfse-dashboard__legend-item"><span class="opennfse-dashboard__legend-dot" style="background:#2e7d32;"></span><span>Emitidas</span></div>';
            echo '<div class="opennfse-dashboard__legend-item"><span class="opennfse-dashboard__legend-dot" style="background:#c62828;"></span><span>Canceladas</span></div>';
            echo '<div class="opennfse-dashboard__legend-item"><span class="opennfse-dashboard__legend-dot" style="background:#f57c00;"></span><span>Erros/Resolvidos</span></div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';

        Module::ui()->renderFooter();
    }
}
