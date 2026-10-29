<?php

declare(strict_types=1);

use OpenNfse\Module;
use OpenNfse\Repositories\LogRepository;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__ . '/bootstrap.php';

add_hook('AdminInvoicesControlsOutput', 1, static function (array $vars) {
    return Module::invoiceHook()->renderControls($vars);
});

add_hook('AdminAreaHeadOutput', 1, static function (array $vars) {
    $base = '';
    if (isset($vars['systemurl']) && is_string($vars['systemurl'])) {
        $base = rtrim($vars['systemurl'], '/');
    } elseif (isset($GLOBALS['CONFIG']['SystemURL']) && is_string($GLOBALS['CONFIG']['SystemURL'])) {
        $base = rtrim((string) $GLOBALS['CONFIG']['SystemURL'], '/');
    }
    if ($base === '') {
        return '';
    }
    return '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars($base . '/modules/addons/OpenNfse/assets/nfse-ui.css', ENT_QUOTES, 'UTF-8') . '?v=15" />';
});

add_hook('InvoicePaid', 1, static function (array $vars) {
    Module::invoicePaidHook()->handle($vars);
});

// Emissão na geração da fatura. No WHMCS 8.13 o admin não chama InvoiceCreated: o "Publish and Send
// Email" chama InvoiceCreationPreEmail e o "Publish" sem e-mail não chama hook útil (o InvoiceCreation
// registra uma conferência, e o cron trata a fatura depois).
add_hook('InvoiceCreated', 1, static function (array $vars) {
    Module::invoiceCreatedHook()->handle($vars);
});

add_hook('InvoiceCreationPreEmail', 1, static function (array $vars) {
    Module::invoiceCreatedHook()->handle($vars);
});

add_hook('InvoiceCreation', 1, static function (array $vars) {
    Module::invoiceCreatedHook()->registerCreation($vars);
});

// Alertas de NFS-e (só observam; nunca cancelam nota nem bloqueiam o fluxo do WHMCS).
add_hook('InvoicePaid', 2, static function (array $vars) {
    Module::invoiceAlertHook()->onPaid($vars);
});

add_hook('InvoiceCancelled', 1, static function (array $vars) {
    Module::invoiceAlertHook()->onCancelled($vars);
});

add_hook('InvoiceRefunded', 1, static function (array $vars) {
    Module::invoiceAlertHook()->onRefunded($vars);
});

add_hook('InvoiceUnpaid', 1, static function (array $vars) {
    Module::invoiceAlertHook()->onUnpaid($vars);
});

add_hook('UpdateInvoiceTotal', 1, static function (array $vars) {
    Module::invoiceAlertHook()->onTotalChanged($vars);
});

add_hook('AddInvoiceLateFee', 1, static function (array $vars) {
    Module::invoiceAlertHook()->onTotalChangedBy('AddInvoiceLateFee', $vars);
});

add_hook('InvoiceSplit', 1, static function (array $vars) {
    Module::invoiceAlertHook()->onTotalChangedBy('InvoiceSplit', $vars);
});

add_hook('AddTransaction', 1, static function (array $vars) {
    Module::invoiceAlertHook()->onTransaction($vars);
});

add_hook('AfterCronJob', 1, static function (array $vars) {
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
    return Module::clientInvoiceHook()->capturePageContext($vars);
});

add_hook('ClientAreaHeadOutput', 1, static function (array $vars) {
    $base = '';
    if (isset($vars['systemurl']) && is_string($vars['systemurl'])) {
        $base = rtrim($vars['systemurl'], '/');
    } elseif (isset($GLOBALS['CONFIG']['SystemURL']) && is_string($GLOBALS['CONFIG']['SystemURL'])) {
        $base = rtrim((string) $GLOBALS['CONFIG']['SystemURL'], '/');
    }
    if ($base === '') {
        return '';
    }
    return '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars($base . '/modules/addons/OpenNfse/assets/nfse-ui.css', ENT_QUOTES, 'UTF-8') . '?v=15" />';
});

add_hook('ClientAreaPrimaryNavbar', 1, static function ($primaryNavbar) {
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
