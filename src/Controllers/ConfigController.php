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
use OpenNfse\Services\DpsLayoutService;
use OpenNfse\Services\EmissionPolicyService;
use OpenNfse\Services\IbgeService;
use OpenNfse\Services\InvoiceEmailService;
use OpenNfse\Services\NfseService;
use OpenNfse\Services\QueueErrorClassifierService;
use OpenNfse\Services\QueueService;
use OpenNfse\Services\StorageService;
use OpenNfse\Services\TokenService;
use OpenNfse\Services\TomadorBairroService;
use OpenNfse\Services\UpdateCheckService;
use OpenNfse\Views\Ui;
use WHMCS\Database\Capsule;
use OpenNfse\Controllers\Support\AdminHelpersTrait;

final class ConfigController
{
    use AdminHelpersTrait;

    private const AUTO_EMIT_VALUE_WARNING = 'A NFS-e emitida na geração usa o valor da fatura naquele momento. '
        . 'Se o valor da fatura mudar depois, por taxa, juros ou multa acrescentados no pagamento (ex.: o PagHiper '
        . 'inclui a taxa na fatura só depois do pagamento) ou por desconto de antecipação, ele vai ficar diferente '
        . 'do valor da NFS-e, e o alerta "Fatura alterada com NFS-e" será aberto. Como não é possível saber de '
        . 'antemão qual gateway o cliente vai usar, e cada gateway pode ter uma taxa diferente, recomendamos NÃO '
        . 'repassar taxas ao cliente nos gateways usados com a emissão na geração.';

    /** Valores aceitos pelo TSRegEspTrib do leiaute nacional 1.01. */
    private const REG_ESP_TRIB_VALIDOS = ['0', '1', '2', '3', '4', '5', '6', '9'];

    private SequenciaisController $sequenciaisController;

    public function __construct(?SequenciaisController $sequenciaisController = null)
    {
        $this->sequenciaisController = $sequenciaisController ?? new SequenciaisController();
    }

    public function showConfig(): void
    {
        $repo = new ConfigRepository();
        $config = $repo->get();

        Module::ui()->renderHeader('Configuração - OpenNfse');
        $this->renderTabs('config');
        echo '<div class="nfse-ui nfse-page nfse-cfg" id="nfse-config">';
        if ((string) ($_GET['saved'] ?? '') === '1') {
            echo Ui::callout('ok', 'Configuração salva.');
        }

        $activeTab = (string) ($_GET['tab'] ?? 'ambiente');
        // Seções agrupadas no submenu (Empresa, Fiscal, Integração, Documentos, Sistema).
        $tabMeta = [
            'ambiente' => [
                'group' => 'Empresa',
                'label' => 'Ambiente',
                'title' => 'Ambiente e certificado',
                'description' => 'Ambiente de operação e o certificado digital usado nas requisições.',
            ],
            'prestador' => [
                'group' => 'Empresa',
                'label' => 'Prestador de serviços',
                'title' => 'Dados do prestador',
                'description' => 'Identificação fiscal e dados cadastrais do emissor da NFS-e.',
            ],
            'endereco' => [
                'group' => 'Empresa',
                'label' => 'Endereço do prestador',
                'title' => 'Endereço do prestador',
                'description' => 'Endereço padrão enviado na identificação do prestador.',
            ],
            'servicosnbs' => [
                'group' => 'Fiscal',
                'label' => 'Serviços e NBS',
                'title' => 'Código de serviços e NBS',
                'description' => 'Catálogo de códigos de serviço e NBS usado nas demais configurações.',
            ],
            'tributacao' => [
                'group' => 'Fiscal',
                'label' => 'Tributação',
                'title' => 'Tributação e regimes',
                'description' => 'Código de serviço, NBS, alíquota e parâmetros tributários padrão.',
            ],
            'sequenciais' => [
                'group' => 'Fiscal',
                'label' => 'Sequenciais',
                'title' => 'Sequenciais de DPS',
                'description' => 'Sequência numérica usada na geração dos DPS.',
            ],
            'codigos' => [
                'group' => 'Fiscal',
                'label' => 'Códigos por produto',
                'title' => 'Códigos por grupo de produto',
                'description' => 'Código de serviço e NBS usados para cada grupo de produtos do WHMCS.',
            ],
            'integracao' => [
                'group' => 'Integração',
                'label' => 'WHMCS e gateways',
                'title' => 'Integração com o WHMCS',
                'description' => 'Custom fields, aviso na área do cliente e gateways que ativam a emissão automática.',
            ],
            'tomador' => [
                'group' => 'Integração',
                'label' => 'Dados do tomador',
                'title' => 'Dados padrão do tomador',
                'description' => 'Valores usados quando o cadastro do cliente vier incompleto.',
            ],
            'processamento' => [
                'group' => 'Integração',
                'label' => 'Fila e processamento',
                'title' => 'Fila e processamento',
                'description' => 'Automação de emissão, fila, cron e intervalo de consulta de status.',
            ],
            'danfse' => [
                'group' => 'Documentos',
                'label' => 'PDF DANFS-e',
                'title' => 'PDF DANFS-e',
                'description' => 'Cabeçalho do PDF e dados institucionais exibidos no DANFS-e.',
            ],
            'atualizacoes' => [
                'group' => 'Sistema',
                'label' => 'Atualizações',
                'title' => 'Atualizações',
                'description' => 'Versão instalada e novas versões publicadas no GitHub.',
            ],
            'retencao' => [
                'group' => 'Sistema',
                'label' => 'Retenção e logs',
                'title' => 'Retenção e logs',
                'description' => 'Por quantos dias a fila concluída e os logs ficam guardados.',
            ],
        ];
        $allowedTabs = [];
        foreach ($tabMeta as $key => $meta) {
            $allowedTabs[$key] = (string) ($meta['label'] ?? $key);
        }
        if (!isset($allowedTabs[$activeTab])) {
            $activeTab = 'ambiente';
        }
        // Seções com formulário próprio: a barra "Salvar alterações" do formulário principal fica oculta nelas.
        $standaloneTabs = ['codigos', 'sequenciais', 'servicosnbs', 'danfse', 'atualizacoes'];

        $msg = (string) ($_GET['msg'] ?? '');
        if ($msg === 'ibge_sync_ok') {
            $total = (int) ($_GET['total'] ?? 0);
            echo Ui::callout('ok', 'Base de municípios IBGE sincronizada com sucesso' . ($total > 0 ? ' (' . $total . ' registros).' : '.'));
        } elseif ($msg === 'ibge_sync_error') {
            echo Ui::callout('danger', 'Não foi possível sincronizar a base de municípios IBGE.', 'Tente novamente em alguns instantes.');
        } elseif ($msg === 'ibge_sync_pending_approval') {
            echo Ui::callout('danger', 'A fonte primária mudou desde o último hash aprovado.', 'Revise os dados exibidos e aprove o novo hash antes de sincronizar.');
        } elseif ($msg === 'ibge_pin_ok') {
            $total = (int) ($_GET['total'] ?? 0);
            echo Ui::callout('ok', 'Novo hash da fonte primária aprovado e base sincronizada com sucesso' . ($total > 0 ? ' (' . $total . ' registros).' : '.'));
        } elseif ($msg === 'ibge_pin_error') {
            echo Ui::callout('danger', 'Não foi possível aprovar o novo hash da fonte primária.', 'Tente novamente em alguns instantes.');
        } elseif ($msg === 'update_check_ok') {
            echo Ui::callout('ok', 'Verificação de atualizações concluída com sucesso.');
        } elseif ($msg === 'update_check_error') {
            echo Ui::callout('danger', 'Não foi possível verificar atualizações do módulo no momento.');
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
        $updateStatus = (new UpdateCheckService())->getStatus($config);
        $tabStatuses = [];
        foreach (array_keys($allowedTabs) as $key) {
            $tabStatuses[$key] = $this->getConfigTabStatusMeta($key, $config);
        }
        // Submenu: ponto verde (ok) ou cinza (neutro); só o que pede atenção leva o texto do status.
        $navItems = [];
        $lastGroup = '';
        foreach ($allowedTabs as $key => $label) {
            $group = (string) ($tabMeta[$key]['group'] ?? '');
            if ($group !== '' && $group !== $lastGroup) {
                $navItems[] = ['group' => $group];
                $lastGroup = $group;
            }
            $status = $tabStatuses[$key] ?? $this->getConfigTabStatusMeta($key, $config);
            $tone = $this->configStatusTone($status);
            $item = [
                'label' => $label,
                'href' => $configUrl($key),
                'current' => $key === $activeTab,
            ];
            if ($tone === 'warn') {
                $item['pill'] = (string) ($status['label'] ?? '');
                $item['tone'] = 'warn';
            } else {
                $item['dot'] = $tone === 'ok' ? 'ok' : 'neutral';
            }
            $navItems[] = $item;
        }

        echo '<div class="nfse-withnav">';
        echo Ui::subnav($navItems, 'Seções da configuração');
        echo '<div class="nfse-withnav__content">';

        $paneStart = function (string $key) use ($activeTab, $tabMeta, $tabStatuses): void {
            echo '<section class="nfse-config-tab nfse-cfg-pane" data-tab="' . Ui::h($key) . '" aria-labelledby="nfse-cfg-h-' . Ui::h($key) . '"' . ($key === $activeTab ? '' : ' hidden') . '>';
            $this->renderConfigPaneHeader((string) $tabMeta[$key]['title'], (string) $tabMeta[$key]['description'], $tabStatuses[$key] ?? [], 'nfse-cfg-h-' . $key);
        };
        $paneEnd = static function (): void {
            echo '</section>';
        };

        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=saveConfig" class="nfse-cfg-form">';
        $token = (new TokenService())->token();
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . Ui::h($token) . '" />';
        }
        echo '<input type="hidden" name="active_config_tab" id="nfse-active-config-tab" value="' . Ui::h($activeTab) . '" />';

        $paneStart('ambiente');
        $msg = (string) ($_REQUEST['msg'] ?? '');
        if ($msg === 'cert_ok') {
            echo Ui::callout('ok', 'Certificado validado com sucesso.');
        } elseif ($msg === 'cert_critical') {
            echo Ui::callout('danger', 'Certificado validado, mas está em prazo crítico de renovação.', 'Recomendado renovar imediatamente e agendar a validação presencial.');
        } elseif ($msg === 'cert_expiring') {
            echo Ui::callout('warn', 'Certificado validado, mas está próximo de expirar.', 'Verifique o prazo e programe a renovação.');
        } elseif ($msg === 'cert_expired') {
            echo Ui::callout('danger', 'O certificado está expirado.', 'Atualize o arquivo e a senha antes de usar o módulo.');
        } elseif ($msg === 'cert_error') {
            echo Ui::callout('danger', 'Não foi possível validar o certificado.', 'Verifique o arquivo e a senha.');
        } elseif ($msg === 'conn_ok') {
            $ms = trim((string) ($_REQUEST['ms'] ?? ''));
            echo Ui::callout('ok', 'Conexão validada com sucesso' . ($ms !== '' ? ' (' . $ms . 'ms).' : '.'));
        } elseif ($msg === 'conn_error') {
            echo Ui::callout('danger', 'Falha ao testar a conexão com a API.', 'Verifique certificado, ambiente e conectividade do servidor.');
        } elseif ($msg === 'conn_sdk_missing') {
            echo Ui::callout('danger', 'SDK não disponível no servidor.', 'Garanta que o vendor do módulo esteja instalado e carregado.');
        }
        $this->renderConfigSectionStart('Ambiente de operação', 'Mantenha alinhado com o certificado e os testes realizados.');
        $this->renderConfigFormTableStart();
        $this->renderSelectRow('environment', 'Ambiente', [
            'homologacao' => 'Homologação',
            'producao' => 'Produção',
        ], $config['environment'] ?? 'homologacao');
        $this->renderSelectRow(
            'dps_leiaute',
            'Leiaute da DPS',
            DpsLayoutService::modeOptions(),
            DpsLayoutService::normalizeMode((string) ($config['dps_leiaute'] ?? DpsLayoutService::MODE_AUTO))
        );
        $this->renderConfigFormTableEnd();
        $this->renderConfigBlock('Leiaute ativo agora: <strong>' . Ui::h((new DpsLayoutService())->describe($config))
            . '</strong>. Depois de mudar o ambiente ou o leiaute, salve para atualizar esta informação.', 'nfse-cfg-note');
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('Certificado digital', 'Arquivo A1 e senha usados para autenticar o emissor na API.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('certificate_path', 'Caminho do Certificado A1 (.pfx)', $config['certificate_path'] ?? '');
        $this->renderPasswordRow('certificate_password', 'Senha do Certificado', '');
        $this->renderConfigFormTableEnd();
        $this->renderConfigBlock('A senha não é exibida após salvar. Preencha novamente apenas quando precisar atualizar o certificado.', 'nfse-cfg-note');
        $this->renderConfigSectionEnd();

        $certInfo = $this->evaluateCertificateFromConfig($config);
        $certActions = Ui::button('Revalidar certificado', ['sm'], ['formaction' => 'addonmodules.php?module=OpenNfse&action=validateCertificate', 'formmethod' => 'post']);
        if (class_exists(\Nfse\Nfse::class)) {
            $certActions .= Ui::button('Testar conexão', ['sm'], ['formaction' => 'addonmodules.php?module=OpenNfse&action=testConnection', 'formmethod' => 'post']);
        }
        $this->renderConfigSectionStart('Status do certificado', 'Validade e leitura do certificado configurado.', $certActions);
        $statusLine = Ui::badge((string) ($certInfo['status_label'] ?? 'Revisar'), $this->configStatusTone($certInfo));
        $summary = trim((string) ($certInfo['summary'] ?? ''));
        if ($summary !== '') {
            $statusLine .= '<span>' . Ui::h($summary) . '</span>';
        }
        $facts = [];
        $validTo = (string) ($certInfo['valid_to'] ?? '');
        $daysLeft = (string) ($certInfo['days_left'] ?? '');
        if ($validTo !== '') {
            $facts['Válido até'] = Ui::h($validTo) . ($daysLeft !== '' ? ' <span class="nfse-muted">(' . Ui::h($daysLeft) . ' dias)</span>' : '');
        }
        foreach (['subject' => 'Subject', 'issuer' => 'Issuer'] as $key => $label) {
            $value = trim((string) ($certInfo[$key] ?? ''));
            if ($value !== '') {
                $facts[$label] = Ui::h($value);
            }
        }
        $fingerprint = trim((string) ($certInfo['fingerprint'] ?? ''));
        if ($fingerprint !== '') {
            $facts['Fingerprint'] = '<span class="nfse-mono">' . Ui::h($fingerprint) . '</span>';
        }
        $this->renderConfigBlock('<div class="nfse-cfg-status">' . $statusLine . '</div>' . self::factsHtml($facts));
        $this->renderConfigBlock('Use Revalidar certificado após trocar o arquivo no servidor. O teste de conexão usa uma consulta leve via SDK para validar mTLS e acesso à API, sem emitir NFS-e.', 'nfse-cfg-note');
        $this->renderConfigSectionEnd();
        $paneEnd();

        $paneStart('prestador');
        $this->renderConfigSectionStart('Identificação fiscal', 'Dados cadastrais do emissor enviados na NFS-e.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('cnpj_emissor', 'CNPJ do Emissor', $config['cnpj_emissor'] ?? '');
        $this->renderSelectRow('prestador_informar_im', 'Informar IM do Prestador?', [
            '1' => 'Sim',
            '0' => 'Não',
        ], (string) ($config['prestador_informar_im'] ?? '1'));
        $this->renderTextRow('inscricao_municipal', 'Inscrição Municipal', $config['inscricao_municipal'] ?? '');
        $this->renderConfigFormTableEnd();
        $this->renderConfigBlock(Ui::callout(
            'info',
            'Indicador Municipal',
            'A Inscrição Municipal deve ser igual ao Indicador Municipal do Portal do Contribuinte, com os zeros à esquerda.',
            '<details class="nfse-form__more"><summary>Onde conferir</summary><div class="nfse-form__more-body">'
            . '<p>Simule a emissão no portal e veja o campo <strong>Indicador Municipal</strong>.</p>'
            . '<p>Homologação: <a href="https://www.producaorestrita.nfse.gov.br/EmissorNacional/" target="_blank" rel="noopener noreferrer">https://www.producaorestrita.nfse.gov.br/EmissorNacional/</a><br />'
            . 'Produção: <a href="https://www.nfse.gov.br/EmissorNacional/" target="_blank" rel="noopener noreferrer">https://www.nfse.gov.br/EmissorNacional/</a></p>'
            . '<p>Se ainda não houver Indicador Municipal no portal, altere o campo <strong>Informar IM do Prestador</strong> para <strong>Não</strong>.</p>'
            . '</div></details>'
        ));
        $this->renderConfigFormTableStart();
        $this->renderTextRow('codigo_ibge', 'Código IBGE', $config['codigo_ibge'] ?? '');
        $this->renderTextRow('prestador_nome', 'Razão Social do Prestador (xNome)', $config['prestador_nome'] ?? '');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('Contato do prestador', 'E-mail e telefone de referência do emissor.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('prestador_email', 'E-mail do Prestador', $config['prestador_email'] ?? '');
        $this->renderTextRow('prestador_fone', 'Telefone do Prestador', $config['prestador_fone'] ?? '');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $paneEnd();

        $paneStart('endereco');
        $this->renderConfigSectionStart('Endereço cadastral', 'Igual ao cadastro fiscal, para evitar rejeições por inconsistência.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('prestador_logradouro', 'Logradouro do Prestador', $config['prestador_logradouro'] ?? '');
        $this->renderTextRow('prestador_numero', 'Número do Prestador', $config['prestador_numero'] ?? '');
        $this->renderTextRow('prestador_complemento', 'Complemento do Prestador', $config['prestador_complemento'] ?? '');
        $this->renderTextRow('prestador_bairro', 'Bairro do Prestador', $config['prestador_bairro'] ?? '');
        $this->renderTextRow('prestador_cep', 'CEP do Prestador', $config['prestador_cep'] ?? '');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $paneEnd();

        $paneStart('integracao');
        $this->renderConfigSectionStart('Integração base', 'Onde o WHMCS guarda o CPF/CNPJ do tomador.');
        $this->renderConfigFormTableStart();
        $this->renderCustomFieldSelectRow('tomador_cpfcnpj_customfield_id', 'Custom Field (CPF/CNPJ do Tomador)', (string) ($config['tomador_cpfcnpj_customfield_id'] ?? ''));
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('Área do cliente', 'Aviso exibido no topo da lista de NFS-e do cliente.');
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
        $gatewayActions = '';
        if (!empty($gateways)) {
            $gatewayActions = Ui::button('Marcar todos', ['sm'], ['type' => 'button', 'id' => 'nfse-mark-all-gateways'])
                . Ui::button('Desmarcar todos', ['sm'], ['type' => 'button', 'id' => 'nfse-unmark-all-gateways']);
        }
        $this->renderConfigSectionStart(
            'Gateways habilitados',
            'Meios de pagamento que disparam a emissão automática. ' . $enabledGatewayCount . ' de ' . count($gateways) . ' habilitados.',
            $gatewayActions,
            true
        );
        if (empty($gateways)) {
            $this->renderConfigBlock('Nenhum gateway ativo encontrado.', 'nfse-cfg-note');
        } else {
            echo '<div class="nfse-table-wrap"><table class="nfse-table">';
            echo '<thead><tr><th scope="col">Gateway</th><th scope="col">Nome</th><th scope="col">Emissão de NFS-e</th><th scope="col">Emitir ao gerar a fatura</th></tr></thead><tbody>';
            foreach ($gateways as $g) {
                $gateway = (string) ($g['gateway'] ?? '');
                if ($gateway === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $gateway)) {
                    continue;
                }
                $name = (string) ($g['name'] ?? $gateway);
                $checked = $gatewaySettingsRepo->isEnabled($gateway) ? ' checked' : '';
                $checkedOnCreate = $gatewaySettingsRepo->isEmitOnCreate($gateway) ? ' checked' : '';
                echo '<tr>';
                echo '<td class="nfse-break"><span class="nfse-mono">' . Ui::h($gateway) . '</span></td>';
                echo '<td class="nfse-break">' . Ui::h($name) . '</td>';
                echo '<td><label class="nfse-check"><input type="checkbox" class="nfse-gateway-check" name="nfse_gateway_enabled[]" value="' . Ui::h($gateway) . '"' . $checked . ' /> <span>Ativado</span></label></td>';
                echo '<td><label class="nfse-check"><input type="checkbox" name="nfse_gateway_emit_on_create[]" value="' . Ui::h($gateway) . '"' . $checkedOnCreate . ' /> <span>Sim</span></label></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            $this->renderConfigBlock(
                '<details class="nfse-form__more"><summary>Como funciona</summary><div class="nfse-form__more-body">'
                . '<p>Gateway desativado: o módulo não enfileira a NFS-e quando a fatura é paga com ele.</p>'
                . '<p>"Emitir ao gerar a fatura" só vale com o gatilho "gerada" (em Fila e processamento ou no custom field do cliente): '
                . 'a NFS-e entra na fila assim que a fatura Unpaid é publicada, só nos gateways marcados aqui (ex.: só boleto) e com a emissão ativada. '
                . 'O comportamento no pagamento não muda.</p></div></details>'
            );
            $this->renderConfigBlock(Ui::callout(
                'warn',
                'Valor da NFS-e x valor pago',
                'Na emissão ao gerar a fatura, taxas ou descontos aplicados depois deixam o valor pago diferente do valor da NFS-e.',
                '<details class="nfse-form__more"><summary>Saiba mais</summary><div class="nfse-form__more-body">' . Ui::h(self::AUTO_EMIT_VALUE_WARNING) . '</div></details>'
            ));
        }
        $this->renderConfigSectionEnd();
        $paneEnd();

        $paneStart('tomador');
        $this->renderConfigSectionStart('Fallbacks para o tomador', 'Usados só quando o cadastro do cliente não trouxer a informação.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('tomador_codigo_ibge_padrao', 'Código IBGE do Tomador (padrão)', $config['tomador_codigo_ibge_padrao'] ?? ($config['codigo_ibge'] ?? ''));
        $this->renderTextRow('tomador_numero_padrao', 'Número do Tomador (padrão)', $config['tomador_numero_padrao'] ?? 'S/N');
        $this->renderSelectRow('tomador_bairro_origem', 'Origem do Bairro do Tomador', TomadorBairroService::origens(), TomadorBairroService::origem($config));
        $this->renderCustomFieldSelectRow('tomador_bairro_customfield_id', 'Custom Field (Bairro do Tomador)', (string) ($config['tomador_bairro_customfield_id'] ?? ''));
        $this->renderTextRow('tomador_bairro_padrao', 'Bairro do Tomador (padrão)', $config['tomador_bairro_padrao'] ?? '');
        $this->renderConfigFormTableEnd();
        echo '<script>(function(){'
            . 'var origem=document.querySelector(\'select[name="tomador_bairro_origem"]\');'
            . 'var cf=document.querySelector(\'select[name="tomador_bairro_customfield_id"]\');'
            . 'var row=cf?cf.closest(".nfse-form__row"):null;'
            . 'function sync(){'
            . 'var on=!!origem&&origem.value==="' . TomadorBairroService::ORIGEM_CUSTOMFIELD . '";'
            . 'if(row){row.style.display=on?"":"none";}'
            . 'if(cf){cf.disabled=!on;}'
            . '}'
            . 'if(origem){origem.addEventListener("change",sync);}'
            . 'sync();'
            . '})();</script>';
        $this->renderConfigSectionEnd();

        $this->renderConfigSectionStart(
            'Municípios IBGE',
            'Base local de municípios usada para resolver códigos e gerar o PDF. Se a fonte mudar, o novo hash precisa ser aprovado antes de sincronizar.',
            Ui::button('Sincronizar agora', ['sm'], ['formaction' => 'addonmodules.php?module=OpenNfse&action=syncIbgeMunicipios', 'formmethod' => 'post']),
            true
        );
        if (is_array($ibgeCatalogStatus) && is_array($configuredMunicipioStatus) && is_array($viaCepStatus)) {
            $catalogTone = match ((string) ($ibgeCatalogStatus['status'] ?? '')) {
                'consistent' => 'ok',
                'unpinned' => 'emitida',
                'divergent', 'local_only' => 'warn',
                default => 'danger',
            };
            $municipioTone = match ((string) ($configuredMunicipioStatus['status'] ?? '')) {
                'ok' => 'ok',
                'missing' => 'warn',
                default => 'danger',
            };
            $viaCepTone = match ((string) ($viaCepStatus['status'] ?? '')) {
                'ok' => 'ok',
                'warning', 'missing' => 'warn',
                default => 'danger',
            };
            $shortHash = static fn (string $hash): string => $hash !== '' ? '<span class="nfse-mono">' . Ui::h(substr($hash, 0, 16)) . '...</span>' : '—';
            $formatDate = static function (string $value): string {
                $value = trim($value);
                return $value !== '' ? date('d/m/Y H:i', strtotime($value) ?: time()) : 'Nunca';
            };
            $catalogFacts = [
                'URL' => '<span class="nfse-mono">' . Ui::h((string) ($ibgeCatalogStatus['source_url'] ?? '')) . '</span>',
                'Municípios na URL' => Ui::h($ibgeCatalogStatus['remote_count'] !== null ? (string) $ibgeCatalogStatus['remote_count'] : '—'),
                'Municípios no banco' => Ui::h((string) ($ibgeCatalogStatus['local_count'] ?? 0)),
                'Última sincronização' => Ui::h($formatDate((string) ($ibgeCatalogStatus['last_synced_at'] ?? ''))),
                'Hash aprovado' => $shortHash(trim((string) ($ibgeCatalogStatus['pinned_hash'] ?? ''))),
                'Aprovado em' => Ui::h($formatDate((string) ($ibgeCatalogStatus['pinned_at'] ?? ''))),
            ];
            $catalogHash = trim((string) ($ibgeCatalogStatus['remote_hash'] ?? ''));
            if ($catalogHash !== '') {
                $catalogFacts['Hash remoto atual'] = $shortHash($catalogHash);
            }
            $catalogPendingHash = trim((string) ($ibgeCatalogStatus['pending_hash'] ?? ''));
            if ($catalogPendingHash !== '') {
                $catalogFacts['Hash pendente'] = $shortHash($catalogPendingHash);
                $catalogFacts['Pendente desde'] = Ui::h($formatDate((string) ($ibgeCatalogStatus['pending_checked_at'] ?? '')));
            }
            if ((string) ($ibgeCatalogStatus['error'] ?? '') !== '') {
                $catalogFacts['Erro da fonte'] = '<span class="nfse-text-danger">' . Ui::h((string) $ibgeCatalogStatus['error']) . '</span>';
            }
            $catalogActions = '';
            if (!empty($ibgeCatalogStatus['approval_required'])) {
                $catalogActions = Ui::button('Aprovar novo hash e sincronizar', ['sm'], ['formaction' => 'addonmodules.php?module=OpenNfse&action=approveIbgeHash', 'formmethod' => 'post']);
            }

            echo '<div class="nfse-cfg-grid">';
            echo self::statusPanel('Fonte primária de municípios', Ui::badge((string) ($ibgeCatalogStatus['label'] ?? ''), $catalogTone), (string) ($ibgeCatalogStatus['message'] ?? ''), $catalogFacts, $catalogActions);
            echo self::statusPanel('Validação do Código IBGE', Ui::badge((string) ($configuredMunicipioStatus['label'] ?? ''), $municipioTone), (string) ($configuredMunicipioStatus['message'] ?? ''), [
                'Código configurado' => Ui::h((string) ($configuredMunicipioStatus['ibge_code'] ?? '—')),
                'Município encontrado' => Ui::h((string) ($configuredMunicipioStatus['municipio'] ?? '—')),
            ]);
            echo self::statusPanel('Status do ViaCEP', Ui::badge((string) ($viaCepStatus['label'] ?? ''), $viaCepTone), (string) ($viaCepStatus['message'] ?? ''), [
                'CEP do prestador' => Ui::h((string) ($viaCepStatus['cep'] ?? '—')),
                'IBGE retornado' => Ui::h((string) (($viaCepStatus['ibge'] ?? null) ?: '—')),
                'Localidade' => Ui::h(trim((string) ($viaCepStatus['localidade'] ?? '')) !== '' ? ((string) $viaCepStatus['localidade'] . ' - ' . (string) ($viaCepStatus['uf'] ?? '')) : '—'),
                'Comparação com o prestador' => Ui::h(!empty($viaCepStatus['matches_expected']) ? 'Compatível' : 'Divergente/indisponível'),
            ]);
            echo '</div>';
        }
        $this->renderConfigSectionEnd();
        $paneEnd();

        $paneStart('atualizacoes');
        $this->renderUpdateCheckContent($updateStatus);
        $paneEnd();

        $paneStart('processamento');
        $this->renderConfigSectionStart('Automação da fila', 'Liga a fila e define a regra da emissão manual.');
        $this->renderConfigFormTableStart();
        $this->renderSelectRow('queue_enabled', 'Habilitar fila/cron?', [
            '1' => 'Sim',
            '0' => 'Não',
        ], (string) ($config['queue_enabled'] ?? '0'));
        $this->renderSelectRow('allow_manual_unpaid', 'Permitir enfileirar manualmente a emissão de faturas não pagas?', [
            '1' => 'Sim',
            '0' => 'Não',
        ], (string) ($config['allow_manual_unpaid'] ?? '0'));
        $this->renderTextRow('queue_wait_status_interval_seconds', 'Intervalo de consulta (segundos)', $config['queue_wait_status_interval_seconds'] ?? '120');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('Emissão automática', 'Quando e para quem a NFS-e entra na fila sozinha. O custom field do cliente vale antes do grupo e da regra global.');
        $this->renderAutoEmitPolicyContent($config);
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('E-mail ao cliente', 'Envio automático do PDF e do XML após a autorização.');
        $this->renderConfigFormTableStart();
        $this->renderSelectRow('auto_send_nfse_email', 'Enviar PDF/XML automaticamente por e-mail?', [
            '1' => 'Sim',
            '0' => 'Não',
        ], (string) ($config['auto_send_nfse_email'] ?? '0'));
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $paneEnd();

        $paneStart('retencao');
        $this->renderConfigSectionStart('Política de retenção', 'Prazos curtos reduzem volume sem perder o histórico de auditoria.');
        $this->renderConfigFormTableStart();
        $this->renderTextRow('queue_done_retention_days', 'Reter fila DONE/RESOLVIDO (dias, 0=desativar)', $config['queue_done_retention_days'] ?? '30');
        $this->renderTextRow('logs_retention_days', 'Reter logs (dias, 0=desativar)', $config['logs_retention_days'] ?? '90');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $paneEnd();

        $paneStart('tributacao');
        $this->renderConfigSectionStart('Parâmetros principais', 'Valores base quando não houver regra por grupo de produto.');
        $this->renderConfigFormTableStart();
        $this->renderTributacaoServiceNbsFields($config);
        $this->renderTextRow('aliquota_iss', 'Alíquota ISS (%)', $config['aliquota_iss'] ?? '');
        $this->renderTextRow('serie_dps', 'Série DPS', $config['serie_dps'] ?? '900');
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $this->renderConfigSectionStart('Regimes e enquadramento', 'Enquadramento tributário do prestador no padrão nacional.');
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
        $this->renderTextRow(
            'prestador_reg_esp_trib',
            'Regime Especial (0 a 6 ou 9)',
            $config['prestador_reg_esp_trib'] ?? '0'
        );
        $this->renderConfigFormTableEnd();
        $this->renderConfigSectionEnd();
        $paneEnd();

        echo '<div class="nfse-savebar nfse-savebar--sticky nfse-cfg-savebar nfse-config-save" id="nfse-config-save"' . (in_array($activeTab, $standaloneTabs, true) ? ' hidden' : '') . '>';
        echo '<span class="nfse-savebar__note">Revise os campos da seção atual antes de salvar. As mudanças são aplicadas imediatamente após o envio.</span>';
        echo '<input type="submit" value="Salvar alterações" class="nfse-btn nfse-btn--primary" />';
        echo '</div>';
        echo '</form>';

        $paneStart('servicosnbs');
        $this->renderServiceNbsCatalogContent();
        $paneEnd();

        $paneStart('sequenciais');
        $this->sequenciaisController->renderSequenciaisContent();
        $paneEnd();

        $paneStart('codigos');
        $this->renderCodigosProdutosServicosContent();
        $paneEnd();

        $paneStart('danfse');
        $this->renderDanfseConfigContent($config);
        $paneEnd();

        echo '<script>';
        echo '(function(){';
        echo 'var active=' . json_encode($activeTab, JSON_UNESCAPED_UNICODE) . ';';
        echo 'var standalone=' . json_encode($standaloneTabs) . ';';
        echo 'var root=document.getElementById("nfse-config");';
        echo 'if(!root){return;}';
        echo 'var links=root.querySelectorAll(".nfse-subnav a.nfse-subnav__item");';
        echo 'var panes=root.querySelectorAll(".nfse-config-tab");';
        echo 'var saves=root.querySelectorAll(".nfse-config-save");';
        echo 'var activeInput=document.getElementById("nfse-active-config-tab");';
        echo 'function tabOf(a){var m=/[?&]tab=([^&#]*)/.exec(a.getAttribute("href")||"");return m?decodeURIComponent(m[1]):"";}';
        // Em área estreita o submenu vira uma linha de chips com rolagem: traz a seção ativa para a vista.
        echo 'function reveal(a){var nav=a.parentNode;if(!nav||nav.scrollWidth<=nav.clientWidth){return;}nav.scrollLeft+=a.getBoundingClientRect().left-nav.getBoundingClientRect().left-8;}';
        echo 'function show(tab,updateUrl){';
        echo 'for(var i=0;i<panes.length;i++){panes[i].hidden=panes[i].getAttribute("data-tab")!==tab;}';
        echo 'for(var j=0;j<links.length;j++){if(tabOf(links[j])===tab){links[j].setAttribute("aria-current","page");reveal(links[j]);}else{links[j].removeAttribute("aria-current");}}';
        echo 'for(var s=0;s<saves.length;s++){saves[s].hidden=standalone.indexOf(tab)!==-1;}';
        echo 'if(activeInput){activeInput.value=tab;}';
        echo 'if(updateUrl&&window.history&&window.history.replaceState){window.history.replaceState(null,"",' . json_encode('addonmodules.php?module=OpenNfse&action=config&tab=', JSON_UNESCAPED_UNICODE) . '+encodeURIComponent(tab));}';
        echo '}';
        echo 'for(var k=0;k<links.length;k++){(function(a){a.addEventListener("click",function(e){var tab=tabOf(a);if(!tab){return;}e.preventDefault();show(tab,true);});})(links[k]);}';
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
        echo '</div>';

        Module::ui()->renderFooter();
    }

    /**
     * Lista de dados (rótulo e valor) dos painéis de status. Os valores já vêm em HTML escapado.
     *
     * @param array<string, string> $facts
     */
    private static function factsHtml(array $facts): string
    {
        if ($facts === []) {
            return '';
        }
        $html = '<dl class="nfse-cfg-facts">';
        foreach ($facts as $label => $valueHtml) {
            $html .= '<dt>' . Ui::h((string) $label) . '</dt><dd>' . $valueHtml . '</dd>';
        }

        return $html . '</dl>';
    }

    /**
     * Painel de status (municípios IBGE, ViaCEP): título e badge, mensagem, dados e ações.
     *
     * @param array<string, string> $facts
     */
    private static function statusPanel(string $title, string $badgeHtml, string $message, array $facts, string $actionsHtml = ''): string
    {
        $html = '<div class="nfse-cfg-panel">';
        $html .= '<div class="nfse-cfg-panel__head"><span class="nfse-cfg-panel__title">' . Ui::h($title) . '</span>' . $badgeHtml . '</div>';
        if ($message !== '') {
            $html .= '<div class="nfse-cfg-panel__text">' . Ui::h($message) . '</div>';
        }
        $html .= self::factsHtml($facts);
        if ($actionsHtml !== '') {
            $html .= '<div class="nfse-actions">' . $actionsHtml . '</div>';
        }

        return $html . '</div>';
    }


    /**
     * Campos da política de emissão automática: gatilho global, escopo por grupo de clientes e
     * custom field por cliente.
     */
    private function renderAutoEmitPolicyContent(array $config): void
    {
        $trigger = EmissionPolicyService::resolveGlobalTrigger($config);
        $scope = strtolower(trim((string) ($config['auto_emit_scope'] ?? EmissionPolicyService::SCOPE_TODOS)));
        if (!in_array($scope, EmissionPolicyService::validScopes(), true)) {
            $scope = EmissionPolicyService::SCOPE_TODOS;
        }
        $selectedGroups = EmissionPolicyService::parseGroupIds($config['auto_emit_group_ids'] ?? '');
        $customFieldId = (int) ($config['auto_emit_customfield_id'] ?? 0);

        $this->renderConfigFormTableStart();
        $this->renderSelectRow('auto_emit_trigger', 'Enfileirar emissão automaticamente quando?', [
            EmissionPolicyService::TRIGGER_NUNCA => 'Nunca',
            EmissionPolicyService::TRIGGER_PAGA => 'Quando a fatura for paga',
            EmissionPolicyService::TRIGGER_GERADA => 'Quando a fatura for gerada',
        ], $trigger);
        $this->renderSelectRow('auto_emit_scope', 'Aplicar a regra a quais clientes?', [
            EmissionPolicyService::SCOPE_TODOS => 'Todos os clientes',
            EmissionPolicyService::SCOPE_GRUPOS => 'Só os grupos selecionados',
        ], $scope);

        $groups = $this->listClientGroups();
        if (empty($groups)) {
            $groupsHtml = '<span class="nfse-muted">Nenhum grupo de clientes cadastrado no WHMCS.</span>';
        } else {
            $groupsHtml = '<div class="nfse-cfg-checklist" role="group" aria-label="Grupos de clientes">';
            foreach ($groups as $group) {
                $gid = (int) ($group['id'] ?? 0);
                $checked = in_array($gid, $selectedGroups, true) ? ' checked' : '';
                $groupsHtml .= '<label class="nfse-check"><input type="checkbox" name="auto_emit_group_ids[]" value="' . $gid . '"' . $checked . ' /> <span>'
                    . Ui::h((string) ($group['groupname'] ?? '')) . ' <span class="nfse-muted">(#' . $gid . ')</span></span></label>';
            }
            $groupsHtml .= '</div>';
        }
        echo self::rowWithId(
            Ui::formRow('Grupos de clientes', $groupsHtml, 'Clientes fora dos grupos marcados não têm emissão automática, salvo se o custom field do cliente disser o contrário.'),
            'nfse-auto-emit-groups-row'
        );

        $fieldOptions = ['0' => 'Não usar (desligado)'];
        $fieldFound = false;
        foreach ($this->listClientCustomFields() as $field) {
            $fid = (int) ($field['id'] ?? 0);
            $fieldFound = $fieldFound || $fid === $customFieldId;
            $type = (string) ($field['fieldtype'] ?? '');
            $fieldOptions[(string) $fid] = (string) ($field['fieldname'] ?? '') . ' (#' . $fid . ($type !== '' ? ', ' . $type : '') . ')';
        }
        if ($customFieldId > 0 && !$fieldFound) {
            $fieldOptions[(string) $customFieldId] = '#' . $customFieldId . ' (campo não encontrado)';
        }
        $fieldId = $this->configFieldId('auto_emit_customfield_id');
        $fieldHtml = Ui::select('auto_emit_customfield_id', $fieldOptions, (string) $customFieldId, ['id' => $fieldId], 'nfse-cfg-field');
        if ($customFieldId > 0 && !$fieldFound) {
            $fieldHtml .= Ui::callout('warn', EmissionPolicyService::MSG_CUSTOMFIELD_MISSING, 'Selecione outro campo ou "Não usar (desligado)".');
        }
        $fieldHtml .= '<div class="nfse-form__help">Um campo do cliente pode sobrepor a regra do grupo e a global.</div>';
        $fieldHtml .= '<details class="nfse-form__more"><summary>Como criar o campo personalizado</summary><div class="nfse-form__more-body">';
        $fieldHtml .= '<div class="nfse-form__help">Crie o campo em Configurações &gt; Campos personalizados do cliente (Setup &gt; Custom Client Fields) com <strong>Field Type: Drop Down</strong>, marque <strong>Admin Only</strong> (para o cliente não alterar) e use exatamente estas opções, separadas por vírgula:</div>';
        $fieldHtml .= '<div class="nfse-cfg-code">' . Ui::h(implode(',', array_keys(EmissionPolicyService::customFieldOptions()))) . '</div>';
        $fieldHtml .= '<div class="nfse-form__help">"' . Ui::h(EmissionPolicyService::CF_NAO_CONFIGURADO) . '" segue o grupo e a regra global. "'
            . Ui::h(EmissionPolicyService::CF_NAO_EMITIR) . '" e "' . Ui::h(EmissionPolicyService::CF_APENAS_MANUAL) . '" desligam a emissão automática do cliente; a emissão manual continua seguindo a opção de faturas não pagas acima. "'
            . Ui::h(EmissionPolicyService::CF_APENAS_PAGAS) . '" emite no pagamento e "' . Ui::h(EmissionPolicyService::CF_TODAS) . '" emite na geração (e no pagamento, se ainda não houver NFS-e). Valor vazio ou diferente dessas opções é tratado como "'
            . Ui::h(EmissionPolicyService::CF_NAO_CONFIGURADO) . '" e gera um aviso no log.</div>';
        $fieldHtml .= '</div></details>';
        echo Ui::formRow('Enfileirar emissão de acordo com o custom field do cliente', $fieldHtml, '', ['id' => $fieldId]);
        $this->renderConfigFormTableEnd();

        echo '<div id="nfse-auto-emit-fiscal-warning" class="nfse-cfg-block">';
        echo Ui::callout(
            'warn',
            'Atenção fiscal (emissão na geração da fatura)',
            'A NFS-e sai antes do pagamento e o ISS é devido mesmo se a fatura não for paga. Confirme com a contabilidade antes de ligar.',
            '<details class="nfse-form__more"><summary>Saiba mais</summary><div class="nfse-form__more-body">'
                . '<p>A competência é a data de emissão e o prazo do município para cancelar a NFS-e é curto. '
                . 'Se a fatura for cancelada ou alterada depois, o cancelamento da NFS-e continua manual. '
                . 'Na geração, a emissão só vale para os gateways marcados em "Emitir ao gerar a fatura" (WHMCS e gateways).</p>'
                . '<p><strong>Valor da NFS-e x valor pago:</strong> ' . Ui::h(self::AUTO_EMIT_VALUE_WARNING) . '</p></div></details>'
        );
        echo '</div>';

        echo '<script>(function(){'
            . 'var trig=document.querySelector(\'select[name="auto_emit_trigger"]\');'
            . 'var scope=document.querySelector(\'select[name="auto_emit_scope"]\');'
            . 'var cf=document.querySelector(\'select[name="auto_emit_customfield_id"]\');'
            . 'var warn=document.getElementById("nfse-auto-emit-fiscal-warning");'
            . 'var groups=document.getElementById("nfse-auto-emit-groups-row");'
            . 'function sync(){'
            . 'var t=trig?trig.value:"";'
            . 'if(warn){warn.style.display=(t==="gerada"||(cf&&cf.value!=="0"))?"":"none";}'
            . 'if(groups){groups.style.display=(scope&&scope.value==="grupos")?"":"none";}'
            . '}'
            . 'if(trig){trig.addEventListener("change",sync);}'
            . 'if(scope){scope.addEventListener("change",sync);}'
            . 'if(cf){cf.addEventListener("change",sync);}'
            . 'sync();'
            . '})();</script>';
    }

    /**
     * Acrescenta um id ao primeiro elemento (a linha do formulário) para os scripts que mostram ou escondem a linha.
     */
    private static function rowWithId(string $rowHtml, string $id): string
    {
        return preg_replace('/^<div /', '<div id="' . Ui::h($id) . '" ', $rowHtml, 1) ?? $rowHtml;
    }

    /** @return array<int, array{id: int, groupname: string}> */
    private function listClientGroups(): array
    {
        $out = [];
        try {
            $rows = Capsule::table('tblclientgroups')->orderBy('groupname')->get(['id', 'groupname']);
            foreach ($rows as $r) {
                $id = (int) ($r->id ?? 0);
                if ($id > 0) {
                    $out[] = ['id' => $id, 'groupname' => (string) ($r->groupname ?? '')];
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $out;
    }

    /** @return array<int, array{id: int, fieldname: string, fieldtype: string}> */
    private function listClientCustomFields(): array
    {
        $out = [];
        try {
            $rows = Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->orderBy('fieldname')
                ->get(['id', 'fieldname', 'fieldtype']);
            foreach ($rows as $r) {
                $id = (int) ($r->id ?? 0);
                if ($id > 0) {
                    $out[] = ['id' => $id, 'fieldname' => (string) ($r->fieldname ?? ''), 'fieldtype' => (string) ($r->fieldtype ?? '')];
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $out;
    }

    public function saveConfig(): void
    {
        $existingConfig = (new ConfigRepository())->get();
        $environment = (string) ($_POST['environment'] ?? '');
        $dpsLeiaute = trim((string) ($_POST['dps_leiaute'] ?? DpsLayoutService::MODE_AUTO));
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
$allowManualUnpaid = (string) ($_POST['allow_manual_unpaid'] ?? '0');
$queueWaitInterval = (string) ($_POST['queue_wait_status_interval_seconds'] ?? '120');
 $autoSendNfseEmail = (string) ($_POST['auto_send_nfse_email'] ?? '0');
 $queueDoneRetentionDays = (string) ($_POST['queue_done_retention_days'] ?? '30');
        $logsRetentionDays = (string) ($_POST['logs_retention_days'] ?? '90');
        $tomadorCodigoIbgePadrao = (string) ($_POST['tomador_codigo_ibge_padrao'] ?? '');
        $tomadorNumeroPadrao = (string) ($_POST['tomador_numero_padrao'] ?? 'S/N');
        $tomadorBairroPadrao = (string) ($_POST['tomador_bairro_padrao'] ?? '');
        $tomadorBairroOrigem = strtolower(trim((string) ($_POST['tomador_bairro_origem'] ?? TomadorBairroService::ORIGEM_ADDRESS2)));
        // O select fica desabilitado (e não é enviado) fora do modo custom field: mantém o valor salvo.
        $tomadorBairroFieldId = trim((string) ($_POST['tomador_bairro_customfield_id'] ?? (string) ($existingConfig['tomador_bairro_customfield_id'] ?? '')));
        $clientAreaNoticeEnabled = (string) ($_POST['client_area_notice_enabled'] ?? '1');
        $clientAreaNoticeType = trim((string) ($_POST['client_area_notice_type'] ?? 'warning'));
        $clientAreaNoticeMessage = trim((string) ($_POST['client_area_notice_message'] ?? ''));
        $nfseGatewayEnabled = $_POST['nfse_gateway_enabled'] ?? [];
        if (!is_array($nfseGatewayEnabled)) {
            $nfseGatewayEnabled = [];
        }
        $nfseGatewayEmitOnCreate = $_POST['nfse_gateway_emit_on_create'] ?? [];
        if (!is_array($nfseGatewayEmitOnCreate)) {
            $nfseGatewayEmitOnCreate = [];
        }
        $autoEmitTrigger = strtolower(trim((string) ($_POST['auto_emit_trigger'] ?? EmissionPolicyService::resolveGlobalTrigger($existingConfig))));
        $autoEmitScope = strtolower(trim((string) ($_POST['auto_emit_scope'] ?? EmissionPolicyService::SCOPE_TODOS)));
        $autoEmitGroupIdsInput = $_POST['auto_emit_group_ids'] ?? [];
        if (!is_array($autoEmitGroupIdsInput)) {
            $autoEmitGroupIdsInput = [];
        }
        $autoEmitCustomFieldId = trim((string) ($_POST['auto_emit_customfield_id'] ?? '0'));
        if ($autoEmitCustomFieldId === '') {
            $autoEmitCustomFieldId = '0';
        }

        $errors = [];
        if ($environment !== 'homologacao' && $environment !== 'producao') {
            $errors[] = 'Ambiente inválido.';
        }
        if (!DpsLayoutService::isValidMode($dpsLeiaute)) {
            $errors[] = 'Leiaute da DPS inválido.';
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
        $prestadorRegEspTrib = trim($prestadorRegEspTrib);
        if ($prestadorRegEspTrib === '') {
            $prestadorRegEspTrib = '0';
        }
        if (!in_array($prestadorRegEspTrib, self::REG_ESP_TRIB_VALIDOS, true)) {
            $errors[] = 'Regime especial inválido: use 0 (Nenhum), 1 (Ato Cooperado), 2 (Estimativa), '
                . '3 (Microempresa Municipal), 4 (Notário ou Registrador), 5 (Profissional Autônomo), '
                . '6 (Sociedade de Profissionais) ou 9 (Outros). '
                . 'Os valores 7, 8 e 10 não existem no leiaute nacional e a nota seria rejeitada.';
        }
        if (!in_array($queueEnabled, ['0', '1'], true)) {
            $errors[] = 'Opção inválida para fila/cron.';
        }
        if (!in_array($autoEmitTrigger, EmissionPolicyService::validTriggers(), true)) {
            $errors[] = 'Opção inválida para emissão automática.';
        }
        if (!in_array($autoEmitScope, EmissionPolicyService::validScopes(), true)) {
            $errors[] = 'Opção inválida para o escopo da emissão automática.';
        }
        $autoEmitGroupIds = EmissionPolicyService::parseGroupIds($autoEmitGroupIdsInput);
        if (count($autoEmitGroupIds) !== count($autoEmitGroupIdsInput)) {
            $errors[] = 'Seleção de grupos de clientes inválida.';
        } elseif (!empty($autoEmitGroupIds)) {
            $knownGroupIds = array_map(static fn ($g): int => (int) ($g['id'] ?? 0), $this->listClientGroups());
            if (array_diff($autoEmitGroupIds, $knownGroupIds) !== []) {
                $errors[] = 'Um ou mais grupos de clientes selecionados não existem mais.';
            }
        }
        if ($autoEmitScope === EmissionPolicyService::SCOPE_GRUPOS && empty($autoEmitGroupIds) && $autoEmitTrigger !== EmissionPolicyService::TRIGGER_NUNCA) {
            $errors[] = 'Selecione ao menos um grupo de clientes para a emissão automática por grupo.';
        }
        if (!ctype_digit($autoEmitCustomFieldId)) {
            $errors[] = 'Custom field da emissão automática inválido.';
        } elseif ((int) $autoEmitCustomFieldId > 0) {
            $knownFieldIds = array_map(static fn ($f): int => (int) ($f['id'] ?? 0), $this->listClientCustomFields());
            if (!in_array((int) $autoEmitCustomFieldId, $knownFieldIds, true)
                && (int) $autoEmitCustomFieldId !== (int) ($existingConfig['auto_emit_customfield_id'] ?? 0)) {
                $errors[] = 'O custom field selecionado para a emissão automática não existe.';
            }
        }
        // Mantido em sincronia para o fallback e para quem voltar a uma versão anterior.
        $autoEmitOnPayment = $autoEmitTrigger === EmissionPolicyService::TRIGGER_NUNCA ? '0' : '1';
if (!in_array($allowManualUnpaid, ['0', '1'], true)) {
$errors[] = 'Opção inválida para emissão manual de faturas não pagas.';
}
if (!in_array($autoSendNfseEmail, ['0', '1'], true)) {
$errors[] = 'Opção inválida para envio automático de e-mail da NFS-e.';
}
        if (($autoEmitOnPayment === '1' || (int) $autoEmitCustomFieldId > 0) && $queueEnabled !== '1') {
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
            $errors[] = 'Informe a retenção de fila DONE/RESOLVIDO (dias) apenas com números.';
        } else {
            $days = (int) $queueDoneRetentionDays;
            if ($days > 3650) {
                $errors[] = 'Retenção de fila DONE/RESOLVIDO inválida (0 a 3650 dias).';
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
        $knownClientFieldIds = array_map(static fn ($f): int => (int) ($f['id'] ?? 0), $this->listClientCustomFields());
        foreach (TomadorBairroService::validateConfig($tomadorBairroOrigem, $tomadorBairroFieldId, $knownClientFieldIds) as $bairroError) {
            $errors[] = $bairroError;
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
            'dps_leiaute' => $dpsLeiaute,
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
            'auto_emit_trigger' => $autoEmitTrigger,
            'auto_emit_scope' => $autoEmitScope,
            'auto_emit_group_ids' => implode(',', $autoEmitGroupIds),
            'auto_emit_customfield_id' => (int) $autoEmitCustomFieldId,
'allow_manual_unpaid' => (int) $allowManualUnpaid,
'auto_send_nfse_email' => (int) $autoSendNfseEmail,
'queue_wait_status_interval_seconds' => (int) $queueWaitInterval,
            'queue_done_retention_days' => (int) $queueDoneRetentionDays,
            'logs_retention_days' => (int) $logsRetentionDays,
            'tomador_codigo_ibge_padrao' => $tomadorCodigoIbgePadrao,
            'tomador_numero_padrao' => $tomadorNumeroPadrao,
            'tomador_bairro_padrao' => $tomadorBairroPadrao,
            'tomador_bairro_origem' => $tomadorBairroOrigem,
            'tomador_bairro_customfield_id' => ctype_digit($tomadorBairroFieldId) && (int) $tomadorBairroFieldId > 0 ? (int) $tomadorBairroFieldId : null,
        ]);

        $activeGateways = (new WhmcsPaymentGatewayRepository())->listActive();
        $gatewayKeys = [];
        foreach ($activeGateways as $g) {
            $gatewayKeys[] = (string) ($g['gateway'] ?? '');
        }
        if (!empty($gatewayKeys)) {
            (new PaymentGatewaySettingsRepository())->saveStatusForGateways($gatewayKeys, $nfseGatewayEnabled, $nfseGatewayEmitOnCreate);
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

    public function checkUpdates(): void
    {
        try {
            (new UpdateCheckService())->checkNow();
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=atualizacoes&msg=update_check_ok');
            exit;
        } catch (\Throwable $e) {
            $configRepo = new ConfigRepository();
            if ($configRepo->get() !== []) {
                $configRepo->save([
                    'update_last_checked_at' => date('Y-m-d H:i:s'),
                    'update_last_status' => 'error',
                    'update_error' => $e->getMessage(),
                ]);
            }
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=atualizacoes&msg=update_check_error');
            exit;
        }
    }

    /**
     * Sub-aba Atualizações: versão instalada e a mais recente, notas da versão (uma vez só) e requisitos.
     * Fica dentro do formulário principal: "Verificar agora" usa formaction.
     */
    private function renderUpdateCheckContent(array $status): void
    {
        $lastCheckedAt = trim((string) ($status['last_checked_at'] ?? ''));
        $lastCheckedDisplay = $lastCheckedAt !== '' ? date('d/m/Y H:i', strtotime($lastCheckedAt) ?: time()) : 'Nunca';
        $downloadUrl = trim((string) ($status['download_url'] ?? ''));
        $changelogUrl = trim((string) ($status['changelog_url'] ?? ''));
        $message = trim((string) ($status['message'] ?? ''));
        $summary = trim((string) ($status['summary'] ?? ''));
        $error = trim((string) ($status['error'] ?? ''));

        $actions = Ui::button('Verificar agora', ['sm'], ['formaction' => 'addonmodules.php?module=OpenNfse&action=checkUpdates', 'formmethod' => 'post']);
        if ($downloadUrl !== '') {
            $actions .= Ui::button('Releases no GitHub', ['sm'], ['href' => $downloadUrl, 'target' => '_blank', 'rel' => 'noopener noreferrer']);
        }
        if ($changelogUrl !== '' && $changelogUrl !== $downloadUrl) {
            $actions .= Ui::button('Changelog', ['sm'], ['href' => $changelogUrl, 'target' => '_blank', 'rel' => 'noopener noreferrer']);
        }

        $this->renderConfigSectionStart('Versão', 'O módulo consulta o GitHub pelo cron, no máximo uma vez por dia.', $actions);
        // O status (Atualizado, Nova versão) já está no cabeçalho da seção; aqui só o resumo, quando acrescenta algo.
        $statusLine = ($summary !== '' && $summary !== $message) ? '<span>' . Ui::h($summary) . '</span>' : '';
        $facts = [
            'Instalada' => Ui::h((string) ($status['current_version'] ?? '—')),
            'Mais recente' => Ui::h((string) ($status['latest_version'] ?? '—')),
            'Última checagem' => Ui::h($lastCheckedDisplay),
        ];
        if ($error !== '' && $error !== $summary) {
            $facts['Erro'] = '<span class="nfse-text-danger">' . Ui::h($error) . '</span>';
        }
        $this->renderConfigBlock(($statusLine !== '' ? '<div class="nfse-cfg-status">' . $statusLine . '</div>' : '') . self::factsHtml($facts));
        $this->renderConfigBlock(
            '<details class="nfse-form__more"><summary>Como funciona</summary><div class="nfse-form__more-body">'
            . 'O cron lê o manifesto <span class="nfse-mono">' . Ui::h((string) ($status['manifest_url'] ?? '')) . '</span> e guarda a última resposta. '
            . 'A instalação continua manual: baixe a versão nova em Releases no GitHub.</div></details>'
        );
        $this->renderConfigSectionEnd();

        if ($message !== '') {
            $this->renderConfigSectionStart('Notas da versão', 'Resumo publicado junto com a versão mais recente.');
            $this->renderConfigBlock('<p class="nfse-cfg-text">' . Ui::h($message) . '</p>');
            $this->renderConfigSectionEnd();
        }

        $minimumWhmcs = trim((string) ($status['minimum_whmcs'] ?? ''));
        $minimumPhp = trim((string) ($status['minimum_php'] ?? ''));
        if ($minimumWhmcs !== '' || $minimumPhp !== '') {
            $requirements = [];
            if ($minimumWhmcs !== '') {
                $requirements['WHMCS mínimo'] = Ui::h($minimumWhmcs);
            }
            if ($minimumPhp !== '') {
                $requirements['PHP mínimo'] = Ui::h($minimumPhp);
            }
            $this->renderConfigSectionStart('Requisitos', 'Informados pela versão mais recente.');
            $this->renderConfigBlock(self::factsHtml($requirements));
            $this->renderConfigSectionEnd();
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
                ? ($daysLeft <= 1 ? 'Expira em 1 dia' : 'Expira em ' . $daysLeft . ' dias')
                : 'Crítico',
            'expiring' => $daysLeft !== null
                ? ($daysLeft <= 1 ? 'Expira em 1 dia' : 'Expira em ' . $daysLeft . ' dias')
                : 'Expira em breve',
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
            echo Ui::callout('ok', 'Configuração do DANFS-e salva.');
        } elseif ($msg === 'danfse_invalid') {
            $detail = trim((string) ($_REQUEST['detail'] ?? ''));
            echo Ui::callout('danger', 'Revise os campos da configuração do DANFS-e.', $detail);
        } elseif ($msg === 'danfse_error') {
            echo Ui::callout('danger', 'Erro ao salvar a configuração do DANFS-e.');
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

        $this->renderConfigSectionStart('Cabeçalho do DANFS-e', 'Logotipo e dados institucionais do cabeçalho do PDF.');
        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=saveDanfseConfig" class="nfse-cfg-cardform">';
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . Ui::h($token) . '" />';
        }
        $this->renderConfigFormTableStart();
        $this->renderTextareaRow('danfse_logo_svg', 'Logo SVG', (string) (($config['danfse_logo_svg'] ?? '') !== '' ? $config['danfse_logo_svg'] : $defaults['danfse_logo_svg']));
        $this->renderTextRow('danfse_municipio_nome', 'Nome', (string) (($config['danfse_municipio_nome'] ?? '') !== '' ? $config['danfse_municipio_nome'] : $defaults['danfse_municipio_nome']));
        $this->renderTextRow('danfse_secretaria_nome', 'Secretaria', (string) (($config['danfse_secretaria_nome'] ?? '') !== '' ? $config['danfse_secretaria_nome'] : $defaults['danfse_secretaria_nome']));
        $this->renderTextRow('danfse_telefone', 'Telefone', (string) (($config['danfse_telefone'] ?? '') !== '' ? $config['danfse_telefone'] : $defaults['danfse_telefone']));
        $this->renderTextRow('danfse_email', 'E-mail', (string) (($config['danfse_email'] ?? '') !== '' ? $config['danfse_email'] : $defaults['danfse_email']));
        $this->renderConfigFormTableEnd();
        echo Ui::saveBar(Ui::button('Salvar configuração DANFS-e', ['primary']));
        echo '</form>';
        $this->renderConfigSectionEnd();

        $this->renderConfigSectionStart('Pré-visualização', 'Como o cabeçalho do PDF fica com as configurações atuais.', '', true);
        $html = '<div class="nfse-cfg-preview">';
        $html .= '<div class="nfse-cfg-preview__title"><div>DANFSe v1.0</div><div>Documento Auxiliar da NFS-e</div>';
        if ($preview['environment'] === 'homologacao') {
            $html .= '<div class="nfse-text-danger">NFS-e SEM VALIDADE JURÍDICA</div>';
        }
        $html .= '</div>';
        $html .= '<div class="nfse-cfg-preview__org">';
        if (trim((string) $preview['logo_svg']) !== '' && stripos((string) $preview['logo_svg'], '<svg') !== false) {
            $html .= '<div class="nfse-cfg-logo">' . $preview['logo_svg'] . '</div>';
        }
        $html .= '<div class="nfse-cfg-preview__org-text">';
        $html .= '<div class="nfse-cfg-preview__org-name">' . Ui::h((string) $preview['municipio_nome']) . '</div>';
        $html .= '<div>' . Ui::h((string) $preview['secretaria_nome']) . '</div>';
        $html .= '<div>' . Ui::h((string) $preview['telefone']) . '</div>';
        $html .= '<div>' . Ui::h((string) $preview['email']) . '</div>';
        $html .= '</div></div></div>';
        $this->renderConfigBlock($html);
        $this->renderConfigBlock('A pré-visualização é ilustrativa e reflete o cabeçalho configurado. O PDF final continua aplicando automaticamente o aviso em homologação.', 'nfse-cfg-note');
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
                '<svg preserveAspectRatio="xMidYMid meet"',
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
            echo Ui::callout('ok', 'Relação de código de serviço e NBS adicionada.');
        } elseif ($msg === 'service_nbs_exists') {
            echo Ui::callout('danger', 'Essa relação já existe no catálogo.');
        } elseif ($msg === 'service_nbs_invalid') {
            echo Ui::callout('danger', 'Informe código de serviço com 6 dígitos, NBS com 9 dígitos e uma descrição.');
        } elseif ($msg === 'service_nbs_deleted') {
            echo Ui::callout('ok', 'Relação de código de serviço e NBS removida.');
        } elseif ($msg === 'service_nbs_updated') {
            echo Ui::callout('ok', 'Relação de código de serviço e NBS atualizada.');
        } elseif ($msg === 'service_nbs_in_use') {
            $usageDetail = trim((string) ($_REQUEST['usage'] ?? ''));
            echo Ui::callout(
                'danger',
                'Não é possível excluir essa relação porque ela está em uso.',
                $usageDetail !== '' ? $usageDetail : 'Revise a configuração padrão e os grupos mapeados.'
            );
        } elseif ($msg === 'service_nbs_error') {
            echo Ui::callout('danger', 'Erro ao processar a relação de código de serviço e NBS.', 'Tente novamente e, se persistir, revise os vínculos dessa relação nas demais configurações do módulo.');
        }

        $repo = new ServiceNbsCatalogRepository();
        $rows = $repo->listAll();
        $catalogCount = count($rows);
        $token = (new TokenService())->token();
        $usageMap = $this->getServiceNbsUsageMap();
        $editId = (int) ($_GET['edit_service_nbs_id'] ?? 0);
        $editRow = $editId > 0 ? $repo->findById($editId) : null;
        $digitsOnly = 'this.value=this.value.replace(/\D/g, \'\');';

        $this->renderConfigSectionStart('Relações cadastradas', 'Alimentam a escolha de código de serviço e NBS no módulo. Total: ' . $catalogCount . '.', '', true);
        if (empty($rows)) {
            $this->renderConfigBlock('Nenhuma relação cadastrada até o momento.', 'nfse-cfg-note');
        } else {
            echo '<div class="nfse-table-wrap"><table class="nfse-table">';
            echo '<thead><tr><th scope="col">Código de Serviço</th><th scope="col">NBS</th><th scope="col">Descrição</th><th scope="col">Uso atual</th><th scope="col">Ações</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $usageKey = (string) ($row['codigo_servico'] ?? '') . '|' . (string) ($row['nbs'] ?? '');
                $usage = $usageMap[$usageKey] ?? [
                    'is_default' => false,
                    'group_names' => [],
                    'summary' => 'Livre para remover',
                ];
                echo '<tr>';
                echo '<td class="nfse-nowrap"><span class="nfse-mono">' . Ui::h((string) ($row['codigo_servico'] ?? '')) . '</span></td>';
                echo '<td class="nfse-nowrap"><span class="nfse-mono">' . Ui::h((string) ($row['nbs'] ?? '')) . '</span></td>';
                echo '<td class="nfse-break">' . Ui::h((string) ($row['descricao'] ?? '')) . '</td>';
                echo '<td class="nfse-break">' . Ui::h((string) ($usage['summary'] ?? 'Livre para remover')) . '</td>';
                echo '<td>';
                echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=deleteServiceNbsCatalog" class="nfse-inline-form nfse-actions">';
                if ($token !== '') {
                    echo '<input type="hidden" name="token" value="' . Ui::h($token) . '" />';
                }
                echo '<input type="hidden" name="id" value="' . $id . '" />';
                echo Ui::button('Editar', ['sm'], ['href' => 'addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs&edit_service_nbs_id=' . $id]);
                echo Ui::button('Excluir', ['sm', 'danger'], ['onclick' => 'return confirm(\'Remover esta relação também a tornará indisponível nas demais telas. Continuar?\');']);
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        $this->renderConfigSectionEnd();

        if ($editRow) {
            $this->renderConfigSectionStart('Editar relação', 'Se a relação estiver em uso, a mudança vale também em Tributação e nos grupos mapeados.');
            echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=updateServiceNbsCatalog" class="nfse-cfg-cardform">';
            if ($token !== '') {
                echo '<input type="hidden" name="token" value="' . Ui::h($token) . '" />';
            }
            echo '<input type="hidden" name="id" value="' . (int) ($editRow['id'] ?? 0) . '" />';
            $this->renderConfigFormTableStart();
            $this->renderCatalogFields(
                (string) ($editRow['codigo_servico'] ?? ''),
                (string) ($editRow['nbs'] ?? ''),
                (string) ($editRow['descricao'] ?? ''),
                false,
                $digitsOnly
            );
            $this->renderConfigFormTableEnd();
            echo Ui::saveBar(
                Ui::button('Cancelar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=config&tab=servicosnbs'])
                . Ui::button('Salvar edição', ['primary'])
            );
            echo '</form>';
            $this->renderConfigSectionEnd();
        }

        $this->renderConfigSectionStart('Adicionar nova relação', 'Novo par de código de serviço e NBS para as demais configurações.');
        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=saveServiceNbsCatalog" class="nfse-cfg-cardform">';
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . Ui::h($token) . '" />';
        }
        $this->renderConfigFormTableStart();
        $this->renderCatalogFields('', '', '', true, $digitsOnly);
        $this->renderConfigFormTableEnd();
        echo Ui::saveBar(Ui::button('Adicionar relação', ['primary']));
        echo '</form>';
        $this->renderConfigSectionEnd();
    }

    /**
     * Campos do catálogo (código de serviço, NBS e descrição), iguais no cadastro e na edição.
     */
    private function renderCatalogFields(string $codigo, string $nbs, string $descricao, bool $withPlaceholders, string $digitsOnly): void
    {
        $codigoId = $this->configFieldId('codigo_servico_catalog');
        echo Ui::formRow('Código de Serviço', Ui::input('codigo_servico_catalog', $codigo, 'text', [
            'id' => $codigoId,
            'maxlength' => '6',
            'inputmode' => 'numeric',
            'pattern' => '[0-9]*',
            'oninput' => $digitsOnly,
            'placeholder' => $withPlaceholders ? 'Ex.: 010301' : null,
        ], 'nfse-cfg-field nfse-mono'), 'Use 6 dígitos numéricos, sem pontuação.', ['id' => $codigoId]);

        $nbsId = $this->configFieldId('nbs_catalog');
        echo Ui::formRow('NBS', Ui::input('nbs_catalog', $nbs, 'text', [
            'id' => $nbsId,
            'maxlength' => '9',
            'inputmode' => 'numeric',
            'pattern' => '[0-9]*',
            'oninput' => $digitsOnly,
            'placeholder' => $withPlaceholders ? 'Ex.: 115061000' : null,
        ], 'nfse-cfg-field nfse-mono'), 'Use 9 dígitos numéricos para a NBS vinculada ao código de serviço.', ['id' => $nbsId]);

        $descId = $this->configFieldId('descricao_catalog');
        $textarea = '<textarea name="descricao_catalog" id="' . Ui::h($descId) . '" class="nfse-input" rows="3"'
            . ($withPlaceholders ? ' placeholder="Descrição amigável da NBS"' : '') . '>' . Ui::h($descricao) . '</textarea>';
        echo Ui::formRow('Descrição', $textarea, 'Texto exibido nas listas de seleção para facilitar a identificação da NBS.', ['id' => $descId]);
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
            echo '<div class="nfse-cfg-block nfse-cfg-note">Quando o catálogo estiver disponível, esses campos passam a usar a relação cadastrada na subaba Código de Serviços e NBS.</div>';
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

        echo Ui::formRow(
            'Código de Serviço Padrão',
            Ui::select('codigo_servico', ['' => 'Selecione'] + $servicoOptions, $selectedServico, ['id' => 'nfse-tributacao-servico'], 'nfse-cfg-field'),
            'As opções são carregadas do catálogo mantido na subaba Código de Serviços e NBS.',
            ['id' => 'nfse-tributacao-servico']
        );
        echo Ui::formRow(
            'NBS Padrão',
            '<select name="nbs_padrao" id="nfse-tributacao-nbs" class="nfse-input nfse-cfg-field"></select>'
            . '<div class="nfse-form__help" id="nfse-tributacao-nbs-help">Selecione um código de serviço para carregar as NBS relacionadas.</div>',
            '',
            ['id' => 'nfse-tributacao-nbs']
        );

        echo '<script>(function(){var selectedNbs=' . json_encode($selectedNbs, JSON_UNESCAPED_UNICODE) . ';var nbsOptions=' . $jsNbsOptions . ';var servicoEl=document.getElementById("nfse-tributacao-servico");var nbsEl=document.getElementById("nfse-tributacao-nbs");var helpEl=document.getElementById("nfse-tributacao-nbs-help");if(!servicoEl||!nbsEl){return;}function fill(){var servico=servicoEl.value||"";nbsEl.innerHTML="";if(servico===""){var placeholder=document.createElement("option");placeholder.value="";placeholder.textContent="Selecione um código de serviço primeiro";nbsEl.appendChild(placeholder);nbsEl.disabled=true;if(helpEl){helpEl.textContent="Cadastre novas combinações na subaba Código de Serviços e NBS sempre que necessário.";}return;}var opts=nbsOptions[servico]||{};var first="";for(var key in opts){if(!opts.hasOwnProperty(key)){continue;}if(first===""){first=key;}var option=document.createElement("option");option.value=key;option.textContent=opts[key];if(selectedNbs!==""&&selectedNbs===key){option.selected=true;}nbsEl.appendChild(option);}if(nbsEl.options.length===0){var empty=document.createElement("option");empty.value="";empty.textContent="(Sem NBS cadastrada para este código)";nbsEl.appendChild(empty);nbsEl.disabled=true;if(helpEl){helpEl.textContent="Adicione uma NBS relacionada a este código na subaba Código de Serviços e NBS.";}return;}nbsEl.disabled=false;if(selectedNbs===""||!opts.hasOwnProperty(selectedNbs)){nbsEl.value=first;}if(helpEl){helpEl.textContent="A NBS selecionada será usada como padrão quando não houver regra específica por grupo.";}selectedNbs=nbsEl.value||"";}fill();servicoEl.addEventListener("change",function(){selectedNbs="";fill();});})();</script>';
    }


    /**
     * Opções do select de código de serviço. Códigos sem zero à esquerda (ex.: "140101") viram chave
     * int no array do PHP, por isso a comparação com o valor salvo é feita como string.
     */
    public static function renderServiceCodeOptions(array $options, string $selected): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $value = (string) $value;
            $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . ($value === $selected ? ' selected' : '') . '>'
                . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        return $html;
    }


    public function renderCodigosProdutosServicosContent(): void
    {
        if ((string) ($_GET['codes_saved'] ?? '') === '1') {
            echo Ui::callout('ok', 'Configuração salva.');
        }

        $config = (new ConfigRepository())->get();
        $defaultServico = (string) ($config['codigo_servico'] ?? '');
        $defaultNbs = (string) ($config['nbs_padrao'] ?? '');

        $groups = Capsule::table('tblproductgroups')->orderBy('order', 'asc')->orderBy('name', 'asc')->get();
        $map = (new GroupServiceCodeRepository())->getAllByGroupId();
        $catalogRepo = new ServiceNbsCatalogRepository();
        $servicoOptions = $catalogRepo->getServiceOptions(true);
        $nbsOptions = $catalogRepo->getNbsOptionsByServiceCode();

        $this->renderConfigSectionStart('Mapeamento por grupo', 'Código de serviço e NBS de cada grupo do WHMCS. Em "Padrão", vale o que está em Tributação. Revise ao criar grupos novos.', '', true);

        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=saveCodigos" class="nfse-cfg-cardform">';
        $token = (new TokenService())->token();
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . Ui::h($token) . '" />';
        }

        echo '<div class="nfse-table-wrap"><table class="nfse-table nfse-cfg-table">';
        echo '<thead><tr><th scope="col">Grupo</th><th scope="col">Código de Serviço</th><th scope="col">NBS</th></tr></thead><tbody>';

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
            if ($curServico !== '' && !isset($rowServicoOptions[$curServico])) {
                $rowServicoOptions[$curServico] = $curServico . ' (Atual fora do catálogo)';
            }

            echo '<tr>';
            echo '<td class="nfse-break">' . Ui::h($name) . '</td>';
            echo '<td>' . Ui::select('codigo_servico[' . $gid . ']', $rowServicoOptions, $curServico, ['data-gid' => (string) $gid, 'aria-label' => 'Código de serviço: ' . $name], 'nfse-input--sm nfse-servico') . '</td>';
            echo '<td>';
            echo '<select class="nfse-input nfse-input--sm nfse-nbs" name="nbs[' . $gid . ']" data-gid="' . $gid . '" aria-label="' . Ui::h('NBS: ' . $name) . '"></select>';
            echo '<div class="nfse-cell-meta nfse-nbs-help" data-gid="' . $gid . '"></div>';
            echo '<input type="hidden" class="nfse-cur-servico" data-gid="' . $gid . '" value="' . Ui::h($curServico) . '" />';
            echo '<input type="hidden" class="nfse-cur-nbs" data-gid="' . $gid . '" value="' . Ui::h($curNbs) . '" />';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo Ui::saveBar(Ui::button('Salvar', ['primary']));
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


    /**
     * Tela antiga do mapeamento (sem rota própria hoje: action=codigos redireciona para a aba de Configurações).
     */
    public function showCodigosProdutosServicos(): void
    {
        Module::ui()->renderHeader('Códigos Produtos/Serviços - OpenNfse');
        $this->renderTabs('config', 'Códigos de produtos e serviços');
        echo '<div class="nfse-ui nfse-page">';
        if ((string) ($_GET['saved'] ?? '') === '1') {
            echo Ui::callout('ok', 'Configuração salva.');
        }
        $this->renderCodigosProdutosServicosContent();
        echo '</div>';
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
