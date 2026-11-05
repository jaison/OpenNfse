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
use OpenNfse\Views\Ui;
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
            echo Ui::callout('ok', 'Sequência atualizada.');
        } elseif ($msg === 'seq_created') {
            echo Ui::callout('ok', 'Sequência criada.');
        } elseif ($msg === 'seq_error') {
            echo Ui::callout('danger', 'Erro ao salvar sequência.');
        }

        $token = (new TokenService())->token();
        $rows = Capsule::table('mod_opennfse_sequences')->orderBy('environment', 'asc')->orderBy('cnpj_emissor', 'asc')->orderBy('serie_dps', 'asc')->get();

        $this->renderConfigSectionStart('Sequências cadastradas', 'Ajuste o último número manualmente apenas quando houver necessidade operacional ou correção de ambiente.');
        echo '<div class="nfse-table-wrap"><table class="nfse-table nfse-cfg-table">';
        echo '<thead><tr><th scope="col">Ambiente</th><th scope="col">CNPJ</th><th scope="col">Série</th><th scope="col" class="nfse-num">Último</th><th scope="col">Ações</th></tr></thead><tbody>';
        $count = 0;
        foreach ($rows as $r) {
            $count++;
            $id = (int) ($r->id ?? 0);
            $env = (string) ($r->environment ?? '');
            $cnpj = (string) ($r->cnpj_emissor ?? '');
            $serie = (string) ($r->serie_dps ?? '');
            $last = (string) ($r->last_number ?? '');

            echo '<tr>';
            echo '<td>' . Ui::h($env) . '</td>';
            echo '<td class="nfse-nowrap"><span class="nfse-mono">' . Ui::h($cnpj) . '</span></td>';
            echo '<td>' . Ui::h($serie) . '</td>';
            echo '<td class="nfse-num">' . Ui::h($last) . '</td>';
            echo '<td>';
            echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=sequenciaisSet" class="nfse-inline-form nfse-actions">';
            if ($token !== '') {
                echo '<input type="hidden" name="token" value="' . Ui::h($token) . '" />';
            }
            echo '<input type="hidden" name="id" value="' . (int) $id . '" />';
            echo Ui::input('last_number', $last, 'number', ['min' => '0', 'aria-label' => 'Último número (' . $env . ', série ' . $serie . ')'], 'nfse-input--sm nfse-input--w-sm');
            echo Ui::button('Salvar', ['sm'], ['onclick' => 'return confirm(\'Ajustar o último número altera a sequência de DPS. Continuar?\');']);
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        if ($count === 0) {
            echo Ui::emptyRow(5, 'Nenhuma sequência cadastrada.');
        }
        echo '</tbody></table></div>';
        $this->renderConfigSectionEnd();

        $this->renderConfigSectionStart('Criar ou inicializar sequência', 'Use este formulário para cadastrar uma nova sequência de DPS ou reinicializar uma combinação ainda não criada.');
        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=sequenciaisInit" class="nfse-cfg-cardform">';
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . Ui::h($token) . '" />';
        }
        $this->renderConfigFormTableStart();
        $this->renderSelectRow('environment', 'Ambiente', [
            'homologacao' => 'homologacao',
            'producao' => 'producao',
        ], 'homologacao');
        $this->renderTextRow('cnpj_emissor', 'CNPJ Emissor', '');
        $this->renderTextRow('serie_dps', 'Série DPS', '');
        $lastId = $this->configFieldId('last_number');
        echo Ui::formRow(
            'Último Número',
            Ui::input('last_number', '0', 'number', ['id' => $lastId, 'min' => '0'], 'nfse-input--w-sm'),
            'Valor inicial da sequência. Use 0 para começar do primeiro número disponível.',
            ['id' => $lastId]
        );
        $this->renderConfigFormTableEnd();
        echo Ui::saveBar(Ui::button('Salvar', ['primary'], ['onclick' => 'return confirm(\'Inicializar uma sequência pode sobrescrever o último número se já existir. Continuar?\');']));
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
