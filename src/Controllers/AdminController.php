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
use OpenNfse\Services\EmissionPolicyContextLoader;
use OpenNfse\Services\InvoiceEmailService;
use OpenNfse\Services\NfseService;
use OpenNfse\Services\QueueErrorClassifierService;
use OpenNfse\Services\QueueService;
use OpenNfse\Services\StorageService;
use OpenNfse\Services\TokenService;
use OpenNfse\Views\Dashboard\DashboardFeed;
use OpenNfse\Views\Dashboard\DashboardPeriod;
use OpenNfse\Views\Dashboard\DashboardView;
use WHMCS\Database\Capsule;

final class AdminController
{
    use AdminHelpersTrait;

    private SequenciaisController $sequenciaisController;

    private ConfigController $configController;

    private ReportsController $reportsController;

    private NotasController $notasController;

    private QueueController $queueController;

    private AlertasController $alertasController;

    public function __construct()
    {
        $this->sequenciaisController = new SequenciaisController();
        $this->configController = new ConfigController($this->sequenciaisController);
        $this->reportsController = new ReportsController();
        $this->notasController = new NotasController();
        $this->queueController = new QueueController();
        $this->alertasController = new AlertasController();
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
            'alertas' => [
                'method' => 'GET',
                'requiresToken' => false,
                'handler' => [$this->alertasController, 'showAlertas'],
            ],
            'alertaResolver' => [
                'method' => 'POST',
                'requiresToken' => true,
                'requiresTokenMessage' => 'Método inválido para resolver alerta de NFS-e.',
                'handler' => [$this->alertasController, 'resolveAlerta'],
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
        $today = date('Y-m-d');
        $period = DashboardPeriod::resolve(
            (string) ($_GET['periodo'] ?? 'mes_atual'),
            (string) ($_GET['data_inicial'] ?? ''),
            (string) ($_GET['data_final'] ?? ''),
            $today
        );

        $repo = new ReportRepository();
        $metrics = $repo->dashboardOverview($period['start'], $period['end']);
        $period['start'] = (string) ($metrics['range_start'] ?? $period['start']);
        $period['end'] = (string) ($metrics['range_end'] ?? $period['end']);
        $dailySeries = $repo->dashboardDailySeries($period['start'], $period['end']);

        // A atividade recente usa as mesmas consultas de antes, no dia escolhido ou no período.
        $dia = DashboardPeriod::selectedDay((string) ($_GET['dia'] ?? ''), $period['start'], $period['end']);
        $feedFilter = DashboardFeed::filter((string) ($_GET['feed'] ?? ''));
        $sources = DashboardFeed::sources($feedFilter);
        $feedFrom = $dia !== '' ? $dia : $period['start'];
        $feedTo = $dia !== '' ? $dia : $period['end'];
        $feedItems = DashboardFeed::merge(
            $sources['emitidas'] ? $repo->dashboardRecentEmitidas($feedFrom, $feedTo, DashboardFeed::LIMIT) : [],
            $sources['canceladas'] ? $repo->dashboardRecentCancelamentos($feedFrom, $feedTo, DashboardFeed::LIMIT) : [],
            $sources['erros'] ? $repo->dashboardRecentIssues($feedFrom, $feedTo, DashboardFeed::LIMIT) : [],
            $feedFilter
        );

        $config = (new ConfigRepository())->get();
        $auditoriaSummary = $this->reportsController->getAuditoriaDashboardSummary(null, $period['start'], $period['end']);
        $queueSnapshot = (new QueueRepository())->currentSnapshot();
        $certificate = $this->configController->evaluateCertificateFromConfig($config);

        $html = DashboardView::render([
            'today' => $today,
            'period' => $period,
            'metrics' => $metrics,
            'series' => $dailySeries,
            'dia' => $dia,
            'feed_filter' => $feedFilter,
            'feed_items' => $feedItems,
            'nao_emitidas' => (int) ($auditoriaSummary['total_invoices'] ?? 0),
            'alertas_abertos' => $this->alertasController->countOpen(),
            'certificate' => $certificate,
            'customfield_missing' => EmissionPolicyContextLoader::configuredCustomFieldMissing($config),
            'now' => time(),
            'queue' => $queueSnapshot,
            'cron' => [
                'last_run_at' => (string) ($config['cron_last_run_at'] ?? ''),
                'source' => (string) ($config['cron_last_source'] ?? ''),
            ],
            'token' => (new TokenService())->token(),
        ]);

        Module::ui()->renderHeader('Dashboard - OpenNfse');
        // A barra reaproveita a configuração e o estado da fila já lidos aqui; o certificado vai só na dica do título.
        $title = DashboardView::titleParts($period, $certificate);
        $this->renderTabs('dashboard', 'Visão geral', $title['subtitle'], ['config' => $config, 'queue' => $queueSnapshot], $title['detail']);
        echo $html;
        Module::ui()->renderFooter();
    }
}
