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
use OpenNfse\Services\IbgeService;
use OpenNfse\Services\InvoiceEmailService;
use OpenNfse\Services\NfseService;
use OpenNfse\Services\QueueErrorClassifierService;
use OpenNfse\Services\QueueService;
use OpenNfse\Services\StorageService;
use OpenNfse\Services\TokenService;
use WHMCS\Database\Capsule;
use OpenNfse\Controllers\Support\AdminHelpersTrait;

final class ConfigController
{
    use AdminHelpersTrait;

    private SequenciaisController $sequenciaisController;

    public function __construct(?SequenciaisController $sequenciaisController = null)
    {
        $this->sequenciaisController = $sequenciaisController ?? new SequenciaisController();
    }

    public function showConfig(): void
    {
        $repo = new ConfigRepository();
        $config = $repo->get();

        Module::ui()->renderHeader('Configuração - OpenNFS-e');
        $this->renderTabs('config');
        if ((string) ($_GET['saved'] ?? '') === '1') {
            echo '<div class="successbox">Configuração salva.</div>';
        }

        $activeTab = (string) ($_GET['tab'] ?? 'ambiente');
        $tabMeta = [
            'ambiente' => [
                'label' => 'Ambiente',
                'title' => 'Ambiente e Certificado',
                'description' => 'Defina o ambiente de operação e os dados do certificado digital usado nas requisições.',
            ],
            'prestador' => [
                'label' => 'Prestador de Serviços',
                'title' => 'Dados do Prestador',
                'description' => 'Informe a identificação fiscal e os dados cadastrais do emissor da NFS-e.',
            ],
            'endereco' => [
                'label' => 'Endereço do Prestador',
                'title' => 'Endereço do Prestador',
                'description' => 'Configure o endereço padrão enviado na identificação do prestador.',
            ],
            'servicosnbs' => [
                'label' => 'Código de Serviços e NBS',
                'title' => 'Código de Serviços e NBS',
                'description' => 'Mantenha o catálogo de códigos de serviço e NBS que alimenta as opções usadas nas demais configurações do módulo.',
            ],
            'tributacao' => [
                'label' => 'Tributação',
                'title' => 'Tributação e Regimes',
                'description' => 'Centralize código de serviço, NBS, alíquota e parâmetros tributários padrão.',
            ],
            'sequenciais' => [
                'label' => 'Sequenciais',
                'title' => 'Sequenciais de DPS',
                'description' => 'Acompanhe e ajuste a sequência numérica utilizada na geração dos DPS.',
            ],
            'integracao' => [
                'label' => 'Integração com WHMCS',
                'title' => 'Integração com WHMCS',
                'description' => 'Defina o relacionamento com custom fields e quais gateways ativam a emissão automática.',
            ],
            'codigos' => [
                'label' => 'Códigos Produtos/Serviços',
                'title' => 'Mapeamento de Grupos',
                'description' => 'Associe grupos do WHMCS aos códigos de serviço e NBS usados pelo módulo.',
            ],
            'danfse' => [
                'label' => 'PDF DANFS-e',
                'title' => 'PDF DANFS-e',
                'description' => 'Personalize o cabeçalho do PDF gerado e os dados institucionais exibidos no DANFS-e.',
            ],
            'tomador' => [
                'label' => 'Dados Padrão do Tomador',
                'title' => 'Dados Padrão do Tomador',
                'description' => 'Use valores de fallback quando o cadastro do tomador vier incompleto no WHMCS.',
            ],
            'processamento' => [
                'label' => 'Processamento Automático',
                'title' => 'Fila e Processamento',
                'description' => 'Controle a automação de emissão, fila, cron e intervalo de consulta de status.',
            ],
            'retencao' => [
                'label' => 'Retenção e Logs',
                'title' => 'Retenção e Logs',
                'description' => 'Defina por quantos dias filas concluídas e logs devem permanecer armazenados.',
            ],
        ];
        $allowedTabs = [];
        foreach ($tabMeta as $key => $meta) {
            $allowedTabs[$key] = (string) ($meta['label'] ?? $key);
        }
        if (!isset($allowedTabs[$activeTab])) {
            $activeTab = 'ambiente';
        }

        $h = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $msg = (string) ($_GET['msg'] ?? '');
        if ($msg === 'ibge_sync_ok') {
            $total = (int) ($_GET['total'] ?? 0);
            echo '<div class="successbox">Base de municípios IBGE sincronizada com sucesso' . ($total > 0 ? ' (' . $h((string) $total) . ' registros).' : '.') . '</div>';
        } elseif ($msg === 'ibge_sync_error') {
            echo '<div class="errorbox">Não foi possível sincronizar a base de municípios IBGE. Tente novamente em alguns instantes.</div>';
        } elseif ($msg === 'ibge_sync_pending_approval') {
            echo '<div class="errorbox">A fonte primária mudou desde o último hash aprovado. Revise os dados exibidos e aprove o novo hash antes de sincronizar.</div>';
        } elseif ($msg === 'ibge_pin_ok') {
            $total = (int) ($_GET['total'] ?? 0);
            echo '<div class="successbox">Novo hash da fonte primária aprovado e base sincronizada com sucesso' . ($total > 0 ? ' (' . $h((string) $total) . ' registros).' : '.') . '</div>';
        } elseif ($msg === 'ibge_pin_error') {
            echo '<div class="errorbox">Não foi possível aprovar o novo hash da fonte primária. Tente novamente em alguns instantes.</div>';
        }
        $configUrl = static function (string $tab): string {
            return 'addonmodules.php?module=OpenNfse&action=config&tab=' . rawurlencode($tab);
        };
        $gatewaySettingsRepo = new PaymentGatewaySettingsRepository();
        $gateways = (new WhmcsPaymentGatewayRepository())->listActive();
        $enabledGatewayCount = 0;
        foreach ($gateways as $g) {
            $gatewayKey = (string) ($g['gateway'] ?? '');
            if ($gatewayKey !== '' && $gatewaySettingsRepo->isEnabled($gatewayKey)) {
                $enabledGatewayCount++;
            }
        }
        $ibgeService = new IbgeService();
        $ibgeCatalogStatus = $ibgeService->getCatalogStatus($config);
        $configuredMunicipioStatus = $ibgeService->getConfiguredMunicipioStatus($config);
        $viaCepStatus = $ibgeService->getViaCepStatus($config);
        $tabStatuses = [];
        foreach (array_keys($allowedTabs) as $key) {
            $tabStatuses[$key] = $this->getConfigTabStatusMeta($key, $config);
        }
        $okSections = 0;
        $attentionSections = 0;
        $reviewSections = 0;
        $trackedTotal = 0;
        $trackedOk = 0;
        foreach ($tabStatuses as $status) {
            $tone = (string) ($status['tone'] ?? 'info');
            if ($tone === 'ok') {
                $okSections++;
            } elseif ($tone === 'attention') {
                $attentionSections++;
            } else {
                $reviewSections++;
            }
            $trackedTotal += (int) ($status['total'] ?? 0);
            $trackedOk += (int) ($status['ok'] ?? 0);
        }
        $completionPercent = $trackedTotal > 0 ? (int) round(($trackedOk / $trackedTotal) * 100) : 0;
        $currentTabStatus = $tabStatuses[$activeTab] ?? ['label' => 'Revisar', 'summary' => '', 'missing' => []];
        $environmentLabel = ((string) ($config['environment'] ?? 'homologacao')) === 'producao' ? 'Produção' : 'Homologação';
        $queueModeLabel = ((string) ($config['queue_enabled'] ?? '0')) === '1' ? 'Fila habilitada' : 'Processamento manual';
        $certConfigured = trim((string) ($config['certificate_path'] ?? '')) !== '';

        echo '<style>';
        echo '.nfse-config-layout{display:flex;gap:18px;align-items:flex-start;}';
        echo '.nfse-config-tabs{width:280px;border:1px solid #d9e1ea;background:#fafbfd;padding:12px;box-sizing:border-box;}';
        echo '.nfse-config-tabs-header{font-size:13px;font-weight:700;color:#2f3b52;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #e6ebf1;}';
        echo '.nfse-config-tabs-summary{margin-top:4px;margin-bottom:12px;padding:10px;border:1px solid #e6ebf1;background:#fff;}';
        echo '.nfse-config-tabs-summary strong{display:block;font-size:12px;color:#334155;margin-bottom:4px;}';
        echo '.nfse-config-tabs-summary span{display:block;font-size:11px;color:#6b7785;line-height:1.45;}';
        echo '.nfse-config-tabs-progress{height:6px;background:#edf2f7;border-radius:999px;overflow:hidden;margin-top:8px;}';
        echo '.nfse-config-tabs-progress > i{display:block;height:100%;background:#2d6ca2;}';
        echo '.nfse-config-tabs a[data-tab]{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-radius:6px;margin-bottom:6px;text-decoration:none;color:#2c4778;border-left:3px solid transparent;transition:all .15s ease;}';
        echo '.nfse-config-tabs a[data-tab]:hover{background:#f0f4f8;}';
        echo '.nfse-config-tabs a.is-active{background:#eef4fb;border-left-color:#2d6ca2;font-weight:700;color:#234b74;box-shadow:inset 0 0 0 1px #dbe7f3;}';
        echo '.nfse-config-tab-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;white-space:nowrap;}';
        echo '.nfse-config-content{flex:1;min-width:0;max-width:none;width:100%;}';
        echo '.nfse-config-overview{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px;}';
        echo '.nfse-config-overview-card{flex:1 1 180px;min-width:180px;border:1px solid #d9e1ea;background:#fff;padding:12px 14px;box-sizing:border-box;}';
        echo '.nfse-config-overview-card strong{display:block;font-size:11px;color:#66717f;margin-bottom:6px;text-transform:none;}';
        echo '.nfse-config-overview-value{font-size:24px;line-height:1.1;font-weight:700;color:#2f3b52;margin-bottom:4px;}';
        echo '.nfse-config-overview-note{font-size:11px;color:#6e7a88;line-height:1.4;}';
        echo '.nfse-config-save{border:1px solid #d9e1ea;background:#fafbfd;padding:10px 12px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}';
        echo '.nfse-config-save strong{font-size:13px;color:#2f3b52;}';
        echo '.nfse-config-save span{font-size:11px;color:#6b7785;}';
        echo '.nfse-config-tab{display:none;}';
        echo '.nfse-config-pane-header{border:1px solid #d9e1ea;background:#fff;padding:14px 16px;margin-bottom:12px;}';
        echo '.nfse-config-pane-header h2{margin:0 0 6px 0;font-size:20px;line-height:1.2;color:#2f3b52;}';
        echo '.nfse-config-pane-header p{margin:0;font-size:12px;line-height:1.5;color:#66717f;max-width:820px;}';
        echo '.nfse-config-pane-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;}';
        echo '.nfse-config-pane-status{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px;}';
        echo '.nfse-config-pane-status-text{font-size:11px;color:#6e7a88;line-height:1.45;}';
        echo '.nfse-config-section{border:1px solid #d9e1ea;background:#fff;margin-bottom:12px;}';
        echo '.nfse-config-section-header{padding:12px 16px;border-bottom:1px solid #edf1f5;background:#fafbfd;}';
        echo '.nfse-config-section-header strong{display:block;font-size:14px;color:#2f3b52;margin-bottom:3px;}';
        echo '.nfse-config-section-header span{display:block;font-size:11px;line-height:1.45;color:#6e7a88;}';
        echo '.nfse-config-section-body{padding:0 16px 10px 16px;}';
        echo '.nfse-config-form-table{width:100%;border-collapse:separate;border-spacing:0 10px;}';
        echo '.nfse-config-form-table .fieldlabel{width:29%;padding:0 14px 0 0;vertical-align:top;text-align:right;}';
        echo '.nfse-config-form-table .fieldarea{padding:0;vertical-align:top;}';
        echo '.nfse-config-label-title{font-size:13px;font-weight:700;color:#334155;line-height:1.35;padding-top:8px;}';
        echo '.nfse-config-help{font-size:11px;line-height:1.45;color:#6e7a88;margin-top:5px;}';
        echo '.nfse-config-input,.nfse-config-select{width:100% !important;max-width:none;}';
        echo '.nfse-config-inline-field{display:flex;gap:8px;align-items:center;}';
        echo '.nfse-config-inline-field input{flex:1;}';
        echo '.nfse-config-mono{font-family:Menlo,Monaco,Consolas,"Courier New",monospace;font-size:12px;}';
        echo '.nfse-config-note{font-size:11px;color:#6e7a88;line-height:1.45;}';
        echo '.nfse-config-box{border:1px dashed #d9e1ea;background:#fafbfd;padding:12px;}';
        echo '@media (max-width: 1200px){.nfse-config-layout{flex-direction:column;}.nfse-config-tabs{width:100%;}.nfse-config-content{max-width:none;width:100%;}}';
        echo '@media (max-width: 860px){.nfse-config-form-table .fieldlabel,.nfse-config-form-table .fieldarea{display:block;width:100%;text-align:left;}.nfse-config-form-table .fieldlabel{padding:0 0 6px 0;}.nfse-config-label-title{padding-top:0;}}';
        echo '</style>';

        echo '<div class="nfse-config-layout">';
        echo '<div class="nfse-config-tabs">';
        echo '<div class="nfse-config-tabs-header">Seções da configuração</div>';
        echo '<div class="nfse-config-tabs-summary">';
        echo '<strong>' . $h((string) $okSections) . ' seção(ões) prontas</strong>';
        echo '<span>' . $h((string) $attentionSections) . ' com atenção e ' . (string) $reviewSections . ' para revisão complementar.</span>';
        echo '<div class="nfse-config-tabs-progress"><i style="width:' . $h((string) max(0, min(100, $completionPercent))) . '%;"></i></div>';
        echo '<span style="margin-top:6px;">Checklist principal concluído em ' . $h((string) $completionPercent) . '%.</span>';
        echo '</div>';
        foreach ($allowedTabs as $key => $label) {
            $status = $tabStatuses[$key] ?? $this->getConfigTabStatusMeta($key, $config);
            $badgeStyle = 'background:' . $h((string) ($status['bg'] ?? '#eef2f7')) . ';color:' . $h((string) ($status['color'] ?? '#5f6b7a')) . ';';
            echo '<a href="' . $h($configUrl($key)) . '" data-tab="' . $h($key) . '">';
            echo '<span>' . $h($label) . '</span>';
            echo '<span class="nfse-config-tab-badge" style="' . $badgeStyle . '">' . $h((string) ($status['label'] ?? '')) . '</span>';
            echo '</a>';
        }
        echo '</div>';
        echo '<div class="nfse-config-content">';
        echo '<div class="nfse-config-overview">';
        echo '<div class="nfse-config-overview-card"><strong>Checklist principal</strong><div class="nfse-config-overview-value">' . $h((string) $completionPercent) . '%</div><div class="nfse-config-overview-note">' . $h((string) $trackedOk) . ' de ' . $h((string) $trackedTotal) . ' itens essenciais preenchidos.</div></div>';
        $certOverview = $this->evaluateCertificateFromConfig($config);
        $certOverviewNote = trim((string) ($certOverview['summary'] ?? ''));
        if ($certOverviewNote === '') {
            $certOverviewNote = $certConfigured ? 'Certificado configurado para este ambiente.' : 'Certificado ainda precisa ser configurado.';
        }
        echo '<div class="nfse-config-overview-card"><strong>Ambiente atual</strong><div class="nfse-config-overview-value">' . $h($environmentLabel) . '</div><div class="nfse-config-overview-note">' . $h($certOverviewNote) . '</div></div>';
        echo '<div class="nfse-config-overview-card"><strong>Gateways automáticos</strong><div class="nfse-config-overview-value">' . $h((string) $enabledGatewayCount) . '/' . $h((string) count($gateways)) . '</div><div class="nfse-config-overview-note">Meios de pagamento habilitados para emissão automática.</div></div>';
        echo '<div class="nfse-config-overview-card"><strong>Processamento</strong><div class="nfse-config-overview-value">' . $h($queueModeLabel) . '</div><div class="nfse-config-overview-note">Aba atual: ' . $h((string) ($tabMeta[$activeTab]['label'] ?? $activeTab)) . ' • ' . $h((string) ($currentTabStatus['summary'] ?? '')) . '</div></div>';
        echo '</div>';

        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=saveConfig">';
        $token = (new TokenService())->token();
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . $h($token) . '" />';
        }
        echo '<input type="hidden" name="active_config_tab" id="nfse-active-config-tab" value="' . $h($activeTab) . '" />';

        echo '<div class="nfse-config-tab" data-tab="ambiente">';
        $this->renderConfigPaneHeader((string) $tabMeta['ambiente']['title'], (string) $tabMeta['ambiente']['description'], $tabStatuses['ambiente'] ?? []);
        $msg = (string) ($_REQUEST['msg'] ?? '');
        if ($msg === 'cert_ok') {
            echo '<div class="successbox">Certificado validado com sucesso.</div>';
        } elseif ($msg === 'cert_critical') {
            echo '<div class="errorbox">Certificado validado, mas está em prazo crítico de renovação. Recomendado renovar imediatamente e agendar a validação presencial.</div>';
        } elseif ($msg === 'cert_expiring') {
            echo '<div class="errorbox">Certificado validado, mas está próximo de expirar. Verifique o prazo e programe a renovação.</div>';
        } elseif ($msg === 'cert_expired') {
            echo '<div class="errorbox">O certificado está expirado. Atualize o arquivo e a senha antes de usar o módulo.</div>';
        } elseif ($msg === 'cert_error') {
            echo '<div class="errorbox">Não foi possível validar o certificado. Verifique o arquivo e a senha.</div>';
        } elseif ($msg === 'conn_ok') {
            $ms = trim((string) ($_REQUEST['ms'] ?? ''));
            echo '<div class="successbox">Conexão validada com sucesso' . ($ms !== '' ? ' (' . htmlspecialchars($ms, ENT_QUOTES, 'UTF-8') . 'ms).' : '.') . '</div>';
        } elseif ($msg === 'conn_error') {
            echo '<div class="errorbox">Falha ao testar a conexão com a API. Verifique certificado, ambiente e conectividade do servidor.</div>';
        } elseif ($msg === 'conn_sdk_missing') {
            echo '<div class="errorbox">SDK não disponível no servidor. Garanta que o vendor do módulo esteja instalado e carregado.</div>';
        }
        $this->renderConfigSectionStart('Ambiente de operação', 'Escolha o ambiente utilizado pelo módulo e mantenha esta opção alinhada com o certificado e os testes realizados.');
        $this->renderConfigFormTableStart();
        $this->renderSelectRow('environment', 'Ambiente', [
            'homologacao' => 'Homologação',
            'producao' => 'Produção',
        ], $config['environment'] ?? 'homologacao');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('Certificado digital', 'Defina o arquivo A1 e a senha utilizados nas chamadas à API. Esses dados são críticos para a autenticação do emissor.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('certificate_path', 'Caminho do Certificado A1 (.pfx)', $config['certificate_path'] ?? '');
        $this->renderPasswordRow('certificate_password', 'Senha do Certificado', '');
        $this->renderConfigFormTableEnd();
        echo '<div class="nfse-config-note" style="padding:0 16px 14px 16px;">A senha não é exibida após salvar. Preencha novamente apenas quando precisar atualizar o certificado.</div>';
        $this->renderConfigSectionEnd();

        $certInfo = $this->evaluateCertificateFromConfig($config);
        $this->renderConfigSectionStart('Status do certificado', 'Confira validade, expiração e o status de leitura do certificado configurado.');
        echo '<div class="nfse-config-box" style="margin:16px;">';
        $statusTone = (string) ($certInfo['tone'] ?? 'info');
        $statusLabel = (string) ($certInfo['status_label'] ?? 'Revisar');
        $statusBg = $statusTone === 'ok' ? '#e8f5e9' : ($statusTone === 'attention' ? '#fff8e1' : '#eef2f7');
        $statusColor = $statusTone === 'ok' ? '#2e7d32' : ($statusTone === 'attention' ? '#8a6d3b' : '#5f6b7a');
        echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">';
        echo '<div style="min-width:240px;">';
        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
        echo '<span style="display:inline-flex;align-items:center;justify-content:center;padding:4px 10px;border-radius:999px;font-weight:700;font-size:12px;background:' . htmlspecialchars($statusBg, ENT_QUOTES, 'UTF-8') . ';color:' . htmlspecialchars($statusColor, ENT_QUOTES, 'UTF-8') . ';">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span>';
        $summary = trim((string) ($certInfo['summary'] ?? ''));
        if ($summary !== '') {
            echo '<span style="color:#4b5563;font-size:12px;">' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        echo '</div>';
        echo '<div style="margin-top:10px;color:#4b5563;font-size:12px;line-height:1.45;">';
        $validTo = (string) ($certInfo['valid_to'] ?? '');
        $daysLeft = (string) ($certInfo['days_left'] ?? '');
        if ($validTo !== '') {
            echo '<div><strong>Válido até:</strong> ' . htmlspecialchars($validTo, ENT_QUOTES, 'UTF-8') . ($daysLeft !== '' ? ' <span style="color:#64748b;">(' . htmlspecialchars($daysLeft, ENT_QUOTES, 'UTF-8') . ' dias)</span>' : '') . '</div>';
        }
        $subject = trim((string) ($certInfo['subject'] ?? ''));
        if ($subject !== '') {
            echo '<div><strong>Subject:</strong> ' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $issuer = trim((string) ($certInfo['issuer'] ?? ''));
        if ($issuer !== '') {
            echo '<div><strong>Issuer:</strong> ' . htmlspecialchars($issuer, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $fingerprint = trim((string) ($certInfo['fingerprint'] ?? ''));
        if ($fingerprint !== '') {
            echo '<div><strong>Fingerprint:</strong> <span class="nfse-config-mono">' . htmlspecialchars($fingerprint, ENT_QUOTES, 'UTF-8') . '</span></div>';
        }
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
        echo '<button type="submit" class="btn btn-default" formaction="addonmodules.php?module=OpenNfse&action=validateCertificate" formmethod="post">Revalidar certificado</button>';
        if (class_exists(\Nfse\Nfse::class)) {
            echo '<button type="submit" class="btn btn-default" formaction="addonmodules.php?module=OpenNfse&action=testConnection" formmethod="post">Testar conexão</button>';
        }
        echo '</div>';
        echo '</div>';
        echo '<div style="margin-top:10px;color:#64748b;font-size:12px;">Dica: use Revalidar certificado após trocar o arquivo no servidor. O teste de conexão usa uma consulta leve via SDK para validar mTLS e acesso à API, sem emitir NFS-e.</div>';
        echo '</div>';
        $this->renderConfigSectionEnd();
        echo '</div>';

        echo '<div class="nfse-config-tab" data-tab="prestador">';
        $this->renderConfigPaneHeader((string) $tabMeta['prestador']['title'], (string) $tabMeta['prestador']['description'], $tabStatuses['prestador'] ?? []);
        $this->renderConfigSectionStart('Identificação fiscal', 'Os campos abaixo definem os dados cadastrais principais do emissor enviados para a NFS-e.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('cnpj_emissor', 'CNPJ do Emissor', $config['cnpj_emissor'] ?? '');
        $this->renderSelectRow('prestador_informar_im', 'Informar IM do Prestador?', [
            '1' => 'Sim',
            '0' => 'Não',
        ], (string) ($config['prestador_informar_im'] ?? '1'));
        $this->renderTextRow('inscricao_municipal', 'Inscrição Municipal', $config['inscricao_municipal'] ?? '');
        echo '<tr>';
        echo '<td class="fieldlabel"></td>';
        echo '<td class="fieldarea" style="padding-top:0;">';
        echo '<div style="margin:0 0 12px 0;padding:10px 12px;background:#f8fafc;border:1px solid #dbeafe;border-radius:6px;color:#475569;font-size:12px;line-height:1.55;">';
        echo 'Verifique no Portal do Contribuinte qual valor aparece no campo <strong>Indicador Municipal</strong> ao simular a emissão da NFS-e. O valor informado aqui deve ser exatamente igual ao exibido no portal, incluindo os zeros à esquerda.<br />';
        echo 'Homologação: <a href="https://www.producaorestrita.nfse.gov.br/EmissorNacional/" target="_blank" rel="noopener noreferrer">https://www.producaorestrita.nfse.gov.br/EmissorNacional/</a><br />';
        echo 'Produção: <a href="https://www.nfse.gov.br/EmissorNacional/" target="_blank" rel="noopener noreferrer">https://www.nfse.gov.br/EmissorNacional/</a><br />';
        echo 'Se ainda não houver Indicador Municipal no portal, altere o campo <strong>Informar IM do Prestador</strong> para <strong>Não</strong>.';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
        $this->renderTextRow('codigo_ibge', 'Código IBGE', $config['codigo_ibge'] ?? '');
        $this->renderTextRow('prestador_nome', 'Razão Social do Prestador (xNome)', $config['prestador_nome'] ?? '');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('Contato do prestador', 'Esses dados são usados como referência de comunicação e ajudam na padronização do emissor.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('prestador_email', 'E-mail do Prestador', $config['prestador_email'] ?? '');
        $this->renderTextRow('prestador_fone', 'Telefone do Prestador', $config['prestador_fone'] ?? '');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        echo '</div>';

        echo '<div class="nfse-config-tab" data-tab="endereco">';
        $this->renderConfigPaneHeader((string) $tabMeta['endereco']['title'], (string) $tabMeta['endereco']['description'], $tabStatuses['endereco'] ?? []);
        $this->renderConfigSectionStart('Endereço cadastral', 'Mantenha esse bloco alinhado com o cadastro fiscal do emissor para evitar rejeições por inconsistência.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('prestador_logradouro', 'Logradouro do Prestador', $config['prestador_logradouro'] ?? '');
        $this->renderTextRow('prestador_numero', 'Número do Prestador', $config['prestador_numero'] ?? '');
        $this->renderTextRow('prestador_complemento', 'Complemento do Prestador', $config['prestador_complemento'] ?? '');
        $this->renderTextRow('prestador_bairro', 'Bairro do Prestador', $config['prestador_bairro'] ?? '');
        $this->renderTextRow('prestador_cep', 'CEP do Prestador', $config['prestador_cep'] ?? '');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        echo '</div>';

        echo '<div class="nfse-config-tab" data-tab="integracao">';
        $this->renderConfigPaneHeader((string) $tabMeta['integracao']['title'], (string) $tabMeta['integracao']['description'], $tabStatuses['integracao'] ?? []);
        $this->renderConfigSectionStart('Integração base', 'Configure o relacionamento entre os dados do WHMCS e os campos obrigatórios usados pelo módulo.');
        $this->renderConfigFormTableStart();
        $this->renderCustomFieldSelectRow('tomador_cpfcnpj_customfield_id', 'Custom Field (CPF/CNPJ do Tomador)', (string) ($config['tomador_cpfcnpj_customfield_id'] ?? ''));
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('Área do Cliente', 'Configure o aviso exibido na lista de NFS-e do cliente, com controle de exibição, tipo visual e mensagem.');
        $this->renderConfigFormTableStart();
        $this->renderSelectRow('client_area_notice_enabled', 'Aviso na área do cliente', [
            '1' => 'Habilitado',
            '0' => 'Desabilitado',
        ], (string) ($config['client_area_notice_enabled'] ?? '1'));
        $this->renderSelectRow('client_area_notice_type', 'Tipo do aviso', [
            'warning' => 'Atenção',
            'info' => 'Informativo',
            'success' => 'Sucesso',
        ], (string) ($config['client_area_notice_type'] ?? 'warning'));
        $this->renderTextareaRow('client_area_notice_message', 'Mensagem do aviso', (string) ($config['client_area_notice_message'] ?? $this->getDefaultClientAreaNoticeMessage()));
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('Gateways habilitados', 'Selecione quais meios de pagamento devem disparar a emissão automática de NFS-e ao marcar a fatura como paga.');
        echo '<div class="nfse-config-box" style="margin:16px;">';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">';
        echo '<div><strong style="display:block;font-size:14px;color:#334155;">Meios de pagamento</strong><span class="nfse-config-note">Atualmente ' . $h((string) $enabledGatewayCount) . ' de ' . $h((string) count($gateways)) . ' gateway(s) estão habilitados para emissão automática.</span></div>';
        if (!empty($gateways)) {
            echo '<div style="display:flex;gap:6px;flex-wrap:wrap;">';
            echo '<button type="button" class="btn btn-xs btn-default" id="nfse-mark-all-gateways">Marcar todos</button>';
            echo '<button type="button" class="btn btn-xs btn-default" id="nfse-unmark-all-gateways">Desmarcar todos</button>';
            echo '</div>';
        }
        echo '</div>';
        if (empty($gateways)) {
            echo '<span style="color:#666;">Nenhum gateway ativo encontrado.</span>';
        } else {
            echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="margin:0;font-size:12px;table-layout:fixed;width:100%;">';
            echo '<tr><th style="width:28%;">Gateway</th><th style="width:42%;">Nome</th><th style="width:30%;">Emissão de NFS-e</th></tr>';
            foreach ($gateways as $g) {
                $gateway = (string) ($g['gateway'] ?? '');
                if ($gateway === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $gateway)) {
                    continue;
                }
                $name = (string) ($g['name'] ?? $gateway);
                $checked = $gatewaySettingsRepo->isEnabled($gateway) ? ' checked' : '';
                echo '<tr>';
                echo '<td style="word-break:break-word;overflow-wrap:anywhere;"><span class="nfse-config-mono">' . htmlspecialchars($gateway, ENT_QUOTES, 'UTF-8') . '</span></td>';
                echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td><label style="margin:0;display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" class="nfse-gateway-check" name="nfse_gateway_enabled[]" value="' . htmlspecialchars($gateway, ENT_QUOTES, 'UTF-8') . '"' . $checked . ' /> <span style="font-weight:600;">Ativado</span></label></td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '<div style="margin-top:6px;color:#666;">Se desativado, o módulo não enfileira/gera NFS-e automaticamente quando a fatura é paga com esse gateway.</div>';
        }
        echo '</div>';
        $this->renderConfigSectionEnd();
        echo '</div>';

        echo '<div class="nfse-config-tab" data-tab="tomador">';
        $this->renderConfigPaneHeader((string) $tabMeta['tomador']['title'], (string) $tabMeta['tomador']['description'], $tabStatuses['tomador'] ?? []);
        $this->renderConfigSectionStart('Fallbacks para o tomador', 'Esses campos só devem ser usados quando o cadastro do cliente não trouxer a informação necessária no momento da emissão.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('tomador_codigo_ibge_padrao', 'Código IBGE do Tomador (padrão)', $config['tomador_codigo_ibge_padrao'] ?? ($config['codigo_ibge'] ?? ''));
        $this->renderTextRow('tomador_numero_padrao', 'Número do Tomador (padrão)', $config['tomador_numero_padrao'] ?? 'S/N');
        $this->renderTextRow('tomador_bairro_padrao', 'Bairro do Tomador (padrão)', $config['tomador_bairro_padrao'] ?? '');
        $this->renderConfigFormTableEnd();
        if (is_array($ibgeCatalogStatus) && is_array($configuredMunicipioStatus) && is_array($viaCepStatus)) {
            $catalogTone = match ((string) ($ibgeCatalogStatus['status'] ?? '')) {
                'consistent' => ['#ecfdf3', '#16a34a', '#166534'],
                'unpinned' => ['#eff6ff', '#3b82f6', '#1d4ed8'],
                'approval_required' => ['#fef2f2', '#ef4444', '#991b1b'],
                'divergent', 'local_only' => ['#fff7ed', '#f59e0b', '#9a3412'],
                default => ['#fef2f2', '#ef4444', '#991b1b'],
            };
            $municipioTone = match ((string) ($configuredMunicipioStatus['status'] ?? '')) {
                'ok' => ['#ecfdf3', '#16a34a', '#166534'],
                'missing' => ['#fff7ed', '#f59e0b', '#9a3412'],
                default => ['#fef2f2', '#ef4444', '#991b1b'],
            };
            $viaCepTone = match ((string) ($viaCepStatus['status'] ?? '')) {
                'ok' => ['#ecfdf3', '#16a34a', '#166534'],
                'warning', 'missing' => ['#fff7ed', '#f59e0b', '#9a3412'],
                default => ['#fef2f2', '#ef4444', '#991b1b'],
            };
            $catalogRemoteCount = $ibgeCatalogStatus['remote_count'] !== null ? (string) $ibgeCatalogStatus['remote_count'] : '—';
            $catalogLocalCount = (string) ($ibgeCatalogStatus['local_count'] ?? 0);
            $catalogHash = trim((string) ($ibgeCatalogStatus['remote_hash'] ?? ''));
            $catalogPinnedHash = trim((string) ($ibgeCatalogStatus['pinned_hash'] ?? ''));
            $catalogPendingHash = trim((string) ($ibgeCatalogStatus['pending_hash'] ?? ''));
            $lastSyncAt = trim((string) ($ibgeCatalogStatus['last_synced_at'] ?? ''));
            $lastSyncAt = $lastSyncAt !== '' ? date('d/m/Y H:i', strtotime($lastSyncAt) ?: time()) : 'Nunca';
            $pinnedAt = trim((string) ($ibgeCatalogStatus['pinned_at'] ?? ''));
            $pinnedAt = $pinnedAt !== '' ? date('d/m/Y H:i', strtotime($pinnedAt) ?: time()) : 'Nunca';
            $pendingCheckedAt = trim((string) ($ibgeCatalogStatus['pending_checked_at'] ?? ''));
            $pendingCheckedAt = $pendingCheckedAt !== '' ? date('d/m/Y H:i', strtotime($pendingCheckedAt) ?: time()) : 'Nunca';
            echo '<div style="margin-top:10px;display:flex;gap:12px;flex-wrap:wrap;">';
            echo '<div style="flex:1 1 280px;min-width:280px;padding:12px;border:1px solid #d9e1ea;background:#fff;">';
            echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">';
            echo '<strong style="font-size:13px;color:#334155;">Fonte primária de municípios</strong>';
            echo '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:' . $h($catalogTone[0]) . ';color:' . $h($catalogTone[2]) . ';font-size:11px;font-weight:700;border:1px solid ' . $h($catalogTone[1]) . ';">' . $h((string) ($ibgeCatalogStatus['label'] ?? '')) . '</span>';
            echo '</div>';
            echo '<div style="font-size:12px;color:#475569;line-height:1.55;margin-bottom:8px;">' . $h((string) ($ibgeCatalogStatus['message'] ?? '')) . '</div>';
            echo '<div style="font-size:11px;color:#64748b;line-height:1.65;">';
            echo 'URL: <span class="nfse-config-mono" style="font-size:11px;">' . $h((string) ($ibgeCatalogStatus['source_url'] ?? '')) . '</span><br />';
            echo 'Municípios na URL: <strong>' . $h($catalogRemoteCount) . '</strong><br />';
            echo 'Municípios no banco: <strong>' . $h($catalogLocalCount) . '</strong><br />';
            echo 'Última sincronização: <strong>' . $h($lastSyncAt) . '</strong><br />';
            echo 'Hash aprovado: <strong>' . ($catalogPinnedHash !== '' ? '<span class="nfse-config-mono" style="font-size:11px;">' . $h(substr($catalogPinnedHash, 0, 16)) . '...</span>' : '—') . '</strong><br />';
            echo 'Aprovado em: <strong>' . $h($pinnedAt) . '</strong>';
            if ($catalogHash !== '') {
                echo '<br />Hash remoto atual: <span class="nfse-config-mono" style="font-size:11px;">' . $h(substr($catalogHash, 0, 16)) . '...</span>';
            }
            if ($catalogPendingHash !== '') {
                echo '<br />Hash pendente: <span class="nfse-config-mono" style="font-size:11px;">' . $h(substr($catalogPendingHash, 0, 16)) . '...</span>';
                echo '<br />Pendente desde: <strong>' . $h($pendingCheckedAt) . '</strong>';
            }
            if ((string) ($ibgeCatalogStatus['error'] ?? '') !== '') {
                echo '<br />Erro da fonte: <span style="color:#b91c1c;">' . $h((string) $ibgeCatalogStatus['error']) . '</span>';
            }
            echo '</div>';
            if (!empty($ibgeCatalogStatus['approval_required'])) {
                echo '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">';
                echo '<button type="submit" class="btn btn-warning" formaction="addonmodules.php?module=OpenNfse&action=approveIbgeHash" formmethod="post">Aprovar novo hash e sincronizar</button>';
                echo '</div>';
            }
            echo '</div>';

            echo '<div style="flex:1 1 240px;min-width:240px;padding:12px;border:1px solid #d9e1ea;background:#fff;">';
            echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">';
            echo '<strong style="font-size:13px;color:#334155;">Validação do Código IBGE</strong>';
            echo '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:' . $h($municipioTone[0]) . ';color:' . $h($municipioTone[2]) . ';font-size:11px;font-weight:700;border:1px solid ' . $h($municipioTone[1]) . ';">' . $h((string) ($configuredMunicipioStatus['label'] ?? '')) . '</span>';
            echo '</div>';
            echo '<div style="font-size:12px;color:#475569;line-height:1.55;margin-bottom:8px;">' . $h((string) ($configuredMunicipioStatus['message'] ?? '')) . '</div>';
            echo '<div style="font-size:11px;color:#64748b;line-height:1.65;">';
            echo 'Código configurado: <strong>' . $h((string) ($configuredMunicipioStatus['ibge_code'] ?? '—')) . '</strong><br />';
            echo 'Município encontrado: <strong>' . $h((string) ($configuredMunicipioStatus['municipio'] ?? '—')) . '</strong>';
            echo '</div>';
            echo '</div>';

            echo '<div style="flex:1 1 240px;min-width:240px;padding:12px;border:1px solid #d9e1ea;background:#fff;">';
            echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">';
            echo '<strong style="font-size:13px;color:#334155;">Status do ViaCEP</strong>';
            echo '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:' . $h($viaCepTone[0]) . ';color:' . $h($viaCepTone[2]) . ';font-size:11px;font-weight:700;border:1px solid ' . $h($viaCepTone[1]) . ';">' . $h((string) ($viaCepStatus['label'] ?? '')) . '</span>';
            echo '</div>';
            echo '<div style="font-size:12px;color:#475569;line-height:1.55;margin-bottom:8px;">' . $h((string) ($viaCepStatus['message'] ?? '')) . '</div>';
            echo '<div style="font-size:11px;color:#64748b;line-height:1.65;">';
            echo 'CEP do prestador: <strong>' . $h((string) ($viaCepStatus['cep'] ?? '—')) . '</strong><br />';
            echo 'IBGE retornado: <strong>' . $h((string) (($viaCepStatus['ibge'] ?? null) ?: '—')) . '</strong><br />';
            echo 'Localidade: <strong>' . $h(trim((string) ($viaCepStatus['localidade'] ?? '')) !== '' ? ((string) $viaCepStatus['localidade'] . ' - ' . (string) ($viaCepStatus['uf'] ?? '')) : '—') . '</strong><br />';
            echo 'Comparação com o prestador: <strong>' . $h(!empty($viaCepStatus['matches_expected']) ? 'Compatível' : 'Divergente/indisponível') . '</strong>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        echo '<div style="margin-top:10px;padding:12px;border:1px solid #d9e1ea;background:#fafbfd;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">';
        echo '<div style="font-size:12px;color:#475569;line-height:1.5;">Sincronize a base completa de municípios IBGE para acelerar resoluções de código e evitar dependência de consultas sob demanda durante a geração do PDF. Quando a fonte primária mudar, o módulo exige aprovação explícita do novo hash antes de atualizar o banco.</div>';
        echo '<button type="submit" class="btn btn-primary" formaction="addonmodules.php?module=OpenNfse&action=syncIbgeMunicipios" formmethod="post">Sincronizar municípios IBGE agora</button>';
        echo '</div>';
        $this->renderConfigSectionEnd();
        echo '</div>';

        echo '<div class="nfse-config-tab" data-tab="processamento">';
        $this->renderConfigPaneHeader((string) $tabMeta['processamento']['title'], (string) $tabMeta['processamento']['description'], $tabStatuses['processamento'] ?? []);
        $this->renderConfigSectionStart('Automação da fila', 'Defina quando o módulo deve enfileirar e processar emissões automaticamente após o pagamento.');
        $this->renderConfigFormTableStart();
        $this->renderSelectRow('queue_enabled', 'Habilitar fila/cron?', [
            '1' => 'Sim',
            '0' => 'Não',
        ], (string) ($config['queue_enabled'] ?? '0'));
        $this->renderSelectRow('auto_emit_on_payment', 'Enfileirar emissão ao pagar a fatura?', [
            '1' => 'Sim',
            '0' => 'Não',
        ], (string) ($config['auto_emit_on_payment'] ?? '0'));
        $this->renderTextRow('queue_wait_status_interval_seconds', 'Intervalo de consulta (segundos)', $config['queue_wait_status_interval_seconds'] ?? '120');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        echo '</div>';

        echo '<div class="nfse-config-tab" data-tab="retencao">';
        $this->renderConfigPaneHeader((string) $tabMeta['retencao']['title'], (string) $tabMeta['retencao']['description'], $tabStatuses['retencao'] ?? []);
        $this->renderConfigSectionStart('Política de retenção', 'Use prazos curtos para reduzir volume desnecessário, sem perder o histórico necessário para auditoria e suporte.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('queue_done_retention_days', 'Reter fila DONE (dias, 0=desativar)', $config['queue_done_retention_days'] ?? '30');
        $this->renderTextRow('logs_retention_days', 'Reter logs (dias, 0=desativar)', $config['logs_retention_days'] ?? '90');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        echo '</div>';

        echo '<div class="nfse-config-tab" data-tab="tributacao">';
        $this->renderConfigPaneHeader((string) $tabMeta['tributacao']['title'], (string) $tabMeta['tributacao']['description'], $tabStatuses['tributacao'] ?? []);
        $this->renderConfigSectionStart('Parâmetros principais', 'Esses valores são usados como base na emissão quando não houver regra específica por grupo de produto ou serviço.');
        $this->renderConfigFormTableStart();
        $this->renderTributacaoServiceNbsFields($config);
        $this->renderTextRow('aliquota_iss', 'Alíquota ISS (%)', $config['aliquota_iss'] ?? '');
        $this->renderTextRow('serie_dps', 'Série DPS', $config['serie_dps'] ?? '900');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('Regimes e enquadramento', 'Use essas opções para refletir o enquadramento tributário do prestador no padrão nacional.');
        $this->renderConfigFormTableStart();
        $this->renderSelectRow('prestador_op_simp_nac', 'Opção Simples Nacional', [
            '1' => '1 - Não optante',
            '2' => '2 - MEI',
            '3' => '3 - ME/EPP',
        ], (string) ($config['prestador_op_simp_nac'] ?? ''));
        $this->renderSelectRow('prestador_reg_ap_trib_sn', 'Regime Apuração SN (opSimpNac=3)', [
            '' => '—',
            '1' => '1 - Apura pelo SN',
            '2' => '2 - Apura fora do SN',
        ], (string) ($config['prestador_reg_ap_trib_sn'] ?? ''));
        $this->renderTextRow('prestador_reg_esp_trib', 'Regime Especial (0=Nenhum, 1..)', $config['prestador_reg_esp_trib'] ?? '0');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        echo '</div>';

        echo '<div class="nfse-config-save" id="nfse-config-save">';
        echo '<div><strong>Finalizar alterações</strong><br /><span>Revise os campos da seção atual antes de salvar. As mudanças são aplicadas imediatamente após o envio.</span></div>';
        echo '<div><input type="submit" value="Salvar alterações" class="btn btn-primary" /></div>';
        echo '</div>';
        echo '</form>';

        echo '<div class="nfse-config-tab" data-tab="servicosnbs">';
        $this->renderConfigPaneHeader((string) $tabMeta['servicosnbs']['title'], (string) $tabMeta['servicosnbs']['description'], $tabStatuses['servicosnbs'] ?? []);
        $this->renderServiceNbsCatalogContent();
        echo '</div>';

        echo '<div class="nfse-config-tab" data-tab="sequenciais">';
        $this->renderConfigPaneHeader((string) $tabMeta['sequenciais']['title'], (string) $tabMeta['sequenciais']['description'], $tabStatuses['sequenciais'] ?? []);
        $this->sequenciaisController->renderSequenciaisContent();
        echo '</div>';

        echo '<div class="nfse-config-tab" data-tab="codigos">';
        $this->renderConfigPaneHeader((string) $tabMeta['codigos']['title'], (string) $tabMeta['codigos']['description'], $tabStatuses['codigos'] ?? []);
        $this->renderCodigosProdutosServicosContent();
        echo '</div>';

        echo '<div class="nfse-config-tab" data-tab="danfse">';
        $this->renderConfigPaneHeader((string) $tabMeta['danfse']['title'], (string) $tabMeta['danfse']['description'], $tabStatuses['danfse'] ?? []);
        $this->renderDanfseConfigContent($config);
        echo '</div>';

        echo '<script>';
        echo '(function(){';
        echo 'var active=' . json_encode($activeTab, JSON_UNESCAPED_UNICODE) . ';';
        echo 'var links=document.querySelectorAll(".nfse-config-tabs a[data-tab]");';
        echo 'var panes=document.querySelectorAll(".nfse-config-tab");';
        echo 'var saves=document.querySelectorAll(".nfse-config-save");';
        echo 'var activeInput=document.getElementById("nfse-active-config-tab");';
        echo 'function show(tab,updateUrl){';
        echo 'for(var i=0;i<panes.length;i++){var p=panes[i]; p.style.display=(p.getAttribute("data-tab")===tab)?"block":"none";}';
        echo 'for(var j=0;j<links.length;j++){var a=links[j]; var on=(a.getAttribute("data-tab")===tab); if(on){a.classList.add("is-active");}else{a.classList.remove("is-active");}}';
        echo 'for(var s=0;s<saves.length;s++){saves[s].style.display=(tab==="codigos"||tab==="sequenciais"||tab==="servicosnbs"||tab==="danfse")?"none":"flex";}';
        echo 'if(activeInput){activeInput.value=tab;}';
        echo 'if(updateUrl&&window.history&&window.history.replaceState){window.history.replaceState(null,"",' . json_encode('addonmodules.php?module=OpenNfse&action=config&tab=', JSON_UNESCAPED_UNICODE) . '+encodeURIComponent(tab));}';
        echo '}';
        echo 'for(var k=0;k<links.length;k++){(function(a){a.addEventListener("click",function(e){e.preventDefault(); show(a.getAttribute("data-tab"),true);});})(links[k]);}';
        echo 'var toggleButtons=document.querySelectorAll("[data-nfse-toggle-password]");';
        echo 'for(var t=0;t<toggleButtons.length;t++){(function(btn){btn.addEventListener("click",function(){var id=btn.getAttribute("data-nfse-toggle-password");var input=id?document.getElementById(id):null;if(!input){return;}var isPassword=input.getAttribute("type")==="password";input.setAttribute("type",isPassword?"text":"password");btn.textContent=isPassword?"Ocultar":"Mostrar";});})(toggleButtons[t]);}';
        echo 'var markAll=document.getElementById("nfse-mark-all-gateways");';
        echo 'var unmarkAll=document.getElementById("nfse-unmark-all-gateways");';
        echo 'function setGatewayChecks(value){var checks=document.querySelectorAll(".nfse-gateway-check");for(var i=0;i<checks.length;i++){checks[i].checked=value;}}';
        echo 'if(markAll){markAll.addEventListener("click",function(){setGatewayChecks(true);});}';
        echo 'if(unmarkAll){unmarkAll.addEventListener("click",function(){setGatewayChecks(false);});}';
        echo 'show(active,false);';
        echo '})();';
        echo '</script>';
        echo '</div>';
        echo '</div>';

        Module::ui()->renderFooter();
    }


    public function saveConfig(): void
    {
        $existingConfig = (new ConfigRepository())->get();
        $environment = (string) ($_POST['environment'] ?? '');
        $activeConfigTab = trim((string) ($_POST['active_config_tab'] ?? 'ambiente'));
        $certificatePath = (string) ($_POST['certificate_path'] ?? '');
        $certificatePassword = (string) ($_POST['certificate_password'] ?? '');
        $cnpjEmissor = (string) ($_POST['cnpj_emissor'] ?? '');
        $prestadorInformarIm = (string) ($_POST['prestador_informar_im'] ?? '1');
        $inscricaoMunicipal = (string) ($_POST['inscricao_municipal'] ?? '');
        $codigoIbge = (string) ($_POST['codigo_ibge'] ?? '');
        $codigoServico = (string) ($_POST['codigo_servico'] ?? '');
        $nbsPadrao = (string) ($_POST['nbs_padrao'] ?? '');
        $aliquotaIss = (string) ($_POST['aliquota_iss'] ?? '');
        $tomadorFieldId = (string) ($_POST['tomador_cpfcnpj_customfield_id'] ?? '');
        $serieDps = (string) ($_POST['serie_dps'] ?? '900');
        $prestadorNome = (string) ($_POST['prestador_nome'] ?? '');
        $prestadorEmail = (string) ($_POST['prestador_email'] ?? '');
        $prestadorFone = (string) ($_POST['prestador_fone'] ?? '');
        $prestadorLogradouro = (string) ($_POST['prestador_logradouro'] ?? '');
        $prestadorNumero = (string) ($_POST['prestador_numero'] ?? '');
        $prestadorComplemento = (string) ($_POST['prestador_complemento'] ?? '');
        $prestadorBairro = (string) ($_POST['prestador_bairro'] ?? '');
        $prestadorCep = (string) ($_POST['prestador_cep'] ?? '');
        $prestadorOpSimpNac = (string) ($_POST['prestador_op_simp_nac'] ?? '');
        $prestadorRegApTribSn = (string) ($_POST['prestador_reg_ap_trib_sn'] ?? '');
        $prestadorRegEspTrib = (string) ($_POST['prestador_reg_esp_trib'] ?? '');
        $queueEnabled = (string) ($_POST['queue_enabled'] ?? '0');
        $autoEmitOnPayment = (string) ($_POST['auto_emit_on_payment'] ?? '0');
        $queueWaitInterval = (string) ($_POST['queue_wait_status_interval_seconds'] ?? '120');
        $queueDoneRetentionDays = (string) ($_POST['queue_done_retention_days'] ?? '30');
        $logsRetentionDays = (string) ($_POST['logs_retention_days'] ?? '90');
        $tomadorCodigoIbgePadrao = (string) ($_POST['tomador_codigo_ibge_padrao'] ?? '');
        $tomadorNumeroPadrao = (string) ($_POST['tomador_numero_padrao'] ?? 'S/N');
        $tomadorBairroPadrao = (string) ($_POST['tomador_bairro_padrao'] ?? '');
        $clientAreaNoticeEnabled = (string) ($_POST['client_area_notice_enabled'] ?? '1');
        $clientAreaNoticeType = trim((string) ($_POST['client_area_notice_type'] ?? 'warning'));
        $clientAreaNoticeMessage = trim((string) ($_POST['client_area_notice_message'] ?? ''));
        $nfseGatewayEnabled = $_POST['nfse_gateway_enabled'] ?? [];
        if (!is_array($nfseGatewayEnabled)) {
            $nfseGatewayEnabled = [];
        }

        $errors = [];
        if ($environment !== 'homologacao' && $environment !== 'producao') {
            $errors[] = 'Ambiente inválido.';
        }
        if ($certificatePath === '') {
            $errors[] = 'Informe o caminho do certificado.';
        } else {
            $certificatePathTrim = trim($certificatePath);
            $ext = strtolower((string) pathinfo($certificatePathTrim, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pfx', 'p12'], true)) {
                $errors[] = 'O certificado deve ser um arquivo .pfx ou .p12.';
            }
            if (!file_exists($certificatePathTrim) || !is_file($certificatePathTrim)) {
                $errors[] = 'O arquivo do certificado não foi encontrado no caminho informado.';
            } elseif (!is_readable($certificatePathTrim)) {
                $errors[] = 'O arquivo do certificado não está acessível para leitura pelo PHP.';
            }
        }
        if (!in_array($prestadorInformarIm, ['0', '1'], true)) {
            $errors[] = 'Opção inválida para informar IM.';
        }
        $codigoServicoDigits = preg_replace('/\D/', '', $codigoServico);
        if ($codigoServicoDigits !== '' && strlen($codigoServicoDigits) !== 6) {
            $errors[] = 'Código de serviço inválido (precisa ter 6 dígitos).';
        }
        $nbsDigits = preg_replace('/\D/', '', $nbsPadrao);
        if ($nbsDigits !== '' && strlen($nbsDigits) !== 9) {
            $errors[] = 'NBS inválida (precisa ter 9 dígitos).';
        }
        if ($codigoServicoDigits !== '' && $nbsDigits !== '') {
            $catalogRepo = new ServiceNbsCatalogRepository();
            $isCurrentPersistedPair = trim((string) ($existingConfig['codigo_servico'] ?? '')) === $codigoServicoDigits
                && trim((string) ($existingConfig['nbs_padrao'] ?? '')) === $nbsDigits;
            if (!$catalogRepo->existsByServiceAndNbs($codigoServicoDigits, $nbsDigits) && !$isCurrentPersistedPair) {
                $errors[] = 'Selecione uma combinação válida de código de serviço e NBS cadastrada no catálogo.';
            }
        }
        if ($aliquotaIss !== '' && !is_numeric(str_replace(',', '.', $aliquotaIss))) {
            $errors[] = 'Informe a alíquota ISS numérica.';
        }
        if ($tomadorFieldId !== '' && !ctype_digit($tomadorFieldId)) {
            $errors[] = 'Informe o ID do custom field (apenas números).';
        }
        if (!in_array($clientAreaNoticeEnabled, ['0', '1'], true)) {
            $errors[] = 'Opção inválida para aviso na área do cliente.';
        }
        if (!in_array($clientAreaNoticeType, ['info', 'warning', 'success'], true)) {
            $errors[] = 'Tipo de aviso inválido para a área do cliente.';
        }
        if ($clientAreaNoticeEnabled === '1' && $clientAreaNoticeMessage === '') {
            $errors[] = 'Informe a mensagem do aviso da área do cliente ou desabilite o aviso.';
        }
        if ($serieDps === '' || strlen($serieDps) > 5) {
            $errors[] = 'Série DPS inválida (até 5 caracteres).';
        }
        $prestadorCepDigits = preg_replace('/\D/', '', $prestadorCep);
        if ($prestadorCepDigits !== '' && strlen($prestadorCepDigits) !== 8) {
            $errors[] = 'CEP do prestador inválido (8 dígitos).';
        }
        if (!in_array($prestadorOpSimpNac, ['1', '2', '3'], true)) {
            $errors[] = 'Informe a opção do Simples Nacional do prestador (1, 2 ou 3).';
        }
        if ($prestadorOpSimpNac === '3' && !in_array($prestadorRegApTribSn, ['1', '2'], true)) {
            $errors[] = 'Para ME/EPP (opSimpNac=3), informe regApTribSN (1 ou 2).';
        }
        if ($prestadorOpSimpNac !== '3') {
            $prestadorRegApTribSn = '';
        }
        if ($prestadorRegEspTrib === '') {
            $prestadorRegEspTrib = '0';
        }
        if (!ctype_digit($prestadorRegEspTrib)) {
            $errors[] = 'Regime especial inválido.';
        }
        if (!in_array($queueEnabled, ['0', '1'], true)) {
            $errors[] = 'Opção inválida para fila/cron.';
        }
        if (!in_array($autoEmitOnPayment, ['0', '1'], true)) {
            $errors[] = 'Opção inválida para emissão automática.';
        }
        if ($autoEmitOnPayment === '1' && $queueEnabled !== '1') {
            $errors[] = 'Para habilitar emissão automática, habilite também a fila/cron.';
        }
        if ($queueWaitInterval === '' || !ctype_digit($queueWaitInterval)) {
            $errors[] = 'Informe o intervalo de consulta (segundos) apenas com números.';
        } else {
            $intervalInt = (int) $queueWaitInterval;
            if ($intervalInt < 30 || $intervalInt > 3600) {
                $errors[] = 'Intervalo de consulta inválido (30 a 3600 segundos).';
            }
        }
        if ($queueDoneRetentionDays === '' || !ctype_digit($queueDoneRetentionDays)) {
            $errors[] = 'Informe a retenção de fila DONE (dias) apenas com números.';
        } else {
            $days = (int) $queueDoneRetentionDays;
            if ($days > 3650) {
                $errors[] = 'Retenção de fila DONE inválida (0 a 3650 dias).';
            }
        }
        if ($logsRetentionDays === '' || !ctype_digit($logsRetentionDays)) {
            $errors[] = 'Informe a retenção de logs (dias) apenas com números.';
        } else {
            $days = (int) $logsRetentionDays;
            if ($days > 3650) {
                $errors[] = 'Retenção de logs inválida (0 a 3650 dias).';
            }
        }
        if (trim($tomadorNumeroPadrao) === '') {
            $errors[] = 'Informe o número padrão do tomador (ex.: S/N).';
        }
        if ($tomadorCodigoIbgePadrao === '') {
            $tomadorCodigoIbgePadrao = (string) $codigoIbge;
        }
        if ($tomadorCodigoIbgePadrao !== '' && (!ctype_digit($tomadorCodigoIbgePadrao) || strlen($tomadorCodigoIbgePadrao) !== 7)) {
            $errors[] = 'Código IBGE do tomador inválido (precisa ter 7 dígitos).';
        }

        if (!empty($errors)) {
            throw new NfseModuleException(implode(' ', $errors));
        }

        $repo = new ConfigRepository();
        $crypto = new CryptoService();

        $passwordEnc = $existingConfig['certificate_password_enc'] ?? null;
        if ($certificatePassword !== '') {
            $passwordEnc = $crypto->encrypt($certificatePassword);
        }

        if (!$passwordEnc) {
            throw new NfseModuleException('Informe a senha do certificado ao menos uma vez para salvar.');
        }

        try {
            $passwordPlain = $crypto->decrypt((string) $passwordEnc);
        } catch (\Throwable $e) {
            throw new NfseModuleException('Não foi possível ler a senha armazenada do certificado. Informe novamente a senha.');
        }
        $certCheck = $this->evaluateCertificateFromInputs($certificatePath, $passwordPlain);
        if (!(bool) ($certCheck['valid'] ?? false)) {
            throw new NfseModuleException((string) ($certCheck['error'] ?? 'Não foi possível validar o certificado.'));
        }

        $repo->save([
            'environment' => $environment,
            'certificate_path' => $certificatePath,
            'certificate_password_enc' => $passwordEnc,
            'cnpj_emissor' => $cnpjEmissor,
            'inscricao_municipal' => $inscricaoMunicipal,
            'prestador_informar_im' => (int) $prestadorInformarIm,
            'codigo_ibge' => $codigoIbge,
            'codigo_servico' => $codigoServicoDigits,
            'nbs_padrao' => $nbsDigits !== '' ? $nbsDigits : null,
            'aliquota_iss' => $aliquotaIss !== '' ? str_replace(',', '.', $aliquotaIss) : '0',
            'tomador_cpfcnpj_customfield_id' => (int) $tomadorFieldId,
            'client_area_notice_enabled' => (int) $clientAreaNoticeEnabled,
            'client_area_notice_type' => $clientAreaNoticeType,
            'client_area_notice_message' => $clientAreaNoticeMessage,
            'serie_dps' => $serieDps,
            'prestador_nome' => $prestadorNome,
            'prestador_email' => $prestadorEmail,
            'prestador_fone' => $prestadorFone,
            'prestador_logradouro' => $prestadorLogradouro,
            'prestador_numero' => $prestadorNumero,
            'prestador_complemento' => $prestadorComplemento,
            'prestador_bairro' => $prestadorBairro,
            'prestador_cep' => $prestadorCepDigits,
            'prestador_op_simp_nac' => $prestadorOpSimpNac,
            'prestador_reg_ap_trib_sn' => $prestadorRegApTribSn,
            'prestador_reg_esp_trib' => $prestadorRegEspTrib,
            'queue_enabled' => (int) $queueEnabled,
            'auto_emit_on_payment' => (int) $autoEmitOnPayment,
            'queue_wait_status_interval_seconds' => (int) $queueWaitInterval,
            'queue_done_retention_days' => (int) $queueDoneRetentionDays,
            'logs_retention_days' => (int) $logsRetentionDays,
            'tomador_codigo_ibge_padrao' => $tomadorCodigoIbgePadrao,
            'tomador_numero_padrao' => $tomadorNumeroPadrao,
            'tomador_bairro_padrao' => $tomadorBairroPadrao,
        ]);

        $activeGateways = (new WhmcsPaymentGatewayRepository())->listActive();
        $gatewayKeys = [];
        foreach ($activeGateways as $g) {
            $gatewayKeys[] = (string) ($g['gateway'] ?? '');
        }
        if (!empty($gatewayKeys)) {
            (new PaymentGatewaySettingsRepository())->saveStatusForGateways($gatewayKeys, $nfseGatewayEnabled);
        }

        $allowedTabs = ['ambiente', 'prestador', 'endereco', 'tributacao', 'servicosnbs', 'sequenciais', 'integracao', 'codigos', 'tomador', 'processamento', 'retencao'];
        if (!in_array($activeConfigTab, $allowedTabs, true)) {
            $activeConfigTab = 'ambiente';
        }

        header('Location: addonmodules.php?module=OpenNfse&action=config&saved=1&tab=' . rawurlencode($activeConfigTab));
        exit;
    }


    public function validateCertificate(): void
    {
        $config = (new ConfigRepository())->get();
        $certificatePath = trim((string) ($_POST['certificate_path'] ?? (string) ($config['certificate_path'] ?? '')));
        $certificatePassword = (string) ($_POST['certificate_password'] ?? '');
        if ($certificatePassword === '') {
            try {
                $passwordEnc = (string) ($config['certificate_password_enc'] ?? '');
                $certificatePassword = (new CryptoService())->decrypt($passwordEnc);
            } catch (\Throwable $e) {
                $certificatePassword = '';
            }
        }

        $cert = $this->evaluateCertificateFromInputs($certificatePath, $certificatePassword);
        $msg = match ((string) ($cert['status'] ?? 'error')) {
            'ok' => 'cert_ok',
            'critical' => 'cert_critical',
            'expiring' => 'cert_expiring',
            'expired' => 'cert_expired',
            default => 'cert_error',
        };

        header('Location: addonmodules.php?module=OpenNfse&action=config&tab=ambiente&msg=' . $msg);
        exit;
    }


    public function testConnection(): void
    {
        if (!class_exists(\Nfse\Nfse::class)) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=ambiente&msg=conn_sdk_missing');
            exit;
        }

        $config = (new ConfigRepository())->get();
        $environment = (string) ($_POST['environment'] ?? (string) ($config['environment'] ?? 'homologacao'));
        $certificatePath = trim((string) ($_POST['certificate_path'] ?? (string) ($config['certificate_path'] ?? '')));
        $certificatePassword = (string) ($_POST['certificate_password'] ?? '');
        if ($certificatePassword === '') {
            try {
                $passwordEnc = (string) ($config['certificate_password_enc'] ?? '');
                $certificatePassword = (new CryptoService())->decrypt($passwordEnc);
            } catch (\Throwable $e) {
                $certificatePassword = '';
            }
        }

        $cert = $this->evaluateCertificateFromInputs($certificatePath, $certificatePassword);
        if (!(bool) ($cert['valid'] ?? false)) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=ambiente&msg=conn_error');
            exit;
        }

        $ambienteEnum = $environment === 'producao'
            ? \Nfse\Enums\TipoAmbiente::Producao
            : \Nfse\Enums\TipoAmbiente::Homologacao;
        $sdkConfig = [
            'ambiente' => $ambienteEnum,
            'certificatePath' => $certificatePath,
            'certificatePassword' => $certificatePassword,
            'codigoMunicipio' => (string) ($config['codigo_ibge'] ?? null),
        ];

        $start = microtime(true);
        $adapter = new \OpenNfse\Api\NfsePhpSdkAdapter();
        $result = $adapter->consultarDps($sdkConfig, '0');
        $ms = (int) round((microtime(true) - $start) * 1000);

        $error = strtolower((string) ($result->errorMessage ?? ''));
        $transportError = $error !== '' && preg_match('/curl error|ssl|certificate|failed to connect|could not resolve|timeout|connection/i', $error) === 1;
        $msg = $transportError ? 'conn_error' : 'conn_ok';

        header('Location: addonmodules.php?module=OpenNfse&action=config&tab=ambiente&msg=' . $msg . '&ms=' . $ms);
        exit;
    }

    public function syncIbgeMunicipios(): void
    {
        try {
            $result = (new IbgeService())->syncMunicipiosCatalog();
            $total = (int) ($result['synced'] ?? 0);
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=tomador&msg=ibge_sync_ok&total=' . $total);
            exit;
        } catch (\Throwable $e) {
            if ($e->getMessage() === (new IbgeService())->getHashApprovalRequiredMessage()) {
                header('Location: addonmodules.php?module=OpenNfse&action=config&tab=tomador&msg=ibge_sync_pending_approval');
                exit;
            }
            $configRepo = new ConfigRepository();
            if ($configRepo->get() !== []) {
                $configRepo->save([
                    'ibge_sync_last_checked_at' => date('Y-m-d H:i:s'),
                    'ibge_sync_last_status' => 'error',
                    'ibge_sync_last_error' => $e->getMessage(),
                ]);
            }
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=tomador&msg=ibge_sync_error');
            exit;
        }
    }

    public function approveIbgeHash(): void
    {
        try {
            $result = (new IbgeService())->approveAndSyncMunicipiosCatalog();
            $total = (int) ($result['synced'] ?? 0);
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=tomador&msg=ibge_pin_ok&total=' . $total);
            exit;
        } catch (\Throwable $e) {
            $configRepo = new ConfigRepository();
            if ($configRepo->get() !== []) {
                $configRepo->save([
                    'ibge_sync_last_checked_at' => date('Y-m-d H:i:s'),
                    'ibge_sync_last_status' => 'error',
                    'ibge_sync_last_error' => $e->getMessage(),
                ]);
            }
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=tomador&msg=ibge_pin_error');
            exit;
        }
    }


    public function evaluateCertificateFromConfig(array $config): array
    {
        $path = trim((string) ($config['certificate_path'] ?? ''));
        $password = '';
        try {
            $passwordEnc = (string) ($config['certificate_password_enc'] ?? '');
            if ($passwordEnc !== '') {
                $password = (new CryptoService())->decrypt($passwordEnc);
            }
        } catch (\Throwable $e) {
            $password = '';
        }

        $result = $this->evaluateCertificateFromInputs($path, $password);
        $status = (string) ($result['status'] ?? 'error');
        $tone = match ($status) {
            'ok' => 'ok',
            'critical' => 'attention',
            'expiring' => 'attention',
            default => 'attention',
        };
        $daysLeft = isset($result['days_left']) && $result['days_left'] !== null ? (int) $result['days_left'] : null;
        $label = match ($status) {
            'ok' => 'OK',
            'critical' => $daysLeft !== null
                ? ($daysLeft <= 1 ? 'Crítico 1d' : 'Crítico ' . $daysLeft . 'd')
                : 'Crítico',
            'expiring' => $daysLeft !== null
                ? ($daysLeft <= 1 ? 'Expira 1d' : 'Expira ' . $daysLeft . 'd')
                : 'Expira',
            'expired' => 'Expirado',
            'missing' => 'Revisar',
            default => 'Atenção',
        };

        $summary = (string) ($result['summary'] ?? '');
        if ($summary === '') {
            $summary = match ($status) {
                'ok' => 'Certificado válido e legível.',
                'critical' => $daysLeft !== null
                    ? ($daysLeft <= 1 ? 'Certificado válido, mas em prazo crítico: expira em 1 dia.' : 'Certificado válido, mas em prazo crítico: expira em ' . $daysLeft . ' dias.')
                    : 'Certificado válido, mas em prazo crítico para renovação.',
                'expiring' => $daysLeft !== null
                    ? ($daysLeft <= 1 ? 'Certificado válido, mas expira em 1 dia.' : 'Certificado válido, mas expira em ' . $daysLeft . ' dias.')
                    : 'Certificado válido, mas próximo de expirar.',
                'expired' => 'Certificado expirado.',
                'missing' => 'Informe o certificado A1 e a senha.',
                default => 'Não foi possível validar o certificado.',
            };
        }

        return [
            'tone' => $tone,
            'status' => $status,
            'status_label' => $label,
            'summary' => $summary,
            'valid_to' => (string) ($result['valid_to'] ?? ''),
            'days_left' => $result['days_left'] ?? '',
            'subject' => (string) ($result['subject'] ?? ''),
            'issuer' => (string) ($result['issuer'] ?? ''),
            'fingerprint' => (string) ($result['fingerprint'] ?? ''),
        ];
    }


    public function evaluateCertificateFromInputs(string $certificatePath, string $certificatePassword): array
    {
        $certificatePath = trim($certificatePath);
        if ($certificatePath === '') {
            return [
                'valid' => false,
                'status' => 'missing',
                'error' => 'Informe o caminho do certificado.',
            ];
        }

        $ext = strtolower((string) pathinfo($certificatePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pfx', 'p12'], true)) {
            return [
                'valid' => false,
                'status' => 'error',
                'error' => 'O certificado deve ser um arquivo .pfx ou .p12.',
            ];
        }

        if (!file_exists($certificatePath) || !is_file($certificatePath)) {
            return [
                'valid' => false,
                'status' => 'error',
                'error' => 'O arquivo do certificado não foi encontrado no caminho informado.',
            ];
        }

        $realCertificatePath = realpath($certificatePath);
        if ($realCertificatePath === false || $realCertificatePath === '') {
            return [
                'valid' => false,
                'status' => 'error',
                'error' => 'O arquivo do certificado não foi encontrado no caminho informado.',
            ];
        }
        $certificatePath = $realCertificatePath;

        $webRoots = [];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = realpath((string) $_SERVER['DOCUMENT_ROOT']);
            if (is_string($docRoot) && $docRoot !== '') {
                $webRoots[] = $docRoot;
            }
        }
        if (defined('ROOTDIR')) {
            $rootDir = realpath((string) ROOTDIR);
            if (is_string($rootDir) && $rootDir !== '') {
                $webRoots[] = $rootDir;
            }
        }
        $webRoots = array_values(array_unique(array_filter($webRoots, static fn ($v): bool => is_string($v) && $v !== '')));
        foreach ($webRoots as $webRoot) {
            $prefix = rtrim($webRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (strpos($certificatePath, $prefix) === 0) {
                return [
                    'valid' => false,
                    'status' => 'error',
                    'error' => 'O certificado deve estar fora do webroot (ex.: fora de public_html/www).',
                ];
            }
        }
        if (!is_readable($certificatePath)) {
            return [
                'valid' => false,
                'status' => 'error',
                'error' => 'O arquivo do certificado não está acessível para leitura pelo PHP.',
            ];
        }
        if (!function_exists('openssl_pkcs12_read') || !function_exists('openssl_x509_parse')) {
            return [
                'valid' => false,
                'status' => 'error',
                'error' => 'Extensão OpenSSL não disponível no PHP.',
            ];
        }
        if ($certificatePassword === '') {
            return [
                'valid' => false,
                'status' => 'error',
                'error' => 'Informe a senha do certificado.',
            ];
        }

        $content = @file_get_contents($certificatePath);
        if ($content === false || $content === '') {
            return [
                'valid' => false,
                'status' => 'error',
                'error' => 'Não foi possível ler o arquivo do certificado.',
            ];
        }

        $certs = [];
        $ok = @openssl_pkcs12_read($content, $certs, $certificatePassword);
        if (!$ok || empty($certs['cert'])) {
            return [
                'valid' => false,
                'status' => 'error',
                'error' => 'Não foi possível abrir o certificado. Verifique a senha e o arquivo.',
            ];
        }

        $parsed = openssl_x509_parse($certs['cert']);
        if (!is_array($parsed)) {
            return [
                'valid' => false,
                'status' => 'error',
                'error' => 'Não foi possível interpretar os dados do certificado.',
            ];
        }

        $validToTs = (int) ($parsed['validTo_time_t'] ?? 0);
        $validFromTs = (int) ($parsed['validFrom_time_t'] ?? 0);
        $now = time();
        $daysLeft = $validToTs > 0 ? (int) floor(($validToTs - $now) / 86400) : null;

        $status = 'ok';
        if ($validToTs > 0 && $validToTs < $now) {
            $status = 'expired';
        } elseif ($daysLeft !== null && $daysLeft <= 7) {
            $status = 'critical';
        } elseif ($daysLeft !== null && $daysLeft <= 30) {
            $status = 'expiring';
        }

        $subjectCn = '';
        if (isset($parsed['subject']) && is_array($parsed['subject']) && isset($parsed['subject']['CN'])) {
            $subjectCn = (string) $parsed['subject']['CN'];
        }
        $issuerCn = '';
        if (isset($parsed['issuer']) && is_array($parsed['issuer']) && isset($parsed['issuer']['CN'])) {
            $issuerCn = (string) $parsed['issuer']['CN'];
        }

        $fingerprint = '';
        if (function_exists('openssl_x509_fingerprint')) {
            $fp = @openssl_x509_fingerprint($certs['cert'], 'sha1');
            if (is_string($fp)) {
                $fingerprint = $fp;
            }
        }

        return [
            'valid' => $status === 'ok' || $status === 'expiring' || $status === 'critical' || $status === 'expired',
            'status' => $status,
            'valid_from' => $validFromTs > 0 ? date('d/m/Y H:i', $validFromTs) : '',
            'valid_to' => $validToTs > 0 ? date('d/m/Y H:i', $validToTs) : '',
            'days_left' => $daysLeft,
            'subject' => $subjectCn,
            'issuer' => $issuerCn,
            'fingerprint' => $fingerprint,
            'summary' => $status === 'ok'
                ? 'Certificado válido e legível.'
                : ($status === 'critical'
                    ? 'Certificado válido, mas em prazo crítico para renovação.'
                    : ($status === 'expiring' ? 'Certificado válido, mas próximo de expirar.' : 'Certificado expirado.')),
        ];
    }


    public function renderDanfseConfigContent(array $config): void
    {
        $msg = (string) ($_REQUEST['msg'] ?? '');
        if ($msg === 'danfse_saved') {
            echo '<div class="successbox">Configuração do DANFS-e salva.</div>';
        } elseif ($msg === 'danfse_invalid') {
            $detail = trim((string) ($_REQUEST['detail'] ?? ''));
            $suffix = $detail !== '' ? ' ' . $detail : '';
            echo '<div class="errorbox">Revise os campos da configuração do DANFS-e.' . htmlspecialchars($suffix, ENT_QUOTES, 'UTF-8') . '</div>';
        } elseif ($msg === 'danfse_error') {
            echo '<div class="errorbox">Erro ao salvar a configuração do DANFS-e.</div>';
        }

        $defaults = $this->getDefaultDanfseConfig();
        $token = (new TokenService())->token();
        $previewLogoSvg = $this->prepareSvgPreviewMarkup((string) (($config['danfse_logo_svg'] ?? '') !== '' ? $config['danfse_logo_svg'] : $defaults['danfse_logo_svg']));
        $preview = [
            'logo_svg' => $previewLogoSvg,
            'municipio_nome' => (string) (($config['danfse_municipio_nome'] ?? '') !== '' ? $config['danfse_municipio_nome'] : $defaults['danfse_municipio_nome']),
            'secretaria_nome' => (string) (($config['danfse_secretaria_nome'] ?? '') !== '' ? $config['danfse_secretaria_nome'] : $defaults['danfse_secretaria_nome']),
            'telefone' => (string) (($config['danfse_telefone'] ?? '') !== '' ? $config['danfse_telefone'] : $defaults['danfse_telefone']),
            'email' => (string) (($config['danfse_email'] ?? '') !== '' ? $config['danfse_email'] : $defaults['danfse_email']),
            'environment' => (string) ($config['environment'] ?? 'homologacao'),
        ];

        $this->renderConfigSectionStart('Cabeçalho do DANFS-e', 'Defina o logotipo e os dados institucionais exibidos no cabeçalho do PDF gerado.');
        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=saveDanfseConfig">';
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
        }
        $this->renderConfigFormTableStart();
        $this->renderTextareaRow('danfse_logo_svg', 'Logo SVG', (string) (($config['danfse_logo_svg'] ?? '') !== '' ? $config['danfse_logo_svg'] : $defaults['danfse_logo_svg']));
        $this->renderTextRow('danfse_municipio_nome', 'Nome', (string) (($config['danfse_municipio_nome'] ?? '') !== '' ? $config['danfse_municipio_nome'] : $defaults['danfse_municipio_nome']));
        $this->renderTextRow('danfse_secretaria_nome', 'Secretaria', (string) (($config['danfse_secretaria_nome'] ?? '') !== '' ? $config['danfse_secretaria_nome'] : $defaults['danfse_secretaria_nome']));
        $this->renderTextRow('danfse_telefone', 'Telefone', (string) (($config['danfse_telefone'] ?? '') !== '' ? $config['danfse_telefone'] : $defaults['danfse_telefone']));
        $this->renderTextRow('danfse_email', 'E-mail', (string) (($config['danfse_email'] ?? '') !== '' ? $config['danfse_email'] : $defaults['danfse_email']));
        $this->renderConfigFormTableEnd();
        echo '<div style="padding:6px 0 2px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><button type="submit" class="btn btn-primary">Salvar configuração DANFS-e</button></div>';
        echo '</form>';
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('Pré-visualização', 'Visualize como o cabeçalho do PDF DANFS-e deve ficar com base nas configurações atuais.');
        echo '<div class="nfse-config-box" style="margin:16px;padding:18px;">';
        echo '<div style="border:1px solid #d8e1ea;border-radius:10px;background:#fff;padding:16px 18px;">';
        echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">';
        echo '<div style="flex:1 1 280px;text-align:center;min-width:280px;">';
        echo '<div style="font-weight:700;font-size:15px;color:#1f2937;">DANFSe v1.0</div>';
        echo '<div style="font-weight:700;font-size:15px;color:#1f2937;">Documento Auxiliar da NFS-e</div>';
        if ($preview['environment'] === 'homologacao') {
            echo '<div style="font-weight:700;font-size:15px;color:#c00000;">NFS-e SEM VALIDADE JURÍDICA</div>';
        }
        echo '</div>';
        echo '<div style="display:flex;gap:10px;align-items:flex-start;flex:1 1 290px;justify-content:flex-end;min-width:290px;">';
        if (trim((string) $preview['logo_svg']) !== '' && stripos((string) $preview['logo_svg'], '<svg') !== false) {
            echo '<div style="width:48px;height:48px;display:flex;align-items:flex-start;justify-content:center;overflow:hidden;">' . $preview['logo_svg'] . '</div>';
        }
        echo '<div style="min-width:220px;text-align:left;">';
        echo '<div style="font-weight:700;font-size:13px;color:#1f2937;">' . htmlspecialchars((string) $preview['municipio_nome'], ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div style="font-size:12px;color:#475569;">' . htmlspecialchars((string) $preview['secretaria_nome'], ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div style="font-size:12px;color:#475569;">' . htmlspecialchars((string) $preview['telefone'], ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div style="font-size:12px;color:#475569;">' . htmlspecialchars((string) $preview['email'], ENT_QUOTES, 'UTF-8') . '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="nfse-config-note" style="padding:0 16px 10px 16px;">A pré-visualização é ilustrativa e reflete o cabeçalho configurado. O PDF final continua aplicando automaticamente o aviso em homologação.</div>';
        echo '</div>';
        $this->renderConfigSectionEnd();
    }


    public function saveDanfseConfig(): void
    {
        $defaults = $this->getDefaultDanfseConfig();
        $logoSvgRaw = (string) ($_POST['danfse_logo_svg'] ?? '');
        $logoSvgRaw = str_replace("\0", '', $logoSvgRaw);
        $logoSvgRaw = preg_replace('/^\xEF\xBB\xBF/', '', $logoSvgRaw) ?? $logoSvgRaw;
        $logoSvgRaw = preg_replace('/\p{Cf}+/u', '', $logoSvgRaw) ?? $logoSvgRaw;
        $logoSvgRaw = trim($logoSvgRaw);
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($logoSvgRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $logoSvgRaw) {
                break;
            }
            $logoSvgRaw = $decoded;
        }
        $logoSvgRawTrim = trim($logoSvgRaw);
        if ($logoSvgRawTrim === '' || preg_match('/<\s*svg\b/i', $logoSvgRawTrim) !== 1) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=danfse&msg=danfse_invalid&detail=' . rawurlencode(' Cole o SVG completo no campo Logo SVG.'));
            exit;
        }
        if (preg_match('/<\/\s*svg\s*>/i', $logoSvgRawTrim) !== 1) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=danfse&msg=danfse_invalid&detail=' . rawurlencode(' O SVG parece incompleto (não foi encontrado </svg>).'));
            exit;
        }
        $logoSvg = $this->normalizeSvgMarkup($logoSvgRawTrim);
        if ($logoSvg === '') {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=danfse&msg=danfse_invalid&detail=' . rawurlencode(' Não foi possível normalizar o SVG. Tente colar apenas o bloco <svg>...</svg>.'));
            exit;
        }
        $municipioNome = trim((string) ($_POST['danfse_municipio_nome'] ?? ''));
        $secretariaNome = trim((string) ($_POST['danfse_secretaria_nome'] ?? ''));
        $telefone = trim((string) ($_POST['danfse_telefone'] ?? ''));
        $email = trim((string) ($_POST['danfse_email'] ?? ''));

        if ($municipioNome === '' || $secretariaNome === '' || $telefone === '' || $email === '') {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=danfse&msg=danfse_invalid&detail=' . rawurlencode(' Preencha Nome, Secretaria, Telefone e E-mail.'));
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=danfse&msg=danfse_invalid&detail=' . rawurlencode(' O e-mail informado é inválido.'));
            exit;
        }

        try {
            (new ConfigRepository())->save([
                'danfse_logo_svg' => $logoSvg,
                'danfse_municipio_nome' => $municipioNome,
                'danfse_secretaria_nome' => $secretariaNome,
                'danfse_telefone' => $telefone,
                'danfse_email' => $email,
            ]);
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=danfse&msg=danfse_saved');
            exit;
        } catch (\Throwable $e) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=danfse&msg=danfse_error');
            exit;
        }
    }


    public function getDefaultDanfseConfig(): array
    {
        $logoSvg = '';
        $defaultLogoPath = dirname(__DIR__, 3) . '/assets/brasao_itajai.svg';
        if (is_file($defaultLogoPath)) {
            $svg = file_get_contents($defaultLogoPath);
            if (is_string($svg) && trim($svg) !== '') {
                $logoSvg = $svg;
            }
        }

        return [
            'danfse_logo_svg' => $logoSvg,
            'danfse_municipio_nome' => 'MUNICÍPIO DE ITAJAÍ',
            'danfse_secretaria_nome' => 'SECRETARIA MUNICIPAL DA FAZENDA',
            'danfse_telefone' => '(47)3241-7400',
            'danfse_email' => 'plantaofiscal@itajai.sc.gov.br',
        ];
    }


    public function normalizeSvgMarkup(string $svg): string
    {
        $svg = str_replace("\0", '', $svg);
        $svg = preg_replace('/^\xEF\xBB\xBF/', '', $svg) ?? $svg;
        $svg = preg_replace('/\p{Cf}+/u', '', $svg) ?? $svg;
        $svg = trim($svg);
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($svg, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $svg) {
                break;
            }
            $svg = $decoded;
        }
        $svg = trim($svg);
        if ($svg === '' || preg_match('/<\s*svg\b/i', $svg) !== 1) {
            return '';
        }

        $svg = preg_replace('/<\?xml.*?\?>/is', '', $svg) ?? $svg;
        $svg = preg_replace('/<!DOCTYPE.*?>/is', '', $svg) ?? $svg;
        $svg = trim($svg);

        if (preg_match('/<\s*svg\b[\s\S]*<\/\s*svg\s*>/i', $svg, $m) !== 1) {
            return '';
        }
        $svg = trim((string) ($m[0] ?? ''));
        if ($svg === '') {
            return '';
        }

        $svg = preg_replace('/<script\b[\s\S]*?<\/script>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<foreignObject\b[\s\S]*?<\/foreignObject>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<\s*(?:image|use)\b[\s\S]*?\/\s*>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<\s*(?:image|use)\b[\s\S]*?<\/\s*(?:image|use)\s*>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/\s(?:xlink:)?href\s*=\s*([\'"])\s*(?:https?:|file:|data:)[^\'"]*\1/i', '', $svg) ?? $svg;
        $svg = trim($svg);

        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $ok = $doc->loadXML($svg, LIBXML_NONET);
            if (!$ok) {
                return $svg;
            }

            $root = $doc->documentElement;
            if (!$root || strtolower((string) $root->localName) !== 'svg') {
                return $svg;
            }

            if (!$root->hasAttribute('xmlns')) {
                $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
            }

            return trim((string) $doc->saveXML($root));
        } catch (\Throwable $e) {
            return $svg;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }


    public function prepareSvgPreviewMarkup(string $svg): string
    {
        $svg = $this->normalizeSvgMarkup($svg);
        if ($svg === '') {
            return '';
        }

        if (preg_match('/<svg\b/i', $svg) === 1) {
            $svg = preg_replace(
                '/<svg\b/i',
                '<svg style="display:block;width:100%;height:100%;max-width:48px;max-height:48px;" preserveAspectRatio="xMidYMid meet"',
                $svg,
                1
            ) ?? $svg;
        }

        return $svg;
    }


    public function renderServiceNbsCatalogContent(): void
    {
        $msg = (string) ($_REQUEST['msg'] ?? '');
        if ($msg === 'service_nbs_saved') {
            echo '<div class="successbox">Relação de código de serviço e NBS adicionada.</div>';
        } elseif ($msg === 'service_nbs_exists') {
            echo '<div class="errorbox">Essa relação já existe no catálogo.</div>';
        } elseif ($msg === 'service_nbs_invalid') {
            echo '<div class="errorbox">Informe código de serviço com 6 dígitos, NBS com 9 dígitos e uma descrição.</div>';
        } elseif ($msg === 'service_nbs_deleted') {
            echo '<div class="successbox">Relação de código de serviço e NBS removida.</div>';
        } elseif ($msg === 'service_nbs_updated') {
            echo '<div class="successbox">Relação de código de serviço e NBS atualizada.</div>';
        } elseif ($msg === 'service_nbs_in_use') {
            $usageDetail = trim((string) ($_REQUEST['usage'] ?? ''));
            $message = 'Não é possível excluir essa relação porque ela está em uso.';
            if ($usageDetail !== '') {
                $message .= ' ' . $usageDetail;
            } else {
                $message .= ' Revise a configuração padrão e os grupos mapeados.';
            }
            echo '<div class="errorbox">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
        } elseif ($msg === 'service_nbs_error') {
            echo '<div class="errorbox">Erro ao processar a relação de código de serviço e NBS. Tente novamente e, se persistir, revise os vínculos dessa relação nas demais configurações do módulo.</div>';
        }

        $repo = new ServiceNbsCatalogRepository();
        $rows = $repo->listAll();
        $catalogCount = count($rows);
        $token = (new TokenService())->token();
        $usageMap = $this->getServiceNbsUsageMap();
        $editId = (int) ($_GET['edit_service_nbs_id'] ?? 0);
        $editRow = $editId > 0 ? $repo->findById($editId) : null;

        $this->renderConfigSectionStart('Relações cadastradas', 'As opções salvas aqui passam a alimentar a seleção de código de serviço e NBS usada no módulo. Total atual: ' . $catalogCount . ' relação(ões).');
        if (empty($rows)) {
            echo '<div class="nfse-config-note" style="padding:14px 16px;">Nenhuma relação cadastrada até o momento.</div>';
        } else {
            echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
            echo '<tr><th style="width:14%;">Código de Serviço</th><th style="width:14%;">NBS</th><th style="width:42%;">Descrição</th><th style="width:18%;">Uso atual</th><th style="width:12%;">Ações</th></tr>';
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $usageKey = (string) ($row['codigo_servico'] ?? '') . '|' . (string) ($row['nbs'] ?? '');
                $usage = $usageMap[$usageKey] ?? [
                    'is_default' => false,
                    'group_names' => [],
                    'summary' => 'Livre para remover',
                ];
                echo '<tr>';
                echo '<td><span class="nfse-config-mono">' . htmlspecialchars((string) ($row['codigo_servico'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span></td>';
                echo '<td><span class="nfse-config-mono">' . htmlspecialchars((string) ($row['nbs'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span></td>';
                echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) ($row['descricao'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars((string) ($usage['summary'] ?? 'Livre para remover'), ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>';
                echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=deleteServiceNbsCatalog" style="display:inline-block;margin:0;">';
                if ($token !== '') {
                    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
                }
                echo '<input type="hidden" name="id" value="' . $id . '" />';
                echo '<a href="addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&edit_service_nbs_id=' . $id . '" class="btn btn-xs btn-default" style="margin-right:6px;">Editar</a>';
                echo '<button type="submit" class="btn btn-xs btn-default" onclick="return confirm(\'Remover esta relação também a tornará indisponível nas demais telas. Continuar?\');">Excluir</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        $this->renderConfigSectionEnd();

        if ($editRow) {
            $this->renderConfigSectionStart('Editar relação', 'Atualize o código de serviço, a NBS ou a descrição. Se o vínculo estiver em uso, a alteração é propagada para Tributação e para os grupos mapeados.');
            echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=updateServiceNbsCatalog">';
            if ($token !== '') {
                echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
            }
            echo '<input type="hidden" name="id" value="' . (int) ($editRow['id'] ?? 0) . '" />';
            $this->renderConfigFormTableStart();
            echo '<tr><td class="fieldlabel"><div class="nfse-config-label-title">Código de Serviço</div></td><td class="fieldarea"><input type="text" name="codigo_servico_catalog" class="form-control nfse-config-input nfse-config-mono" maxlength="6" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/\\D/g, \'\');" value="' . htmlspecialchars((string) ($editRow['codigo_servico'] ?? ''), ENT_QUOTES, 'UTF-8') . '" /><div class="nfse-config-help">Use 6 dígitos numéricos, sem pontuação.</div></td></tr>';
            echo '<tr><td class="fieldlabel"><div class="nfse-config-label-title">NBS</div></td><td class="fieldarea"><input type="text" name="nbs_catalog" class="form-control nfse-config-input nfse-config-mono" maxlength="9" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/\\D/g, \'\');" value="' . htmlspecialchars((string) ($editRow['nbs'] ?? ''), ENT_QUOTES, 'UTF-8') . '" /><div class="nfse-config-help">Use 9 dígitos numéricos para a NBS vinculada ao código de serviço.</div></td></tr>';
            echo '<tr><td class="fieldlabel"><div class="nfse-config-label-title">Descrição</div></td><td class="fieldarea"><textarea name="descricao_catalog" class="form-control nfse-config-input" rows="3" style="resize:vertical;">' . htmlspecialchars((string) ($editRow['descricao'] ?? ''), ENT_QUOTES, 'UTF-8') . '</textarea><div class="nfse-config-help">Texto exibido nas listas de seleção para facilitar a identificação da NBS.</div></td></tr>';
            $this->renderConfigFormTableEnd();
            echo '<div style="padding:6px 0 2px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><button type="submit" class="btn btn-primary">Salvar edição</button><a href="addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs" class="btn btn-default">Cancelar</a></div>';
            echo '</form>';
            $this->renderConfigSectionEnd();
        }

        $this->renderConfigSectionStart('Adicionar nova relação', 'Cadastre um novo código de serviço e a NBS correspondente para disponibilizar essa opção nas demais configurações.');
        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=saveServiceNbsCatalog">';
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
        }
        $this->renderConfigFormTableStart();
        echo '<tr><td class="fieldlabel"><div class="nfse-config-label-title">Código de Serviço</div></td><td class="fieldarea"><input type="text" name="codigo_servico_catalog" class="form-control nfse-config-input nfse-config-mono" maxlength="6" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/\\D/g, \'\');" placeholder="Ex.: 010301" /><div class="nfse-config-help">Use 6 dígitos numéricos, sem pontuação.</div></td></tr>';
        echo '<tr><td class="fieldlabel"><div class="nfse-config-label-title">NBS</div></td><td class="fieldarea"><input type="text" name="nbs_catalog" class="form-control nfse-config-input nfse-config-mono" maxlength="9" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/\\D/g, \'\');" placeholder="Ex.: 115061000" /><div class="nfse-config-help">Use 9 dígitos numéricos para a NBS vinculada ao código de serviço.</div></td></tr>';
        echo '<tr><td class="fieldlabel"><div class="nfse-config-label-title">Descrição</div></td><td class="fieldarea"><textarea name="descricao_catalog" class="form-control nfse-config-input" rows="3" style="resize:vertical;" placeholder="Descrição amigável da NBS"></textarea><div class="nfse-config-help">Texto exibido nas listas de seleção para facilitar a identificação da NBS.</div></td></tr>';
        $this->renderConfigFormTableEnd();
        echo '<div style="padding:6px 0 2px 0;"><button type="submit" class="btn btn-primary">Adicionar relação</button></div>';
        echo '</form>';
        $this->renderConfigSectionEnd();
    }


    public function saveServiceNbsCatalog(): void
    {
        $codigoServico = preg_replace('/\D/', '', (string) ($_POST['codigo_servico_catalog'] ?? ''));
        $nbs = preg_replace('/\D/', '', (string) ($_POST['nbs_catalog'] ?? ''));
        $descricao = trim((string) ($_POST['descricao_catalog'] ?? ''));
        if (strlen($codigoServico) !== 6 || strlen($nbs) !== 9 || $descricao === '') {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_invalid');
            exit;
        }

        try {
            $repo = new ServiceNbsCatalogRepository();
            if ($repo->existsByServiceAndNbs($codigoServico, $nbs)) {
                header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_exists');
                exit;
            }

            $repo->insert($codigoServico, $nbs, $descricao);
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_saved');
            exit;
        } catch (\Throwable $e) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_error');
            exit;
        }
    }


    public function updateServiceNbsCatalog(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $codigoServico = preg_replace('/\D/', '', (string) ($_POST['codigo_servico_catalog'] ?? ''));
        $nbs = preg_replace('/\D/', '', (string) ($_POST['nbs_catalog'] ?? ''));
        $descricao = trim((string) ($_POST['descricao_catalog'] ?? ''));
        if ($id <= 0 || strlen($codigoServico) !== 6 || strlen($nbs) !== 9 || $descricao === '') {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_invalid&edit_service_nbs_id=' . $id);
            exit;
        }

        try {
            $repo = new ServiceNbsCatalogRepository();
            $currentRow = $repo->findById($id);
            if (!$currentRow) {
                header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_error');
                exit;
            }

            $oldCodigoServico = preg_replace('/\D/', '', (string) ($currentRow['codigo_servico'] ?? ''));
            $oldNbs = preg_replace('/\D/', '', (string) ($currentRow['nbs'] ?? ''));
            $samePair = $oldCodigoServico === $codigoServico && $oldNbs === $nbs;
            if (!$samePair && $repo->existsByServiceAndNbs($codigoServico, $nbs)) {
                header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_exists&edit_service_nbs_id=' . $id);
                exit;
            }

            $matchingIds = $repo->listMatchingIdsByNormalizedPair($oldCodigoServico, $oldNbs);
            if (empty($matchingIds)) {
                header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_error');
                exit;
            }

            $configRepo = new ConfigRepository();
            $config = $configRepo->get();
            $now = date('Y-m-d H:i:s');
            Capsule::connection()->transaction(function () use ($configRepo, $config, $oldCodigoServico, $oldNbs, $codigoServico, $nbs, $descricao, $matchingIds, $repo, $now): void {
                $defaultCodigoServico = preg_replace('/\D/', '', (string) ($config['codigo_servico'] ?? ''));
                $defaultNbs = preg_replace('/\D/', '', (string) ($config['nbs_padrao'] ?? ''));
                if ($defaultCodigoServico === $oldCodigoServico && $defaultNbs === $oldNbs) {
                    $configRepo->save([
                        'codigo_servico' => $codigoServico,
                        'nbs_padrao' => $nbs,
                    ]);
                }

                Capsule::table('mod_opennfse_group_service_codes')
                    ->where('codigo_servico', $oldCodigoServico)
                    ->where('nbs', $oldNbs)
                    ->update([
                        'codigo_servico' => $codigoServico,
                        'nbs' => $nbs,
                        'updated_at' => $now,
                    ]);

                $repo->deleteIds($matchingIds);
                $repo->insert($codigoServico, $nbs, $descricao);
            });

            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_updated');
            exit;
        } catch (\Throwable $e) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_error&edit_service_nbs_id=' . $id);
            exit;
        }
    }


    public function getServiceNbsUsageMap(): array
    {
        $usageMap = [];
        $config = (new ConfigRepository())->get();
        $defaultKey = preg_replace('/\D/', '', (string) ($config['codigo_servico'] ?? '')) . '|' . preg_replace('/\D/', '', (string) ($config['nbs_padrao'] ?? ''));
        if ($defaultKey !== '|') {
            $usageMap[$defaultKey] = [
                'is_default' => true,
                'group_names' => [],
                'summary' => 'Usada como padrão em Tributação',
            ];
        }

        $effectiveMap = (new GroupServiceCodeRepository())->getAllByGroupId();
        if (!empty($effectiveMap)) {
            $groups = Capsule::table('tblproductgroups')
                ->whereIn('id', array_keys($effectiveMap))
                ->orderBy('order', 'asc')
                ->orderBy('name', 'asc')
                ->get();

            foreach ($groups as $groupRow) {
                $groupId = (int) ($groupRow->id ?? 0);
                if ($groupId <= 0 || !isset($effectiveMap[$groupId])) {
                    continue;
                }
                $groupMap = $effectiveMap[$groupId];
                $mappedServico = preg_replace('/\D/', '', (string) ($groupMap['codigo_servico'] ?? ''));
                $mappedNbs = preg_replace('/\D/', '', (string) ($groupMap['nbs'] ?? ''));
                if ($mappedServico === '' || $mappedNbs === '') {
                    continue;
                }

                $key = $mappedServico . '|' . $mappedNbs;
                if (!isset($usageMap[$key])) {
                    $usageMap[$key] = [
                        'is_default' => false,
                        'group_names' => [],
                        'summary' => '',
                    ];
                }

                $groupName = trim((string) ($groupRow->name ?? ''));
                if ($groupName !== '') {
                    $usageMap[$key]['group_names'][] = $groupName;
                }
            }
        }

        foreach ($usageMap as $key => $usage) {
            $parts = [];
            if (!empty($usage['is_default'])) {
                $parts[] = 'Padrão em Tributação';
            }
            $groupNames = array_values(array_unique(array_filter($usage['group_names'] ?? [])));
            if (!empty($groupNames)) {
                $label = count($groupNames) === 1 ? '1 grupo' : count($groupNames) . ' grupos';
                $parts[] = 'Mapeada em ' . $label . ': ' . implode(', ', $groupNames);
            }
            $usageMap[$key]['group_names'] = $groupNames;
            $usageMap[$key]['summary'] = !empty($parts) ? implode(' | ', $parts) : 'Livre para remover';
        }

        return $usageMap;
    }


    public function deleteServiceNbsCatalog(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_error');
            exit;
        }

        try {
            $repo = new ServiceNbsCatalogRepository();
            $row = $repo->findById($id);
            if (!$row) {
                header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_error');
                exit;
            }

            $codigoServico = (string) ($row['codigo_servico'] ?? '');
            $nbs = (string) ($row['nbs'] ?? '');
            $config = (new ConfigRepository())->get();
            $defaultInUse = trim((string) ($config['codigo_servico'] ?? '')) === $codigoServico
                && trim((string) ($config['nbs_padrao'] ?? '')) === $nbs;
            $groupNames = [];
            $effectiveMap = (new GroupServiceCodeRepository())->getAllByGroupId();
            if (!empty($effectiveMap)) {
                $groups = Capsule::table('tblproductgroups')
                    ->whereIn('id', array_keys($effectiveMap))
                    ->orderBy('order', 'asc')
                    ->orderBy('name', 'asc')
                    ->get();
                foreach ($groups as $groupRow) {
                    $groupId = (int) ($groupRow->id ?? 0);
                    if ($groupId <= 0 || !isset($effectiveMap[$groupId])) {
                        continue;
                    }
                    $groupMap = $effectiveMap[$groupId];
                    $mappedServico = preg_replace('/\D/', '', (string) ($groupMap['codigo_servico'] ?? ''));
                    $mappedNbs = preg_replace('/\D/', '', (string) ($groupMap['nbs'] ?? ''));
                    if ($mappedServico === $codigoServico && $mappedNbs === $nbs) {
                        $name = trim((string) ($groupRow->name ?? ''));
                        if ($name !== '') {
                            $groupNames[] = $name;
                        }
                    }
                }
            }

            if ($defaultInUse || !empty($groupNames)) {
                $usageParts = [];
                if ($defaultInUse) {
                    $usageParts[] = 'Está selecionada como configuração padrão na aba Tributação.';
                }
                if (!empty($groupNames)) {
                    $usageParts[] = 'Está mapeada nos grupos: ' . implode(', ', $groupNames) . '.';
                }
                $usageMessage = rawurlencode(implode(' ', $usageParts));
                header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_in_use&usage=' . $usageMessage);
                exit;
            }

            $deletedRows = $repo->deleteRelationById($id);
            if ($deletedRows <= 0) {
                header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_error');
                exit;
            }
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_deleted');
            exit;
        } catch (\Throwable $e) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&msg=service_nbs_error');
            exit;
        }
    }


    public function renderTributacaoServiceNbsFields(array $config): void
    {
        $selectedServico = preg_replace('/\D/', '', (string) ($config['codigo_servico'] ?? ''));
        $selectedNbs = preg_replace('/\D/', '', (string) ($config['nbs_padrao'] ?? ''));

        try {
            $catalogRepo = new ServiceNbsCatalogRepository();
            $servicoOptions = $catalogRepo->getServiceOptions(false);
            $nbsOptionsByService = $catalogRepo->getNbsOptionsByServiceCode();
        } catch (\Throwable $e) {
            $servicoOptions = [];
            $nbsOptionsByService = [];
        }

        if (empty($servicoOptions) || empty($nbsOptionsByService)) {
            $this->renderTextRow('codigo_servico', 'Código de Serviço Padrão', $config['codigo_servico'] ?? '');
            $this->renderTextRow('nbs_padrao', 'NBS Padrão', $config['nbs_padrao'] ?? '');
            echo '<tr><td class="fieldlabel"></td><td class="fieldarea"><div class="nfse-config-help">Quando o catálogo estiver disponível, esses campos passam a usar a relação cadastrada na subaba Código de Serviços e NBS.</div></td></tr>';
            return;
        }

        if ($selectedServico !== '' && !isset($servicoOptions[$selectedServico])) {
            $servicoOptions[$selectedServico] = $selectedServico . ' (Atual fora do catálogo)';
        }
        if ($selectedServico !== '' && !isset($nbsOptionsByService[$selectedServico])) {
            $nbsOptionsByService[$selectedServico] = [];
        }
        if ($selectedServico !== '' && $selectedNbs !== '' && !isset($nbsOptionsByService[$selectedServico][$selectedNbs])) {
            $nbsOptionsByService[$selectedServico][$selectedNbs] = $selectedNbs . ' - Valor atual fora do catálogo';
        }

        $jsNbsOptions = json_encode($nbsOptionsByService, JSON_UNESCAPED_UNICODE);

        echo '<tr>';
        echo '<td class="fieldlabel"><div class="nfse-config-label-title">Código de Serviço Padrão</div></td>';
        echo '<td class="fieldarea">';
        echo '<select name="codigo_servico" id="nfse-tributacao-servico" class="form-control nfse-config-select">';
        echo '<option value="">Selecione</option>';
        foreach ($servicoOptions as $value => $label) {
            $selected = $value === $selectedServico ? ' selected' : '';
            echo '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';
        echo '<div class="nfse-config-help">As opções são carregadas do catálogo mantido na subaba Código de Serviços e NBS.</div>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td class="fieldlabel"><div class="nfse-config-label-title">NBS Padrão</div></td>';
        echo '<td class="fieldarea">';
        echo '<select name="nbs_padrao" id="nfse-tributacao-nbs" class="form-control nfse-config-select"></select>';
        echo '<div class="nfse-config-help" id="nfse-tributacao-nbs-help">Selecione um código de serviço para carregar as NBS relacionadas.</div>';
        echo '</td>';
        echo '</tr>';

        echo '<script>(function(){var selectedNbs=' . json_encode($selectedNbs, JSON_UNESCAPED_UNICODE) . ';var nbsOptions=' . $jsNbsOptions . ';var servicoEl=document.getElementById("nfse-tributacao-servico");var nbsEl=document.getElementById("nfse-tributacao-nbs");var helpEl=document.getElementById("nfse-tributacao-nbs-help");if(!servicoEl||!nbsEl){return;}function fill(){var servico=servicoEl.value||"";nbsEl.innerHTML="";if(servico===""){var placeholder=document.createElement("option");placeholder.value="";placeholder.textContent="Selecione um código de serviço primeiro";nbsEl.appendChild(placeholder);nbsEl.disabled=true;if(helpEl){helpEl.textContent="Cadastre novas combinações na subaba Código de Serviços e NBS sempre que necessário.";}return;}var opts=nbsOptions[servico]||{};var first="";for(var key in opts){if(!opts.hasOwnProperty(key)){continue;}if(first===""){first=key;}var option=document.createElement("option");option.value=key;option.textContent=opts[key];if(selectedNbs!==""&&selectedNbs===key){option.selected=true;}nbsEl.appendChild(option);}if(nbsEl.options.length===0){var empty=document.createElement("option");empty.value="";empty.textContent="(Sem NBS cadastrada para este código)";nbsEl.appendChild(empty);nbsEl.disabled=true;if(helpEl){helpEl.textContent="Adicione uma NBS relacionada a este código na subaba Código de Serviços e NBS.";}return;}nbsEl.disabled=false;if(selectedNbs===""||!opts.hasOwnProperty(selectedNbs)){nbsEl.value=first;}if(helpEl){helpEl.textContent="A NBS selecionada será usada como padrão quando não houver regra específica por grupo.";}selectedNbs=nbsEl.value||"";}fill();servicoEl.addEventListener("change",function(){selectedNbs="";fill();});})();</script>';
    }


    public function renderCodigosProdutosServicosContent(): void
    {
        if ((string) ($_GET['codes_saved'] ?? '') === '1') {
            echo '<div class="successbox">Configuração salva.</div>';
        }

        $config = (new ConfigRepository())->get();
        $defaultServico = (string) ($config['codigo_servico'] ?? '');
        $defaultNbs = (string) ($config['nbs_padrao'] ?? '');

        $groups = Capsule::table('tblproductgroups')->orderBy('order', 'asc')->orderBy('name', 'asc')->get();
        $map = (new GroupServiceCodeRepository())->getAllByGroupId();
        $catalogRepo = new ServiceNbsCatalogRepository();
        $servicoOptions = $catalogRepo->getServiceOptions(true);
        $nbsOptions = $catalogRepo->getNbsOptionsByServiceCode();

        $this->renderConfigSectionStart('Mapeamento por grupo', 'Associe cada grupo do WHMCS a um código de serviço e NBS. Quando estiver em "Padrão", o módulo usa os valores definidos na aba Tributação.');
        echo '<div class="nfse-config-box" style="margin:16px 16px 0 16px;">';
        echo '<div class="nfse-config-note">Revise esses mapeamentos sempre que novos grupos de produto forem criados no WHMCS ou quando houver atualização fiscal.</div>';
        echo '</div>';

        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=saveCodigos">';
        $token = (new TokenService())->token();
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
        }

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;margin-top:16px;">';
        echo '<tr><th style="width:22%;">Grupo</th><th style="width:18%;">Código de Serviço</th><th style="width:60%;">NBS</th></tr>';

        foreach ($groups as $g) {
            $gid = (int) ($g->id ?? 0);
            if ($gid <= 0) {
                continue;
            }
            $name = (string) ($g->name ?? '');

            $current = $map[$gid] ?? ['codigo_servico' => null, 'nbs' => null];
            $curServico = $current['codigo_servico'] !== null ? (string) $current['codigo_servico'] : '';
            $curNbs = $current['nbs'] !== null ? (string) $current['nbs'] : '';
            $rowServicoOptions = $servicoOptions;
            $rowNbsOptions = $nbsOptions;
            if ($curServico !== '' && !isset($rowServicoOptions[$curServico])) {
                $rowServicoOptions[$curServico] = $curServico . ' (Atual fora do catálogo)';
            }
            if ($curServico !== '' && !isset($rowNbsOptions[$curServico])) {
                $rowNbsOptions[$curServico] = [];
            }
            if ($curServico !== '' && $curNbs !== '' && !isset($rowNbsOptions[$curServico][$curNbs])) {
                $rowNbsOptions[$curServico][$curNbs] = $curNbs . ' - Valor atual fora do catálogo';
            }

            echo '<tr>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>';

            echo '<td>';
            echo '<select class="form-control nfse-servico" name="codigo_servico[' . (int) $gid . ']" data-gid="' . (int) $gid . '" style="width:100%;max-width:240px;">';
            foreach ($rowServicoOptions as $val => $label) {
                $sel = $val === $curServico ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
            }
            echo '</select>';
            echo '</td>';

            echo '<td>';
            echo '<select class="form-control nfse-nbs" name="nbs[' . (int) $gid . ']" data-gid="' . (int) $gid . '" style="width:100%;max-width:100%;">';
            echo '</select>';
            echo '<div class="help-block nfse-nbs-help" data-gid="' . (int) $gid . '" style="margin:4px 0 0 0;">';
            echo '</div>';
            echo '</td>';

            echo '</tr>';

            echo '<input type="hidden" class="nfse-cur-servico" data-gid="' . (int) $gid . '" value="' . htmlspecialchars($curServico, ENT_QUOTES, 'UTF-8') . '" />';
            echo '<input type="hidden" class="nfse-cur-nbs" data-gid="' . (int) $gid . '" value="' . htmlspecialchars($curNbs, ENT_QUOTES, 'UTF-8') . '" />';
        }

        echo '</table>';
        echo '<div style="padding-top:12px;"><button type="submit" class="btn btn-primary">Salvar</button></div>';
        echo '</form>';
        $this->renderConfigSectionEnd();

        $mergedNbsOptions = $nbsOptions;
        foreach ($map as $currentRow) {
            $curServico = $currentRow['codigo_servico'] !== null ? (string) $currentRow['codigo_servico'] : '';
            $curNbs = $currentRow['nbs'] !== null ? (string) $currentRow['nbs'] : '';
            if ($curServico === '') {
                continue;
            }
            if (!isset($mergedNbsOptions[$curServico])) {
                $mergedNbsOptions[$curServico] = [];
            }
            if ($curNbs !== '' && !isset($mergedNbsOptions[$curServico][$curNbs])) {
                $mergedNbsOptions[$curServico][$curNbs] = $curNbs . ' - Valor atual fora do catálogo';
            }
        }

        $jsNbsOptions = json_encode($mergedNbsOptions, JSON_UNESCAPED_UNICODE);
        $jsDefaultServico = json_encode($defaultServico, JSON_UNESCAPED_UNICODE);
        $jsDefaultNbs = json_encode($defaultNbs, JSON_UNESCAPED_UNICODE);

        echo '<script>(function(){var nbsOptions=' . $jsNbsOptions . ';var defaultServico=' . $jsDefaultServico . ';var defaultNbs=' . $jsDefaultNbs . ';function curVal(cls,gid){var el=document.querySelector(cls+\'[data-gid="\'+gid+\'"]\');return el?el.value:\'\';}function fill(gid){var servEl=document.querySelector(\'.nfse-servico[data-gid="\'+gid+\'"]\');var nbsEl=document.querySelector(\'.nfse-nbs[data-gid="\'+gid+\'"]\');var help=document.querySelector(\'.nfse-nbs-help[data-gid="\'+gid+\'"]\');if(!servEl||!nbsEl){return;}var serv=servEl.value||\'\';var curNbs=curVal(\'.nfse-cur-nbs\',gid);nbsEl.innerHTML=\'\';if(serv===\'\'){var opt=document.createElement(\'option\');opt.value=\'\';opt.textContent=\'Padrão\';nbsEl.appendChild(opt);nbsEl.disabled=true;var s=defaultServico?defaultServico:\'-\';var n=defaultNbs?defaultNbs:\'-\';if(help){help.textContent=\'Usará: Código \' + s + \' e NBS \' + n; }return;}nbsEl.disabled=false;var opts=nbsOptions[serv]||{};var first=\'\';for(var k in opts){if(!opts.hasOwnProperty(k)){continue;}if(first===\'\'){first=k;}var o=document.createElement(\'option\');o.value=k;o.textContent=opts[k];if(curNbs!==\'\'&&curNbs===k){o.selected=true;}nbsEl.appendChild(o);}if(nbsEl.options.length===0){var o2=document.createElement(\'option\');o2.value=\'\';o2.textContent=\'(Sem opções)\';nbsEl.appendChild(o2);nbsEl.disabled=true;}else if(curNbs===\'\'){nbsEl.value=first;}if(help){help.textContent=\'\';}}var servs=document.querySelectorAll(\'.nfse-servico\');for(var i=0;i<servs.length;i++){(function(el){var gid=el.getAttribute(\'data-gid\');fill(gid);el.addEventListener(\'change\',function(){var h=document.querySelector(\'.nfse-cur-nbs[data-gid="\'+gid+\'"]\');if(h){h.value=\'\';}fill(gid);});})(servs[i]);}})();</script>';
    }


    public function showCodigosProdutosServicos(): void
    {
        Module::ui()->renderHeader('Códigos Produtos/Serviços - OpenNFS-e');
        $this->renderTabs('codigos');

        if ((string) ($_GET['saved'] ?? '') === '1') {
            echo '<div class="successbox">Configuração salva.</div>';
        }

        $config = (new ConfigRepository())->get();
        $defaultServico = (string) ($config['codigo_servico'] ?? '');
        $defaultNbs = (string) ($config['nbs_padrao'] ?? '');

        $groups = Capsule::table('tblproductgroups')->orderBy('order', 'asc')->orderBy('name', 'asc')->get();
        $map = (new GroupServiceCodeRepository())->getAllByGroupId();
        $catalogRepo = new ServiceNbsCatalogRepository();
        $servicoOptions = $catalogRepo->getServiceOptions(true);
        $nbsOptions = $catalogRepo->getNbsOptionsByServiceCode();

        echo '<div class="alert alert-info" style="margin-bottom:10px;">';
        echo 'Mapeie cada Grupo do WHMCS para um Código de Serviço e NBS. Se escolher Padrão, o módulo usará os valores padrão da Config.';
        echo '</div>';

        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=saveCodigos">';
        $token = (new TokenService())->token();
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
        }

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;">';
        echo '<tr><th>Grupo</th><th>Código de Serviço</th><th>NBS</th></tr>';

        foreach ($groups as $g) {
            $gid = (int) ($g->id ?? 0);
            if ($gid <= 0) {
                continue;
            }
            $name = (string) ($g->name ?? '');

            $current = $map[$gid] ?? ['codigo_servico' => null, 'nbs' => null];
            $curServico = $current['codigo_servico'] !== null ? (string) $current['codigo_servico'] : '';
            $curNbs = $current['nbs'] !== null ? (string) $current['nbs'] : '';
            $rowServicoOptions = $servicoOptions;
            $rowNbsOptions = $nbsOptions;
            if ($curServico !== '' && !isset($rowServicoOptions[$curServico])) {
                $rowServicoOptions[$curServico] = $curServico . ' (Atual fora do catálogo)';
            }
            if ($curServico !== '' && !isset($rowNbsOptions[$curServico])) {
                $rowNbsOptions[$curServico] = [];
            }
            if ($curServico !== '' && $curNbs !== '' && !isset($rowNbsOptions[$curServico][$curNbs])) {
                $rowNbsOptions[$curServico][$curNbs] = $curNbs . ' - Valor atual fora do catálogo';
            }

            echo '<tr>';
            echo '<td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>';

            echo '<td>';
            echo '<select class="form-control nfse-servico" name="codigo_servico[' . (int) $gid . ']" data-gid="' . (int) $gid . '" style="max-width:240px;">';
            foreach ($rowServicoOptions as $val => $label) {
                $sel = $val === $curServico ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
            }
            echo '</select>';
            echo '</td>';

            echo '<td>';
            echo '<select class="form-control nfse-nbs" name="nbs[' . (int) $gid . ']" data-gid="' . (int) $gid . '" style="min-width:520px;max-width:780px;">';
            echo '</select>';
            echo '<div class="help-block nfse-nbs-help" data-gid="' . (int) $gid . '" style="margin:4px 0 0 0;">';
            echo '</div>';
            echo '</td>';

            echo '</tr>';

            echo '<input type="hidden" class="nfse-cur-servico" data-gid="' . (int) $gid . '" value="' . htmlspecialchars($curServico, ENT_QUOTES, 'UTF-8') . '" />';
            echo '<input type="hidden" class="nfse-cur-nbs" data-gid="' . (int) $gid . '" value="' . htmlspecialchars($curNbs, ENT_QUOTES, 'UTF-8') . '" />';
        }

        echo '</table>';
        echo '<p><button type="submit" class="btn btn-primary">Salvar</button></p>';
        echo '</form>';

        $mergedNbsOptions = $nbsOptions;
        foreach ($map as $currentRow) {
            $curServico = $currentRow['codigo_servico'] !== null ? (string) $currentRow['codigo_servico'] : '';
            $curNbs = $currentRow['nbs'] !== null ? (string) $currentRow['nbs'] : '';
            if ($curServico === '') {
                continue;
            }
            if (!isset($mergedNbsOptions[$curServico])) {
                $mergedNbsOptions[$curServico] = [];
            }
            if ($curNbs !== '' && !isset($mergedNbsOptions[$curServico][$curNbs])) {
                $mergedNbsOptions[$curServico][$curNbs] = $curNbs . ' - Valor atual fora do catálogo';
            }
        }

        $jsNbsOptions = json_encode($mergedNbsOptions, JSON_UNESCAPED_UNICODE);
        $jsDefaultServico = json_encode($defaultServico, JSON_UNESCAPED_UNICODE);
        $jsDefaultNbs = json_encode($defaultNbs, JSON_UNESCAPED_UNICODE);

        echo '<script>(function(){var nbsOptions=' . $jsNbsOptions . ';var defaultServico=' . $jsDefaultServico . ';var defaultNbs=' . $jsDefaultNbs . ';function curVal(cls,gid){var el=document.querySelector(cls+\'[data-gid="\'+gid+\'"]\');return el?el.value:\'\';}function fill(gid){var servEl=document.querySelector(\'.nfse-servico[data-gid="\'+gid+\'"]\');var nbsEl=document.querySelector(\'.nfse-nbs[data-gid="\'+gid+\'"]\');var help=document.querySelector(\'.nfse-nbs-help[data-gid="\'+gid+\'"]\');if(!servEl||!nbsEl){return;}var serv=servEl.value||\'\';var curNbs=curVal(\'.nfse-cur-nbs\',gid);nbsEl.innerHTML=\'\';if(serv===\'\'){var opt=document.createElement(\'option\');opt.value=\'\';opt.textContent=\'Padrão\';nbsEl.appendChild(opt);nbsEl.disabled=true;var s=defaultServico?defaultServico:\'-\';var n=defaultNbs?defaultNbs:\'-\';if(help){help.textContent=\'Usará: Código \' + s + \' e NBS \' + n; }return;}nbsEl.disabled=false;var opts=nbsOptions[serv]||{};var first=\'\';for(var k in opts){if(!opts.hasOwnProperty(k)){continue;}if(first===\'\'){first=k;}var o=document.createElement(\'option\');o.value=k;o.textContent=opts[k];if(curNbs!==\'\'&&curNbs===k){o.selected=true;}nbsEl.appendChild(o);}if(nbsEl.options.length===0){var o2=document.createElement(\'option\');o2.value=\'\';o2.textContent=\'(Sem opções)\' ;nbsEl.appendChild(o2);nbsEl.disabled=true;}else if(curNbs===\'\'){nbsEl.value=first;}if(help){help.textContent=\'\';}}var servs=document.querySelectorAll(\'.nfse-servico\');for(var i=0;i<servs.length;i++){(function(el){var gid=el.getAttribute(\'data-gid\');fill(gid);el.addEventListener(\'change\',function(){var h=document.querySelector(\'.nfse-cur-nbs[data-gid="\'+gid+\'"]\');if(h){h.value=\'\';}fill(gid);});})(servs[i]);}})();</script>';

        Module::ui()->renderFooter();
    }


    public function saveCodigosProdutosServicos(): void
    {
        $repo = new GroupServiceCodeRepository();

        $servicos = $_POST['codigo_servico'] ?? [];
        $nbs = $_POST['nbs'] ?? [];
        if (!is_array($servicos) || !is_array($nbs)) {
            Module::ui()->renderError('Parâmetros inválidos.');
            return;
        }

        $catalogRepo = new ServiceNbsCatalogRepository();
        $allowedServico = array_fill_keys(array_keys($catalogRepo->getServiceOptions(true)), true);
        $allowedNbs = [];
        foreach ($catalogRepo->getNbsOptionsByServiceCode() as $codigoServico => $nbsOptions) {
            $allowedNbs[$codigoServico] = array_fill_keys(array_keys($nbsOptions), true);
        }

        foreach ($servicos as $gidRaw => $servRaw) {
            $gid = (int) $gidRaw;
            if ($gid <= 0) {
                continue;
            }

            $serv = preg_replace('/\D/', '', (string) $servRaw);
            if (!isset($allowedServico[$serv])) {
                Module::ui()->renderError('Código de serviço inválido para o grupo ' . $gid . '.');
                return;
            }

            if ($serv === '') {
                $repo->delete($gid);
                continue;
            }

            $nbsRaw = (string) ($nbs[$gidRaw] ?? '');
            $nbsDigits = preg_replace('/\D/', '', $nbsRaw);
            if ($nbsDigits === '' || !isset($allowedNbs[$serv][$nbsDigits])) {
                Module::ui()->renderError('NBS inválida para o grupo ' . $gid . '.');
                return;
            }

            $repo->upsert($gid, $serv, $nbsDigits);
        }

        header('Location: addonmodules.php?module=OpenNfse&action=config&tab=codigos&codes_saved=1');
        exit;
    }

}
