<?php

declare(strict_types=1);

use OpenNfse\Module;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Services\StorageService;
use OpenNfse\Services\InvoiceHistoryService;

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
    return '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars($base . '/modules/addons/OpenNfse/assets/nfse-ui.css', ENT_QUOTES, 'UTF-8') . '?v=7" />';
});

add_hook('InvoicePaid', 1, static function (array $vars) {
    Module::invoicePaidHook()->handle($vars);
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
    return '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars($base . '/modules/addons/OpenNfse/assets/nfse-ui.css', ENT_QUOTES, 'UTF-8') . '?v=7" />';
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

add_hook('EmailPreSend', 1, static function (array $vars) {
    $mergeFields = $vars['mergefields'] ?? [];
    if (!is_array($mergeFields)) {
        $mergeFields = [];
    }

    $relId = (int) ($vars['relid'] ?? 0);
    $fallbackMergeFields = [];
    if ($relId > 0 && isset($GLOBALS['opennfse_pending_email_attachments'][(string) $relId]) && is_array($GLOBALS['opennfse_pending_email_attachments'][(string) $relId])) {
        $fallbackMergeFields = $GLOBALS['opennfse_pending_email_attachments'][(string) $relId];
    }

    $isNfseEmailAttempt = (string) ($mergeFields['nfse_attach_files'] ?? '') === '1' || !empty($fallbackMergeFields);
    $notaId = null;
    if ($relId > 0) {
        try {
            $nota = (new NotaRepository())->findByInvoiceId($relId);
            $notaId = isset($nota['id']) ? (int) $nota['id'] : null;
        } catch (Throwable $e) {
            $notaId = null;
        }
    }

    $debugLog = static function (string $stage, array $payload) use ($relId, $notaId): void {
        $payload['relid'] = $relId;
        try {
            (new LogRepository())->insert(
                $notaId !== null && $notaId > 0 ? $notaId : null,
                'EMAIL_NFSE_HOOK_DEBUG',
                $stage,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } catch (Throwable $e) {
        }
    };

    if (!empty($fallbackMergeFields)) {
        $mergeFields = array_replace($fallbackMergeFields, $mergeFields);
    }

    if ((string) ($mergeFields['nfse_attach_files'] ?? '') !== '1') {
        if ($isNfseEmailAttempt) {
            $debugLog('skip_without_nfse_flag', [
                'messagename' => (string) ($vars['messagename'] ?? ''),
                'has_mergefields' => !empty($mergeFields),
                'has_fallback' => !empty($fallbackMergeFields),
            ]);
        }
        return [];
    }

    $debugLog('hook_entered', [
        'messagename' => (string) ($vars['messagename'] ?? ''),
        'mergefield_keys' => array_keys($mergeFields),
        'has_fallback' => !empty($fallbackMergeFields),
    ]);

    $storage = new StorageService();
    $attachments = [];
    $maxTotalBytes = 10 * 1024 * 1024;
    $maxFileBytes = 8 * 1024 * 1024;
    $totalBytes = 0;

    $xmlPath = trim((string) ($mergeFields['nfse_xml_path'] ?? ''));
    if ($xmlPath !== '') {
        try {
            $xmlAbsPath = $storage->resolveAbsolutePath($xmlPath);
            $xmlSize = @filesize($xmlAbsPath);
            if (!is_int($xmlSize) || $xmlSize < 0 || $xmlSize > $maxFileBytes || ($totalBytes + $xmlSize) > $maxTotalBytes) {
                $debugLog('abort_xml_size', [
                    'xml_path' => $xmlPath,
                    'xml_size' => $xmlSize,
                    'total_bytes' => $totalBytes,
                ]);
                return ['abortsend' => true];
            }
            $xmlData = @file_get_contents($xmlAbsPath);
            if ($xmlData !== false && $xmlData !== '') {
                $attachments[] = [
                    'filename' => basename($xmlAbsPath),
                    'data' => $xmlData,
                ];
                $totalBytes += $xmlSize;
            }
        } catch (Throwable $e) {
            $debugLog('abort_xml_exception', [
                'xml_path' => $xmlPath,
                'error' => $e->getMessage(),
            ]);
            return ['abortsend' => true];
        }
    }

    $pdfTempPath = trim((string) ($mergeFields['nfse_pdf_temp_path'] ?? ''));
    if ($pdfTempPath !== '') {
        $pdfRealPath = realpath($pdfTempPath);
        $tmpBase = realpath(sys_get_temp_dir());
        if ($pdfRealPath === false || $tmpBase === false || strpos($pdfRealPath, rtrim($tmpBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0 || !is_file($pdfRealPath)) {
            $debugLog('abort_pdf_path', [
                'pdf_temp_path' => $pdfTempPath,
                'pdf_real_path' => $pdfRealPath,
                'tmp_base' => $tmpBase,
            ]);
            return ['abortsend' => true];
        }
        try {
            $pdfSize = @filesize($pdfRealPath);
            if (!is_int($pdfSize) || $pdfSize < 0 || $pdfSize > $maxFileBytes || ($totalBytes + $pdfSize) > $maxTotalBytes) {
                $debugLog('abort_pdf_size', [
                    'pdf_path' => $pdfRealPath,
                    'pdf_size' => $pdfSize,
                    'total_bytes' => $totalBytes,
                ]);
                return ['abortsend' => true];
            }
            $pdfData = @file_get_contents($pdfRealPath);
            if ($pdfData !== false && $pdfData !== '') {
                $attachments[] = [
                    'filename' => trim((string) ($mergeFields['nfse_pdf_filename'] ?? '')) !== '' ? trim((string) ($mergeFields['nfse_pdf_filename'] ?? '')) : basename($pdfRealPath),
                    'data' => $pdfData,
                ];
                $totalBytes += $pdfSize;
            }
        } catch (Throwable $e) {
            $debugLog('abort_pdf_exception', [
                'pdf_path' => $pdfRealPath,
                'error' => $e->getMessage(),
            ]);
            return ['abortsend' => true];
        } finally {
            if (isset($pdfRealPath) && is_string($pdfRealPath) && $pdfRealPath !== '' && is_file($pdfRealPath)) {
                @unlink($pdfRealPath);
            }
        }
    }

    if (empty($attachments)) {
        $debugLog('no_attachments', [
            'xml_path' => (string) ($mergeFields['nfse_xml_path'] ?? ''),
            'pdf_temp_path' => (string) ($mergeFields['nfse_pdf_temp_path'] ?? ''),
        ]);
        if ($relId > 0) {
            (new InvoiceHistoryService())->append($relId, 'Diagnóstico: EmailPreSend executado sem anexos montados.');
        }
        return [];
    }

    $debugLog('attachments_ready', [
        'count' => count($attachments),
        'filenames' => array_map(static function (array $attachment): string {
            return (string) ($attachment['filename'] ?? '');
        }, $attachments),
        'total_bytes' => $totalBytes,
    ]);
    if ($relId > 0) {
        (new InvoiceHistoryService())->append($relId, 'Diagnóstico: EmailPreSend montou ' . count($attachments) . ' anexo(s) para envio.');
    }

    return ['attachments' => $attachments];
});
