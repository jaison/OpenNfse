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
use WHMCS\Database\Capsule;

trait AdminHelpersTrait
{
    public function redirectRelatorios(string $tab): void
    {
        $tab = trim($tab);
        $allowed = ['emitidas' => true, 'falhas' => true, 'cancelamentos' => true, 'logs' => true];
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
            if ($fStatus !== '') {
                $merged['status'] = $fStatus;
            }
            if ($fInvoice !== '') {
                $merged['invoiceid'] = $fInvoice;
            }
        }
        if (!empty($merged)) {
            $url .= '&' . http_build_query($merged, '', '&', PHP_QUERY_RFC3986);
        }
        header('Location: ' . $url);
        exit;
    }


    public function redirectNotas(array $params, bool $keepFilters): void
    {
        $url = 'addonmodules.php?module=OpenNfse&action=notas';
        $merged = $params;
        if ($keepFilters) {
            $fInvoice = trim((string) ($_REQUEST['return_invoiceid'] ?? $_REQUEST['invoiceid'] ?? ''));
            $fStatus = trim((string) ($_REQUEST['return_status'] ?? $_REQUEST['status'] ?? ''));
            $fFrom = trim((string) ($_REQUEST['return_updated_from'] ?? $_REQUEST['updated_from'] ?? ''));
            $fTo = trim((string) ($_REQUEST['return_updated_to'] ?? $_REQUEST['updated_to'] ?? ''));
            if ($fInvoice !== '') {
                $merged['invoiceid'] = $fInvoice;
            }
            if ($fStatus !== '') {
                $merged['status'] = $fStatus;
            }
            if ($fFrom !== '') {
                $merged['updated_from'] = $fFrom;
            }
            if ($fTo !== '') {
                $merged['updated_to'] = $fTo;
            }
        }
        if (!empty($merged)) {
            $url .= '&' . http_build_query($merged, '', '&', PHP_QUERY_RFC3986);
        }
        header('Location: ' . $url);
        exit;
    }


    public function renderTabs(string $active): void
    {
        $base = 'addonmodules.php?module=OpenNfse';
        $tabs = [
            'dashboard' => $base . '&action=dashboard',
            'notas' => $base . '&action=notas',
            'fila' => $base . '&action=fila',
            'relatorios' => $base . '&action=relatorios',
            'config' => $base . '&action=config',
        ];

        echo '<ul class="nav nav-tabs" style="margin:10px 0 15px 0;">';
        foreach ($tabs as $key => $url) {
            $label = match ($key) {
                'dashboard' => 'Dashboard',
                'notas' => 'Notas',
                'fila' => 'Fila',
                'relatorios' => 'Relatórios',
                default => 'Configurações',
            };
            $liClass = $key === $active ? ' class="active"' : '';
            echo '<li' . $liClass . '><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
        }
        echo '</ul>';
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


    public function renderConfigSectionStart(string $title, string $description = ''): void
    {
        echo '<div class="nfse-config-section">';
        echo '<div class="nfse-config-section-header">';
        echo '<strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong>';
        if ($description !== '') {
            echo '<span>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        echo '</div>';
        echo '<div class="nfse-config-section-body">';
    }


    public function renderConfigSectionEnd(): void
    {
        echo '</div>';
        echo '</div>';
    }


    public function renderConfigFormTableStart(): void
    {
        echo '<table class="nfse-config-form-table" border="0" cellspacing="0" cellpadding="0">';
    }


    public function renderConfigFormTableEnd(): void
    {
        echo '</table>';
    }


    public function renderConfigPaneHeader(string $title, string $description, array $status): void
    {
        $label = (string) ($status['label'] ?? 'Revisar');
        $summary = trim((string) ($status['summary'] ?? ''));
        $missing = is_array($status['missing'] ?? null) ? $status['missing'] : [];
        $bg = (string) ($status['bg'] ?? '#eef2f7');
        $color = (string) ($status['color'] ?? '#5f6b7a');

        echo '<div class="nfse-config-pane-header">';
        echo '<div class="nfse-config-pane-top">';
        echo '<div><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2><p>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p></div>';
        echo '<span class="nfse-config-tab-badge" style="background:' . htmlspecialchars($bg, ENT_QUOTES, 'UTF-8') . ';color:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        echo '</div>';
        if ($summary !== '' || !empty($missing)) {
            echo '<div class="nfse-config-pane-status">';
            if ($summary !== '') {
                echo '<span class="nfse-config-pane-status-text">' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            if (!empty($missing)) {
                $missingText = implode(', ', array_slice(array_map(static fn ($v): string => (string) $v, $missing), 0, 3));
                if (count($missing) > 3) {
                    $missingText .= ' e mais ' . (count($missing) - 3);
                }
                echo '<span class="nfse-config-pane-status-text">Pendências principais: ' . htmlspecialchars($missingText, ENT_QUOTES, 'UTF-8') . '.</span>';
            }
            echo '</div>';
        }
        echo '</div>';
    }


    public function renderTextRow(string $name, string $label, mixed $value): void
    {
        $inputClass = 'form-control nfse-config-input';
        if (in_array($name, ['certificate_path'], true)) {
            $inputClass .= ' nfse-config-mono';
        }
        echo '<tr>';
        echo '<td class="fieldlabel"><div class="nfse-config-label-title">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div></td>';
        echo '<td class="fieldarea">';
        echo '<input type="text" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($inputClass, ENT_QUOTES, 'UTF-8') . '" />';
        $help = $this->getConfigFieldHelp($name);
        if ($help !== '') {
            echo '<div class="nfse-config-help">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        echo '</td>';
        echo '</tr>';
    }


    public function renderPasswordRow(string $name, string $label, mixed $value): void
    {
        $id = 'nfse-field-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
        echo '<tr>';
        echo '<td class="fieldlabel"><div class="nfse-config-label-title">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div></td>';
        echo '<td class="fieldarea">';
        echo '<div class="nfse-config-inline-field">';
        echo '<input type="password" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '" class="form-control nfse-config-input" autocomplete="new-password" />';
        echo '<button type="button" class="btn btn-default" data-nfse-toggle-password="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">Mostrar</button>';
        echo '</div>';
        $help = $this->getConfigFieldHelp($name);
        if ($help !== '') {
            echo '<div class="nfse-config-help">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        echo '</td>';
        echo '</tr>';
    }


    public function renderSelectRow(string $name, string $label, array $options, string $selected): void
    {
        echo '<tr>';
        echo '<td class="fieldlabel"><div class="nfse-config-label-title">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div></td>';
        echo '<td class="fieldarea"><select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="form-control nfse-config-select">';
        foreach ($options as $value => $text) {
            $valueStr = (string) $value;
            $isSelected = $valueStr === $selected ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($valueStr, ENT_QUOTES, 'UTF-8') . '"' . $isSelected . '>' . htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';
        $help = $this->getConfigFieldHelp($name);
        if ($help !== '') {
            echo '<div class="nfse-config-help">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        echo '</td>';
        echo '</tr>';
    }


    public function renderCustomFieldSelectRow(string $name, string $label, string $selected): void
    {
        $fields = \WHMCS\Database\Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->orderBy('fieldname')
            ->get(['id', 'fieldname']);

        echo '<tr>';
        echo '<td class="fieldlabel"><div class="nfse-config-label-title">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div></td>';
        echo '<td class="fieldarea"><select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="form-control nfse-config-select">';
        echo '<option value="">Selecione...</option>';
        foreach ($fields as $field) {
            $id = (string) $field->id;
            $isSelected = $id === $selected ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . $isSelected . '>'
                . htmlspecialchars((string) $field->fieldname, ENT_QUOTES, 'UTF-8') . ' (#' . $id . ')</option>';
        }
        if ($selected !== '' && $selected !== '0' && !$fields->contains('id', (int) $selected)) {
            echo '<option value="' . htmlspecialchars($selected, ENT_QUOTES, 'UTF-8') . '" selected>#' . htmlspecialchars($selected, ENT_QUOTES, 'UTF-8') . ' (campo não encontrado)</option>';
        }
        echo '</select>';
        $help = $this->getConfigFieldHelp($name);
        if ($help !== '') {
            echo '<div class="nfse-config-help">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        echo '</td>';
        echo '</tr>';
    }


    public function renderTextareaRow(string $name, string $label, mixed $value): void
    {
        echo '<tr>';
        echo '<td class="fieldlabel"><div class="nfse-config-label-title">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div></td>';
        echo '<td class="fieldarea">';
        echo '<textarea name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="form-control nfse-config-input" rows="5" style="resize:vertical;">' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</textarea>';
        $help = $this->getConfigFieldHelp($name);
        if ($help !== '') {
            echo '<div class="nfse-config-help">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        echo '</td>';
        echo '</tr>';
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


    public function getConfigFieldHelp(string $name): string
    {
        $help = [
            'environment' => 'Use homologação para testes e produção somente quando toda a configuração fiscal já estiver validada.',
            'certificate_path' => 'Informe o caminho absoluto do arquivo .pfx disponível no servidor do WHMCS.',
            'certificate_password' => 'Preencha apenas quando desejar atualizar a senha armazenada para o certificado atual.',
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
            'prestador_reg_esp_trib' => 'Regime especial tributário, mantendo 0 quando não houver enquadramento específico.',
            'tomador_cpfcnpj_customfield_id' => 'ID do custom field do cliente que armazena CPF/CNPJ do tomador no WHMCS.',
            'client_area_notice_enabled' => 'Controla a exibição de um aviso fixo no topo da lista de NFS-e do cliente.',
            'client_area_notice_type' => 'Define o estilo visual do aviso mostrado na área do cliente.',
            'client_area_notice_message' => 'Mensagem exibida na lista de NFS-e do cliente. HTML não é permitido; URLs informadas no texto podem ser exibidas como links clicáveis.',
            'tomador_codigo_ibge_padrao' => 'Fallback para o código do município do tomador quando o cadastro do cliente não informar.',
            'tomador_numero_padrao' => 'Número padrão usado quando o endereço do tomador vier sem numeração.',
            'tomador_bairro_padrao' => 'Bairro padrão para completar cadastros incompletos do tomador.',
            'queue_enabled' => 'Habilita o processamento automático via fila e cron do módulo.',
            'auto_emit_on_payment' => 'Quando ativo, o módulo enfileira a emissão assim que a fatura é marcada como paga.',
            'queue_wait_status_interval_seconds' => 'Intervalo entre consultas de status quando a nota fica aguardando retorno do ambiente nacional.',
            'queue_done_retention_days' => 'Quantidade de dias para manter registros da fila concluídos antes da limpeza.',
            'logs_retention_days' => 'Quantidade de dias para manter logs de request/response antes da limpeza.',
            'danfse_logo_svg' => 'Cole aqui o SVG completo do brasão ou logotipo que deve aparecer no cabeçalho do DANFS-e.',
            'danfse_municipio_nome' => 'Linha principal exibida no bloco institucional do cabeçalho do PDF.',
            'danfse_secretaria_nome' => 'Linha secundária exibida abaixo do nome do município.',
            'danfse_telefone' => 'Telefone institucional mostrado no cabeçalho do DANFS-e.',
            'danfse_email' => 'E-mail institucional mostrado no cabeçalho do DANFS-e.',
        ];

        return (string) ($help[$name] ?? '');
    }


    public function getConfigTabStatusMeta(string $tab, array $config): array
    {
        $ok = ['label' => 'OK', 'bg' => '#e8f5e9', 'color' => '#2e7d32'];
        $attention = ['label' => 'Atenção', 'bg' => '#fff8e1', 'color' => '#8a6d3b'];
        $info = ['label' => 'Revisar', 'bg' => '#eef2f7', 'color' => '#5f6b7a'];
        $build = static function (array $requiredFields, string $tone, array $base, string $summaryPrefix = 'Checklist principal') use ($config, $attention): array {
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
            $resolvedBase = empty($missing) ? $base : $attention;

            return [
                'label' => $label,
                'bg' => (string) ($resolvedBase['bg'] ?? '#eef2f7'),
                'color' => (string) ($resolvedBase['color'] ?? '#5f6b7a'),
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
                $base['bg'] = $attention['bg'];
                $base['color'] = $attention['color'];
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
                        'bg' => $ok['bg'],
                        'color' => $ok['color'],
                        'tone' => 'ok',
                        'summary' => 'Catálogo ativo com ' . $catalogCount . ' relação(ões) disponível(is) para Tributação e mapeamentos.',
                        'missing' => [],
                        'ok' => 1,
                        'total' => 1,
                    ];
                }

                return [
                    'label' => $attention['label'],
                    'bg' => $attention['bg'],
                    'color' => $attention['color'],
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
                    'bg' => $ok['bg'],
                    'color' => $ok['color'],
                    'tone' => 'ok',
                    'summary' => 'Fila/cron habilitados com intervalo de consulta configurado.',
                    'missing' => trim((string) ($config['queue_wait_status_interval_seconds'] ?? '')) === '' ? ['Intervalo de consulta'] : [],
                    'ok' => trim((string) ($config['queue_wait_status_interval_seconds'] ?? '')) !== '' ? 2 : 1,
                    'total' => 2,
                ]
                : [
                    'label' => 'Manual',
                    'bg' => $info['bg'],
                    'color' => $info['color'],
                    'tone' => 'info',
                    'summary' => 'Processamento automático desativado; emissão depende de ação manual.',
                    'missing' => [],
                    'ok' => 0,
                    'total' => 1,
                ],
            'retencao' => $build([
                'queue_done_retention_days' => 'Retenção da fila DONE',
                'logs_retention_days' => 'Retenção de logs',
            ], 'info', $info, 'Política de retenção'),
            'sequenciais' => [
                'label' => 'Operacional',
                'bg' => $info['bg'],
                'color' => $info['color'],
                'tone' => 'info',
                'summary' => 'Área operacional para ajuste fino da numeração dos DPS.',
                'missing' => [],
                'ok' => 0,
                'total' => 0,
            ],
            'codigos' => [
                'label' => 'Mapeamento',
                'bg' => $info['bg'],
                'color' => $info['color'],
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
                        'bg' => $ok['bg'],
                        'color' => $ok['color'],
                        'tone' => 'ok',
                        'summary' => 'Cabeçalho do DANFS-e configurado com logo SVG, dados institucionais e aviso automático para homologação.',
                        'missing' => [],
                        'ok' => 1,
                        'total' => 1,
                    ];
                }
                return [
                    'label' => $attention['label'],
                    'bg' => $attention['bg'],
                    'color' => $attention['color'],
                    'tone' => 'attention',
                    'summary' => 'Revise os dados institucionais e o logo SVG usados no cabeçalho do DANFS-e.',
                    'missing' => ['Cabeçalho do PDF'],
                    'ok' => 0,
                    'total' => 1,
                ];
            })(),
            default => [
                'label' => $info['label'],
                'bg' => $info['bg'],
                'color' => $info['color'],
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
