<?php

declare(strict_types=1);

use OpenNfse\Module;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Updater\HookGate;
use OpenNfse\Updater\MaintenanceMode;
use OpenNfse\Views\AdminNav;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__ . '/bootstrap.php';

// Durante a atualização (MaintenanceMode), os hooks de fatura guardam o evento para depois
// (HookGate) e os de tela não mostram nada.
add_hook('AdminInvoicesControlsOutput', 1, static function (array $vars) {
    if (MaintenanceMode::isPaused()) {
        return '';
    }
    return Module::invoiceHook()->renderControls($vars);
});

add_hook('AdminAreaHeadOutput', 1, static function (array $vars) {
    $base = '';
    if (isset($vars['systemurl']) && is_string($vars['systemurl'])) {
        $base = rtrim($vars['systemurl'], '/');
    } elseif (isset($GLOBALS['CONFIG']['SystemURL']) && is_string($GLOBALS['CONFIG']['SystemURL'])) {
        $base = rtrim((string) $GLOBALS['CONFIG']['SystemURL'], '/');
    }
    // Nas telas do addon, esconde o título "OpenNfse" do WHMCS: a barra de navegação já mostra o nome.
    $filename = isset($vars['filename']) && is_string($vars['filename'])
        ? $vars['filename']
        : (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $titleStyle = AdminNav::moduleTitleStyle($filename, $_GET);
    if ($base === '') {
        return $titleStyle;
    }
    return '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars($base . '/modules/addons/OpenNfse/assets/nfse-ui.css', ENT_QUOTES, 'UTF-8') . '?v=36" />' . $titleStyle;
});

add_hook('InvoicePaid', 1, static function (array $vars) {
    HookGate::run('invoice_paid_emit', $vars);
});

// Emissão na geração da fatura. Observado no WHMCS 9 (ambiente de testes): o admin não chama
// InvoiceCreated; o "Publish and Send Email" chama InvoiceCreationPreEmail e o "Publish" sem e-mail
// não chama hook útil (o InvoiceCreation registra uma conferência, e o cron trata a fatura depois).
// O PreEmail só age na fatura com conferência registrada (não reavalia reenvio de e-mail).
add_hook('InvoiceCreated', 1, static function (array $vars) {
    HookGate::run('invoice_created', $vars);
});

add_hook('InvoiceCreationPreEmail', 1, static function (array $vars) {
    HookGate::run('invoice_pre_email', $vars);
});

add_hook('InvoiceCreation', 1, static function (array $vars) {
    HookGate::run('invoice_creation', $vars);
});

// Alertas de NFS-e (só observam; nunca cancelam nota nem bloqueiam o fluxo do WHMCS).
add_hook('InvoicePaid', 2, static function (array $vars) {
    HookGate::run('invoice_paid_alert', $vars);
});

add_hook('InvoiceCancelled', 1, static function (array $vars) {
    HookGate::run('invoice_cancelled', $vars);
});

add_hook('InvoiceRefunded', 1, static function (array $vars) {
    HookGate::run('invoice_refunded', $vars);
});

add_hook('InvoiceUnpaid', 1, static function (array $vars) {
    HookGate::run('invoice_unpaid', $vars);
});

add_hook('UpdateInvoiceTotal', 1, static function (array $vars) {
    HookGate::run('invoice_total', $vars);
});

add_hook('AddInvoiceLateFee', 1, static function (array $vars) {
    HookGate::run('invoice_late_fee', $vars);
});

add_hook('InvoiceSplit', 1, static function (array $vars) {
    HookGate::run('invoice_split', $vars);
});

add_hook('AddTransaction', 1, static function (array $vars) {
    HookGate::run('add_transaction', $vars);
});

add_hook('AfterCronJob', 1, static function (array $vars) {
    if (MaintenanceMode::isPaused()) {
        return;
    }
    try {
        Module::cron()->runFromWhmcsCron();
    } catch (Throwable $e) {
        try {
            (new LogRepository())->insert(
                null,
                'CRON_HOOK_ERROR',
                json_encode(['source' => 'whmcs_cron', 'vars' => array_keys($vars)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $e->getMessage()
            );
        } catch (Throwable $loggingError) {
        }
    }
});

add_hook('ClientAreaPageViewInvoice', 1, static function (array $vars) {
    if (MaintenanceMode::isPaused()) {
        return [];
    }
    return Module::clientInvoiceHook()->capturePageContext($vars);
});

add_hook('ClientAreaHeadOutput', 1, static function (array $vars) {
    if (MaintenanceMode::isPaused()) {
        return '';
    }
    $base = '';
    if (isset($vars['systemurl']) && is_string($vars['systemurl'])) {
        $base = rtrim($vars['systemurl'], '/');
    } elseif (isset($GLOBALS['CONFIG']['SystemURL']) && is_string($GLOBALS['CONFIG']['SystemURL'])) {
        $base = rtrim((string) $GLOBALS['CONFIG']['SystemURL'], '/');
    }
    if ($base === '') {
        return '';
    }
    return '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars($base . '/modules/addons/OpenNfse/assets/nfse-ui.css', ENT_QUOTES, 'UTF-8') . '?v=36" />';
});

add_hook('ClientAreaPrimaryNavbar', 1, static function ($primaryNavbar) {
    if (MaintenanceMode::isPaused()) {
        return;
    }
    try {
        if (!is_object($primaryNavbar) || !method_exists($primaryNavbar, 'getChild')) {
            return;
        }

        $billing = $primaryNavbar->getChild('Billing');
        if (!$billing || !is_object($billing) || !method_exists($billing, 'addChild')) {
            return;
        }

        if (method_exists($billing, 'getChild') && $billing->getChild('OpenNfse')) {
            return;
        }

        $order = 41;
        if (method_exists($billing, 'getChild')) {
            $myInvoices = $billing->getChild('My Invoices');
            if ($myInvoices && method_exists($myInvoices, 'getOrder')) {
                $order = (int) $myInvoices->getOrder() + 1;
            }
        }

        $billing->addChild('OpenNfse', [
            'label' => 'Notas Fiscais',
            'uri' => 'index.php?m=OpenNfse',
            'order' => $order,
        ]);
    } catch (Throwable $e) {
        return;
    }
});
