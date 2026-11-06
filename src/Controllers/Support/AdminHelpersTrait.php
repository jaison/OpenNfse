<?php

declare(strict_types=1);

namespace OpenNfse\Controllers\Support;

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
use OpenNfse\Views\AdminNav;
use OpenNfse\Views\Dashboard\DashboardQueue;
use OpenNfse\Views\Ui;
use WHMCS\Database\Capsule;

trait AdminHelpersTrait
{
    public function redirectRelatorios(string $tab): void
    {
        $tab = trim($tab);
        $allowed = ['emitidas' => true, 'falhas' => true, 'cancelamentos' => true, 'auditoria' => true, 'logs' => true];
        if (!isset($allowed[$tab])) {
            $tab = 'emitidas';
        }

        $params = $_GET;
        unset($params['module'], $params['action']);
        $params['tab'] = $tab;

        $url = 'addonmodules.php?module=OpenNfse&action=relatorios';
        if (!empty($params)) {
            $url .= '&' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        header('Location: ' . $url);
        exit;
    }


    public function redirectInvoice(int $invoiceId, array $params): void
    {
        $url = 'invoices.php?action=edit&id=' . $invoiceId;
        if (!empty($params)) {
            $url .= '&' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        header('Location: ' . $url);
        exit;
    }


    public function redirectFila(array $params, bool $keepFilters): void
    {
        $url = 'addonmodules.php?module=OpenNfse&action=fila';
        $merged = $params;
        if ($keepFilters) {
            $fStatus = trim((string) ($_REQUEST['return_status'] ?? $_REQUEST['status'] ?? ''));
            $fInvoice = trim((string) ($_REQUEST['return_invoiceid'] ?? $_REQUEST['invoiceid'] ?? ''));
            $fUpdatedFrom = trim((string) ($_REQUEST['return_updated_from'] ?? $_REQUEST['updated_from'] ?? ''));
            $fUpdatedTo = trim((string) ($_REQUEST['return_updated_to'] ?? $_REQUEST['updated_to'] ?? ''));
            $fPage = trim((string) ($_REQUEST['return_page'] ?? $_REQUEST['page'] ?? ''));
            $fPerPage = trim((string) ($_REQUEST['return_per_page'] ?? $_REQUEST['per_page'] ?? ''));
            if ($fStatus !== '') {
                $merged['status'] = $fStatus;
            }
            if ($fInvoice !== '') {
                $merged['invoiceid'] = $fInvoice;
            }
            if ($fUpdatedFrom !== '') {
                $merged['updated_from'] = $fUpdatedFrom;
            }
            if ($fUpdatedTo !== '') {
                $merged['updated_to'] = $fUpdatedTo;
            }
            if ($fPage !== '') {
                $merged['page'] = $fPage;
            }
            if ($fPerPage !== '') {
                $merged['per_page'] = $fPerPage;
            }
        }
        if (!empty($merged)) {
            $url .= '&' . http_build_query($merged, '', '&', PHP_QUERY_RFC3986);
        }
        header('Location: ' . $url);
        exit;
    }


    public function redirectRelatorioEmitidas(array $params, bool $keepFilters): void
    {
        $url = 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas';
        $merged = $params;
        if ($keepFilters) {
            $fInvoice = trim((string) ($_REQUEST['return_invoiceid'] ?? $_REQUEST['invoiceid'] ?? ''));
            $fStatus = trim((string) ($_REQUEST['return_status'] ?? $_REQUEST['status'] ?? ''));
            $fFrom = trim((string) ($_REQUEST['return_data_inicial'] ?? $_REQUEST['data_inicial'] ?? $_REQUEST['return_updated_from'] ?? $_REQUEST['updated_from'] ?? ''));
            $fTo = trim((string) ($_REQUEST['return_data_final'] ?? $_REQUEST['data_final'] ?? $_REQUEST['return_updated_to'] ?? $_REQUEST['updated_to'] ?? ''));
            if ($fInvoice !== '') {
                $merged['invoiceid'] = $fInvoice;
            }
            if ($fStatus !== '') {
                $merged['status'] = $fStatus;
            }
            if ($fFrom !== '') {
                $merged['data_inicial'] = $fFrom;
            }
            if ($fTo !== '') {
                $merged['data_final'] = $fTo;
            }
        }
        if (!empty($merged)) {
            $url .= '&' . http_build_query($merged, '', '&', PHP_QUERY_RFC3986);
        }
        header('Location: ' . $url);
        exit;
    }


    /**
     * Barra de navegação do addon (Views\AdminNav), com o título da tela.
     * $title null usa o título padrão da aba; $detail só vai na dica (title) do título.
     * $context aceita dados já lidos pela tela, para não repetir consultas: config e queue
     * (QueueRepository::currentSnapshot()).
     *
     * @param array{config?: array<string, mixed>, queue?: array<string, mixed>} $context
     */
    public function renderTabs(string $active, ?string $title = null, ?string $subtitle = null, array $context = [], string $detail = ''): void
    {
        echo AdminNav::shell($active, $this->navData($context), $title, $subtitle, $detail);
    }

    /**
     * @param array{config?: array<string, mixed>, queue?: array<string, mixed>} $context
     * @return array<string, mixed>
     */
    private function navData(array $context): array
    {
        $systemUrl = '';
        if (isset($GLOBALS['CONFIG']['SystemURL']) && is_string($GLOBALS['CONFIG']['SystemURL'])) {
            $systemUrl = rtrim((string) $GLOBALS['CONFIG']['SystemURL'], '/');
        }
        // Sem SystemURL, o caminho relativo parte da pasta do admin, que fica um nível abaixo da raiz.
        $data = [
            'logo_url' => ($systemUrl !== '' ? $systemUrl : '..') . '/modules/addons/OpenNfse/assets/opennsfe_logo.png',
            'queue' => null,
            'version' => [],
        ];

        // A barra nunca derruba a tela: se a leitura falhar, o estado da fila (ou a versão) só não aparece.
        try {
            $config = $context['config'] ?? (new ConfigRepository())->get();
        } catch (\Throwable $e) {
            $config = null;
        }

        try {
            $snapshot = $context['queue'] ?? (new QueueRepository())->currentSnapshot();
            $cron = is_array($config)
                ? DashboardQueue::cronState((string) ($config['cron_last_run_at'] ?? ''), (string) ($config['cron_last_source'] ?? ''), time())
                : null;
            $data['queue'] = AdminNav::queueStatus($snapshot, $cron);
        } catch (\Throwable $e) {
            $data['queue'] = null;
        }

        try {
            $status = (new UpdateCheckService())->getStatus(is_array($config) ? $config : []);
            $data['version'] = [
                'current' => (string) ($status['current_version'] ?? ''),
                'latest' => (string) ($status['latest_version'] ?? ''),
                'update_available' => !empty($status['update_available']),
                'href' => 'addonmodules.php?module=OpenNfse&action=config&tab=atualizacoes',
            ];
        } catch (\Throwable $e) {
            $data['version'] = [];
        }

        return $data;
    }


    public function resolveClientName(array $row): string
    {
        $company = trim((string) ($row['companyname'] ?? ''));
        if ($company !== '') {
            return $company;
        }
        $first = trim((string) ($row['firstname'] ?? ''));
        $last = trim((string) ($row['lastname'] ?? ''));
        $name = trim($first . ' ' . $last);
        return $name !== '' ? $name : '-';
    }


    public function formatDate(string $value, string $format): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }
        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->format($format);
        } catch (\Throwable $e) {
            return '-';
        }
    }


    public function formatMoney(float $amount, string $prefix, string $suffix): string
    {
        $prefix = (string) $prefix;
        $suffix = (string) $suffix;
        $formatted = number_format($amount, 2, ',', '.');
        return $prefix . $formatted . $suffix;
    }


    /**
     * Bloco de subseção das Configurações: título, descrição e ações à esquerda; o card com os campos à direita.
     * Com $wide (tabelas, grades de status) o texto fica em cima e o card ocupa a largura toda.
     * O conteúdo vem logo depois (formulário, blocos, tabela) e o bloco fecha em renderConfigSectionEnd().
     */
    public function renderConfigSectionStart(string $title, string $description = '', string $actionsHtml = '', bool $wide = false): void
    {
        echo '<div class="nfse-cfg-blk' . ($wide ? ' nfse-cfg-blk--wide' : '') . '">';
        echo '<div class="nfse-cfg-blk__intro">';
        echo '<h3 class="nfse-cfg-blk__title">' . Ui::h($title) . '</h3>';
        if ($description !== '') {
            echo '<p class="nfse-cfg-blk__desc">' . Ui::h($description) . '</p>';
        }
        if ($actionsHtml !== '') {
            echo '<div class="nfse-actions nfse-cfg-blk__actions">' . $actionsHtml . '</div>';
        }
        echo '</div>';
        echo '<section class="nfse-card nfse-cfg-card" aria-label="' . Ui::h($title) . '">';
    }


    public function renderConfigSectionEnd(): void
    {
        echo '</section></div>';
    }


    public function renderConfigFormTableStart(): void
    {
        echo '<div class="nfse-form">';
    }


    public function renderConfigFormTableEnd(): void
    {
        echo '</div>';
    }


    /**
     * Bloco de texto dentro do card de subseção (nota, aviso, ações), com o recuo das linhas do formulário.
     */
    public function renderConfigBlock(string $html, string $class = ''): void
    {
        echo '<div class="nfse-cfg-block' . ($class !== '' ? ' ' . Ui::h($class) : '') . '">' . $html . '</div>';
    }


    /**
     * Cabeçalho do painel de uma seção: título e descrição à esquerda; pendências e o status à direita.
     */
    public function renderConfigPaneHeader(string $title, string $description, array $status, string $id = ''): void
    {
        $label = (string) ($status['label'] ?? 'Revisar');
        $missing = is_array($status['missing'] ?? null) ? $status['missing'] : [];

        $actions = '';
        if (!empty($missing)) {
            $missingText = implode(', ', array_slice(array_map(static fn ($v): string => (string) $v, $missing), 0, 3));
            if (count($missing) > 3) {
                $missingText .= ' e mais ' . (count($missing) - 3);
            }
            $actions .= '<span class="nfse-cfg-pending">Pendências: ' . Ui::h($missingText) . '</span>';
        }
        $summary = trim((string) ($status['summary'] ?? ''));
        $actions .= '<span' . ($summary !== '' ? ' title="' . Ui::h($summary) . '"' : '') . '>'
            . Ui::badge($label, $this->configStatusTone($status)) . '</span>';

        echo Ui::sectionHeader($title, $description, $actions, $id);
    }


    /**
     * Tom do badge de status de uma seção: ok, warn (atenção) ou neutro.
     */
    public function configStatusTone(array $status): string
    {
        return match ((string) ($status['tone'] ?? 'info')) {
            'ok' => 'ok',
            'attention' => 'warn',
            default => '',
        };
    }


    /**
     * id único para o campo (o mesmo name aparece em mais de um formulário da tela).
     */
    public function configFieldId(string $name): string
    {
        static $seen = [];
        $base = 'nfse-cfg-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
        $seen[$base] = ($seen[$base] ?? 0) + 1;

        return $seen[$base] === 1 ? $base : $base . '-' . $seen[$base];
    }


    public function renderTextRow(string $name, string $label, mixed $value): void
    {
        $id = $this->configFieldId($name);
        $class = 'nfse-cfg-field' . ($name === 'certificate_path' ? ' nfse-mono' : '');
        echo Ui::formRow($label, Ui::input($name, (string) $value, 'text', ['id' => $id], $class), $this->getConfigFieldHelp($name), ['id' => $id, 'more' => $this->getConfigFieldHelpMore($name)]);
    }


    public function renderPasswordRow(string $name, string $label, mixed $value): void
    {
        $id = 'nfse-field-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
        $field = '<div class="nfse-cfg-inline">'
            . Ui::input($name, (string) $value, 'password', ['id' => $id, 'autocomplete' => 'new-password'])
            . Ui::button('Mostrar', [], ['type' => 'button', 'data-nfse-toggle-password' => $id])
            . '</div>';
        echo Ui::formRow($label, $field, $this->getConfigFieldHelp($name), ['id' => $id, 'more' => $this->getConfigFieldHelpMore($name)]);
    }


    public function renderSelectRow(string $name, string $label, array $options, string $selected): void
    {
        $id = $this->configFieldId($name);
        echo Ui::formRow($label, Ui::select($name, $options, $selected, ['id' => $id], 'nfse-cfg-field'), $this->getConfigFieldHelp($name), ['id' => $id, 'more' => $this->getConfigFieldHelpMore($name)]);
    }


    public function renderCustomFieldSelectRow(string $name, string $label, string $selected): void
    {
        $fields = \WHMCS\Database\Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->orderBy('fieldname')
            ->get(['id', 'fieldname']);

        $options = ['' => 'Selecione...'];
        foreach ($fields as $field) {
            $options[(string) $field->id] = (string) $field->fieldname . ' (#' . (string) $field->id . ')';
        }
        if ($selected !== '' && $selected !== '0' && !$fields->contains('id', (int) $selected)) {
            $options[$selected] = '#' . $selected . ' (campo não encontrado)';
        }

        $id = $this->configFieldId($name);
        echo Ui::formRow($label, Ui::select($name, $options, $selected, ['id' => $id], 'nfse-cfg-field'), $this->getConfigFieldHelp($name), ['id' => $id, 'more' => $this->getConfigFieldHelpMore($name)]);
    }


    public function renderTextareaRow(string $name, string $label, mixed $value): void
    {
        $id = $this->configFieldId($name);
        $field = '<textarea name="' . Ui::h($name) . '" id="' . Ui::h($id) . '" class="nfse-input" rows="5">' . Ui::h((string) $value) . '</textarea>';
        echo Ui::formRow($label, $field, $this->getConfigFieldHelp($name), ['id' => $id, 'more' => $this->getConfigFieldHelpMore($name)]);
    }

    public function renderPostActionButton(string $action, string $label, array $params = [], string $class = 'btn btn-xs btn-default', string $style = ''): string
    {
        $token = (new TokenService())->token();
        $html = '<form method="post" action="addonmodules.php" style="display:inline-block;margin:0;' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="module" value="OpenNfse" />';
        $html .= '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" />';
        if ($token !== '') {
            $html .= '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
        }
        foreach ($params as $key => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '" />';
        }
        $html .= '<button type="submit" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>';
        $html .= '</form>';

        return $html;
    }


    public function buildRelatoriosExportUrl(string $tab, array $params): string
    {
        $base = [
            'module' => 'OpenNfse',
            'action' => 'relatoriosExport',
            'tab' => $tab,
        ];
        $merged = array_merge($base, $params);
        return 'addonmodules.php?' . http_build_query($merged, '', '&', PHP_QUERY_RFC3986);
    }


    public function buildRelatoriosZipUrl(string $tab, array $params): string
    {
        $base = [
            'module' => 'OpenNfse',
            'action' => 'relatoriosExportZip',
            'tab' => $tab,
        ];
        $merged = array_merge($base, $params);
        return 'addonmodules.php?' . http_build_query($merged, '', '&', PHP_QUERY_RFC3986);
    }


    /**
     * @return array<string, string|array{0: string, 1: string}>
     */
    private function configFieldHelpMap(): array
    {
        $help = [
            'environment' => 'Use homologação para testes e produção somente quando toda a configuração fiscal já estiver validada.',
            'certificate_path' => 'Informe o caminho absoluto do arquivo .pfx disponível no servidor do WHMCS.',
            'certificate_password' => 'Não aparece depois de salva. Preencha só para trocar a senha.',
            'cnpj_emissor' => 'Digite apenas números para manter o CNPJ no formato esperado pelas validações do módulo.',
            'prestador_informar_im' => 'Defina se a inscrição municipal deve ser enviada de forma obrigatória ao prestador.',
            'inscricao_municipal' => 'Obrigatória quando a prefeitura ou o ambiente nacional exigirem a IM do prestador.',
            'codigo_ibge' => 'Código IBGE do município do prestador. É usado em diversos pontos do payload fiscal.',
            'prestador_nome' => 'Razão social enviada no xNome do prestador para compor a identificação fiscal.',
            'prestador_email' => 'Endereço de contato institucional do prestador.',
            'prestador_fone' => 'Telefone do prestador no padrão aceito pelo seu emissor.',
            'prestador_logradouro' => 'Logradouro do endereço fiscal do prestador.',
            'prestador_numero' => 'Número do endereço do prestador.',
            'prestador_complemento' => 'Complemento do endereço, quando existir.',
            'prestador_bairro' => 'Bairro do endereço fiscal do prestador.',
            'prestador_cep' => 'CEP do endereço do prestador. Prefira apenas números.',
            'codigo_servico' => 'Código de serviço padrão usado quando não houver regra específica por grupo.',
            'nbs_padrao' => 'NBS padrão com 9 dígitos, aplicada quando o grupo não definir um valor próprio.',
            'aliquota_iss' => 'Informe a alíquota ISS em formato numérico, com ponto ou vírgula.',
            'serie_dps' => 'Série padrão usada na numeração do DPS emitido pelo módulo.',
            'prestador_op_simp_nac' => 'Selecione o enquadramento do prestador perante o Simples Nacional.',
            'prestador_reg_ap_trib_sn' => 'Usado quando o prestador é ME/EPP no Simples Nacional.',
            'prestador_reg_esp_trib' => ['Use 0 quando não houver enquadramento específico.', 'Regime especial tributário (TSRegEspTrib): 0 Nenhum, 1 Ato Cooperado, 2 Estimativa, 3 Microempresa Municipal, 4 Notário ou Registrador, 5 Profissional Autônomo, 6 Sociedade de Profissionais, 9 Outros.'],
            'dps_leiaute' => ['Deixe em Automático, salvo se a SEFIN rejeitar a DPS com E1235 por leiaute.', 'O modo Automático segue a NT 009: leiaute novo em homologação desde 20/10/2026 e em produção a partir de 01/12/2026 00:00 (horário de Brasília). Force um leiaute só se a SEFIN rejeitar a DPS com E1235 por leiaute.'],
            'tomador_cpfcnpj_customfield_id' => 'ID do custom field do cliente que armazena CPF/CNPJ do tomador no WHMCS.',
            'client_area_notice_enabled' => 'Controla a exibição de um aviso fixo no topo da lista de NFS-e do cliente.',
            'client_area_notice_type' => 'Define o estilo visual do aviso mostrado na área do cliente.',
            'client_area_notice_message' => ['Mensagem exibida na lista de NFS-e do cliente.', 'HTML não é permitido. URLs informadas no texto podem ser exibidas como links clicáveis.'],
            'tomador_codigo_ibge_padrao' => 'Fallback para o código do município do tomador quando o cadastro do cliente não informar.',
            'tomador_numero_padrao' => 'Número padrão usado quando o endereço do tomador vier sem numeração.',
            'tomador_bairro_origem' => ['De onde vem o bairro do tomador na NFS-e.', 'Endereço 2 é o Bairro: o campo Endereço 2 do cliente vai como bairro da NFS-e. Campo personalizado: o bairro vem do custom field escolhido abaixo e o Endereço 2 vai como complemento.'],
            'tomador_bairro_customfield_id' => 'Custom field do cliente que armazena o bairro. Usado só com a origem "Campo personalizado".',
            'tomador_bairro_padrao' => 'Bairro usado quando a origem escolhida estiver vazia.',
            'queue_enabled' => 'Habilita o processamento automático via fila e cron do módulo.',
            'auto_emit_trigger' => ['Nunca, quando a fatura for paga ou quando for gerada.', 'Nunca: sem emissão automática. Paga: enfileira quando a fatura é paga. Gerada: enfileira quando a fatura Unpaid é publicada (só nos gateways marcados para emitir ao gerar) e, se ainda não houver NFS-e, também no pagamento.'],
            'auto_emit_scope' => 'Com "Só os grupos selecionados", a regra acima vale apenas para clientes dos grupos marcados abaixo.',
            'allow_manual_unpaid' => ['Vale para o botão Emitir NFS-e na fatura. Exige a fila ligada.', 'A regra vale independente do gatilho automático, do grupo e do custom field do cliente.'],
            'queue_wait_status_interval_seconds' => 'Intervalo entre consultas de status quando a nota fica aguardando retorno do ambiente nacional.',
            'queue_done_retention_days' => 'Quantidade de dias para manter registros da fila concluídos ou resolvidos antes da limpeza.',
            'logs_retention_days' => 'Quantidade de dias para manter logs de request/response antes da limpeza.',
            'danfse_logo_svg' => 'Cole aqui o SVG completo do brasão ou logotipo que deve aparecer no cabeçalho do DANFS-e.',
            'danfse_municipio_nome' => 'Linha principal exibida no bloco institucional do cabeçalho do PDF.',
            'danfse_secretaria_nome' => 'Linha secundária exibida abaixo do nome do município.',
            'danfse_telefone' => 'Telefone institucional mostrado no cabeçalho do DANFS-e.',
            'danfse_email' => 'E-mail institucional mostrado no cabeçalho do DANFS-e.',
        ];

        return $help;
    }


    /**
     * Ajuda curta do campo (uma linha). Entradas com [curta, detalhe] mostram o detalhe em "Como funciona".
     */
    public function getConfigFieldHelp(string $name): string
    {
        $entry = $this->configFieldHelpMap()[$name] ?? '';

        return is_array($entry) ? (string) ($entry[0] ?? '') : (string) $entry;
    }


    public function getConfigFieldHelpMore(string $name): string
    {
        $entry = $this->configFieldHelpMap()[$name] ?? '';

        return is_array($entry) ? (string) ($entry[1] ?? '') : '';
    }


    public function getConfigTabStatusMeta(string $tab, array $config): array
    {
        $ok = ['label' => 'OK'];
        $attention = ['label' => 'Atenção'];
        $info = ['label' => 'Revisar'];
        $build = static function (array $requiredFields, string $tone, array $base, string $summaryPrefix = 'Checklist principal') use ($config): array {
            $missing = [];
            foreach ($requiredFields as $field => $label) {
                $value = trim((string) ($config[$field] ?? ''));
                if ($value === '') {
                    $missing[] = (string) $label;
                }
            }
            $total = count($requiredFields);
            $okCount = $total - count($missing);
            $label = empty($missing) ? (string) ($base['label'] ?? 'OK') : (count($missing) . ' pend.');
            $summary = $summaryPrefix . ': ' . $okCount . '/' . $total . ' item(ns) principais preenchidos.';
            $resolvedTone = empty($missing) ? $tone : 'attention';

            return [
                'label' => $label,
                'tone' => $resolvedTone,
                'summary' => $summary,
                'missing' => $missing,
                'ok' => $okCount,
                'total' => $total,
            ];
        };

        return match ($tab) {
            'ambiente' => (function () use ($build, $config, $ok, $attention): array {
                $base = $build([
                    'environment' => 'Ambiente',
                    'certificate_path' => 'Certificado A1',
                ], 'ok', $ok, 'Ambiente técnico');
                if (($base['tone'] ?? '') === 'attention') {
                    return $base;
                }
                $cert = $this->evaluateCertificateFromConfig($config);
                $tone = (string) ($cert['tone'] ?? 'info');
                if ($tone === 'ok') {
                    $base['label'] = (string) ($cert['status_label'] ?? $base['label']);
                    $base['summary'] = (string) ($cert['summary'] ?? $base['summary']);
                    return $base;
                }
                $base['label'] = (string) ($cert['status_label'] ?? $attention['label']);
                $base['tone'] = 'attention';
                $base['summary'] = (string) ($cert['summary'] ?? $base['summary']);
                return $base;
            })(),
            'prestador' => $build([
                'cnpj_emissor' => 'CNPJ do emissor',
                'codigo_ibge' => 'Código IBGE',
                'prestador_nome' => 'Razão social',
                'prestador_email' => 'E-mail do prestador',
            ], 'ok', $ok, 'Cadastro do prestador'),
            'endereco' => $build([
                'prestador_logradouro' => 'Logradouro',
                'prestador_numero' => 'Número',
                'prestador_bairro' => 'Bairro',
                'prestador_cep' => 'CEP',
            ], 'ok', $ok, 'Endereço fiscal'),
            'tributacao' => $build([
                'codigo_servico' => 'Código de serviço',
                'nbs_padrao' => 'NBS padrão',
                'aliquota_iss' => 'Alíquota ISS',
                'serie_dps' => 'Série DPS',
            ], 'ok', $ok, 'Parâmetros tributários'),
            'servicosnbs' => (function () use ($ok, $attention, $info): array {
                try {
                    $catalogCount = (new ServiceNbsCatalogRepository())->countAll();
                } catch (\Throwable $e) {
                    $catalogCount = 0;
                }

                if ($catalogCount > 0) {
                    return [
                        'label' => $catalogCount . ' item(ns)',
                        'tone' => 'ok',
                        'summary' => 'Catálogo ativo com ' . $catalogCount . ' relação(ões) disponível(is) para Tributação e mapeamentos.',
                        'missing' => [],
                        'ok' => 1,
                        'total' => 1,
                    ];
                }

                return [
                    'label' => $attention['label'],
                    'tone' => 'attention',
                    'summary' => 'Cadastre ao menos uma relação de código de serviço e NBS para alimentar as demais telas.',
                    'missing' => ['Catálogo vazio'],
                    'ok' => 0,
                    'total' => 1,
                ];
            })(),
            'integracao' => $build([
                'tomador_cpfcnpj_customfield_id' => 'Custom field do CPF/CNPJ',
            ], 'ok', $ok, 'Integração principal'),
            'tomador' => $build([
                'tomador_codigo_ibge_padrao' => 'Código IBGE padrão',
                'tomador_numero_padrao' => 'Número padrão',
            ], 'info', $info, 'Fallback do tomador'),
            'processamento' => ((string) ($config['queue_enabled'] ?? '0')) === '1'
                ? [
                    'label' => 'Automático',
                    'tone' => 'ok',
                    'summary' => 'Fila/cron habilitados com intervalo de consulta configurado.',
                    'missing' => trim((string) ($config['queue_wait_status_interval_seconds'] ?? '')) === '' ? ['Intervalo de consulta'] : [],
                    'ok' => trim((string) ($config['queue_wait_status_interval_seconds'] ?? '')) !== '' ? 2 : 1,
                    'total' => 2,
                ]
                : [
                    'label' => 'Manual',
                    'tone' => 'info',
                    'summary' => 'Processamento automático desativado; emissão depende de ação manual.',
                    'missing' => [],
                    'ok' => 0,
                    'total' => 1,
                ],
            'atualizacoes' => (function () use ($config): array {
                $update = (new UpdateCheckService())->getStatus($config);
                $state = (string) ($update['status'] ?? '');
                $tone = match (true) {
                    $state === 'ok' && empty($update['update_available']) => 'ok',
                    $state === 'ok', $state === 'error' => 'attention',
                    default => 'info',
                };

                return [
                    'label' => match (true) {
                        $state === 'ok' && !empty($update['update_available']) => 'Nova versão',
                        $state === 'error' => 'Falha',
                        default => (string) ($update['label'] ?? 'Revisar'),
                    },
                    'tone' => $tone,
                    'summary' => (string) ($update['summary'] ?? ''),
                    'missing' => [],
                    'ok' => 0,
                    'total' => 0,
                ];
            })(),
            'retencao' => $build([
                'queue_done_retention_days' => 'Retenção da fila DONE/RESOLVIDO',
                'logs_retention_days' => 'Retenção de logs',
            ], 'info', $info, 'Política de retenção'),
            'sequenciais' => [
                'label' => 'Operacional',
                'tone' => 'info',
                'summary' => 'Área operacional para ajuste fino da numeração dos DPS.',
                'missing' => [],
                'ok' => 0,
                'total' => 0,
            ],
            'codigos' => [
                'label' => 'Mapeamento',
                'tone' => 'info',
                'summary' => 'Revise o mapeamento sempre que houver novos grupos de produtos ou serviços.',
                'missing' => [],
                'ok' => 0,
                'total' => 0,
            ],
            'danfse' => (function () use ($config, $ok, $attention): array {
                $defaults = $this->getDefaultDanfseConfig();
                $required = [
                    'danfse_logo_svg' => (string) (($config['danfse_logo_svg'] ?? '') !== '' ? $config['danfse_logo_svg'] : $defaults['danfse_logo_svg']),
                    'danfse_municipio_nome' => (string) (($config['danfse_municipio_nome'] ?? '') !== '' ? $config['danfse_municipio_nome'] : $defaults['danfse_municipio_nome']),
                    'danfse_secretaria_nome' => (string) (($config['danfse_secretaria_nome'] ?? '') !== '' ? $config['danfse_secretaria_nome'] : $defaults['danfse_secretaria_nome']),
                    'danfse_telefone' => (string) (($config['danfse_telefone'] ?? '') !== '' ? $config['danfse_telefone'] : $defaults['danfse_telefone']),
                    'danfse_email' => (string) (($config['danfse_email'] ?? '') !== '' ? $config['danfse_email'] : $defaults['danfse_email']),
                ];
                $missing = [];
                foreach ($required as $label => $value) {
                    if (trim($value) === '') {
                        $missing[] = $label;
                    }
                }
                if (empty($missing)) {
                    return [
                        'label' => 'PDF pronto',
                        'tone' => 'ok',
                        'summary' => 'Cabeçalho do DANFS-e configurado com logo SVG, dados institucionais e aviso automático para homologação.',
                        'missing' => [],
                        'ok' => 1,
                        'total' => 1,
                    ];
                }
                return [
                    'label' => $attention['label'],
                    'tone' => 'attention',
                    'summary' => 'Revise os dados institucionais e o logo SVG usados no cabeçalho do DANFS-e.',
                    'missing' => ['Cabeçalho do PDF'],
                    'ok' => 0,
                    'total' => 1,
                ];
            })(),
            default => [
                'label' => $info['label'],
                'tone' => 'info',
                'summary' => '',
                'missing' => [],
                'ok' => 0,
                'total' => 0,
            ],
        };
    }


    public function getDefaultClientAreaNoticeMessage(): string
    {
        return 'A partir de 01/07/2026 mudamos nosso sistema de emissão de NFS-e para nova API da Nota Nacional. Portanto serão exibidas apenas as notas emitidas depois desta data.' . PHP_EOL . PHP_EOL . 'Caso precise do PDF ou XML de alguma nota fiscal anterior a esta data, solicite via https://my.minivps.com.br/submitticket.php';
    }

}
