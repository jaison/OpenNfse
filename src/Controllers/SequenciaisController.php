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
use OpenNfse\Services\InvoiceEmailService;
use OpenNfse\Services\NfseService;
use OpenNfse\Services\QueueErrorClassifierService;
use OpenNfse\Services\QueueService;
use OpenNfse\Services\StorageService;
use OpenNfse\Services\TokenService;
use WHMCS\Database\Capsule;
use OpenNfse\Controllers\Support\AdminHelpersTrait;

final class SequenciaisController
{
    use AdminHelpersTrait;

    public function showSequenciais(): void
    {
        header('Location: addonmodules.php?module=OpenNfse&action=config&tab=sequenciais');
        exit;
    }


    public function renderSequenciaisContent(): void
    {
        $msg = (string) ($_REQUEST['msg'] ?? '');
        if ($msg === 'seq_ok') {
            echo '<div class="successbox">Sequência atualizada.</div>';
        } elseif ($msg === 'seq_created') {
            echo '<div class="successbox">Sequência criada.</div>';
        } elseif ($msg === 'seq_error') {
            echo '<div class="errorbox">Erro ao salvar sequência.</div>';
        }

        $token = (new TokenService())->token();
        $rows = Capsule::table('mod_opennfse_sequences')->orderBy('environment', 'asc')->orderBy('cnpj_emissor', 'asc')->orderBy('serie_dps', 'asc')->get();

        $this->renderConfigSectionStart('Sequências cadastradas', 'Ajuste o último número manualmente apenas quando houver necessidade operacional ou correção de ambiente.');
        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr><th>Ambiente</th><th>CNPJ</th><th>Série</th><th>Último</th><th>Ações</th></tr>';
        foreach ($rows as $r) {
            $id = (int) ($r->id ?? 0);
            $env = (string) ($r->environment ?? '');
            $cnpj = (string) ($r->cnpj_emissor ?? '');
            $serie = (string) ($r->serie_dps ?? '');
            $last = (string) ($r->last_number ?? '');

            echo '<tr>';
            echo '<td>' . htmlspecialchars($env, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($cnpj, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($serie, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($last, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>';
            echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=sequenciaisSet" style="display:inline-block;margin-right:6px;">';
            if ($token !== '') {
                echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
            }
            echo '<input type="hidden" name="id" value="' . (int) $id . '" />';
            echo '<input type="number" name="last_number" value="' . htmlspecialchars($last, ENT_QUOTES, 'UTF-8') . '" style="width:120px;margin-right:6px;" min="0" />';
            echo '<button type="submit" class="btn btn-xs btn-default" onclick="return confirm(\'Ajustar o último número altera a sequência de DPS. Continuar?\');">Salvar</button>';
            echo '</form>';

            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
        $this->renderConfigSectionEnd();

        $this->renderConfigSectionStart('Criar ou inicializar sequência', 'Use este formulário para cadastrar uma nova sequência de DPS ou reinicializar uma combinação ainda não criada.');
        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=sequenciaisInit">';
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
        }
        $this->renderConfigFormTableStart();
        $this->renderSelectRow('environment', 'Ambiente', [
            'homologacao' => 'homologacao',
            'producao' => 'producao',
        ], 'homologacao');
        $this->renderTextRow('cnpj_emissor', 'CNPJ Emissor', '');
        $this->renderTextRow('serie_dps', 'Série DPS', '');
        echo '<tr><td class="fieldlabel"><div class="nfse-config-label-title">Último Número</div></td><td class="fieldarea"><input type="number" name="last_number" value="0" class="form-control nfse-config-input" min="0" /><div class="nfse-config-help">Valor inicial da sequência. Use 0 para começar do primeiro número disponível.</div></td></tr>';
        $this->renderConfigFormTableEnd();
        echo '<div style="padding:6px 0 2px 0;"><button type="submit" class="btn btn-primary" onclick="return confirm(\'Inicializar uma sequência pode sobrescrever o último número se já existir. Continuar?\');">Salvar</button></div>';
        echo '</form>';
        $this->renderConfigSectionEnd();
    }


    public function sequenciaisSet(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $last = trim((string) ($_POST['last_number'] ?? ''));
        if ($id <= 0 || $last === '' || !ctype_digit($last)) {
            Module::ui()->renderError('Parâmetros inválidos.');
            return;
        }
        try {
            Capsule::table('mod_opennfse_sequences')->where('id', $id)->update(['last_number' => (int) $last]);
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=sequenciais&msg=seq_ok');
            exit;
        } catch (\Throwable $e) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=sequenciais&msg=seq_error');
            exit;
        }
    }


    public function sequenciaisBump(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $add = trim((string) ($_POST['add'] ?? ''));
        if ($id <= 0 || $add === '' || !ctype_digit($add) || (int) $add <= 0) {
            Module::ui()->renderError('Parâmetros inválidos.');
            return;
        }
        try {
            Capsule::table('mod_opennfse_sequences')->where('id', $id)->update(['last_number' => Capsule::raw('last_number + ' . (int) $add)]);
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=sequenciais&msg=seq_ok');
            exit;
        } catch (\Throwable $e) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=sequenciais&msg=seq_error');
            exit;
        }
    }


    public function sequenciaisInit(): void
    {
        $environment = trim((string) ($_POST['environment'] ?? ''));
        $cnpj = preg_replace('/\D/', '', trim((string) ($_POST['cnpj_emissor'] ?? '')));
        $serie = trim((string) ($_POST['serie_dps'] ?? ''));
        $last = trim((string) ($_POST['last_number'] ?? ''));
        if (!in_array($environment, ['homologacao', 'producao'], true) || $cnpj === '' || strlen($cnpj) !== 14 || $serie === '' || $last === '' || !ctype_digit($last)) {
            Module::ui()->renderError('Parâmetros inválidos.');
            return;
        }

        $existing = Capsule::table('mod_opennfse_sequences')
            ->where('environment', $environment)
            ->where('cnpj_emissor', $cnpj)
            ->where('serie_dps', $serie)
            ->first();

        if ($existing) {
            try {
                Capsule::table('mod_opennfse_sequences')->where('id', (int) $existing->id)->update(['last_number' => (int) $last]);
                header('Location: addonmodules.php?module=OpenNfse&action=config&tab=sequenciais&msg=seq_ok');
                exit;
            } catch (\Throwable $e) {
                header('Location: addonmodules.php?module=OpenNfse&action=config&tab=sequenciais&msg=seq_error');
                exit;
            }
        }

        try {
            Capsule::table('mod_opennfse_sequences')->insert([
                'environment' => $environment,
                'cnpj_emissor' => $cnpj,
                'serie_dps' => $serie,
                'last_number' => (int) $last,
            ]);
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=sequenciais&msg=seq_created');
            exit;
        } catch (\Throwable $e) {
            header('Location: addonmodules.php?module=OpenNfse&action=config&tab=sequenciais&msg=seq_error');
            exit;
        }
    }

}
