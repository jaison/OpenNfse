<?php

declare(strict_types=1);

namespace OpenNfse\Hooks;

use OpenNfse\Helpers\ActionFormRenderer;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\PaymentGatewaySettingsRepository;
use OpenNfse\Repositories\QueueRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use OpenNfse\Services\NfseService;
use OpenNfse\Services\TokenService;

final class InvoiceHook
{
    public function renderControls(array $vars): string
    {
        if (isset($vars['filename']) && !in_array((string) $vars['filename'], ['invoices', 'invoices.php'], true)) {
            return '';
        }

        $invoiceId = (int) ($vars['invoiceid'] ?? $vars['id'] ?? ($_REQUEST['id'] ?? 0));
        if ($invoiceId <= 0) {
            return '';
        }

        $base = 'addonmodules.php?module=OpenNfse';
        $emitUrl = $base . '&action=emit';
        $statusUrl = $base . '&action=status';
        $downloadXmlUrl = $base . '&action=downloadXml';
        $downloadPdfUrl = $base . '&action=downloadPdf';
        $sendEmailUrl = $base . '&action=sendEmail';
        $cancelFormUrl = $base . '&action=cancelForm&invoiceid=' . $invoiceId;
        $token = (new TokenService())->token();
        $tokenInput = $token !== '' ? '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />' : '';

        $nota = null;
        try {
            $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
        } catch (\Throwable $e) {
            $nota = null;
        }
        $nota = $this->refreshAfterAutomaticStatusCheck($invoiceId, $nota);

        $gatewayEnabled = true;
        $paymentMethod = '';
        $invoiceStatus = '';
        $creditValue = 0.0;
        $invoiceTotal = 0.0;
        $isPaid = false;
        $isCreditPayment = false;
        try {
            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
            $paymentMethod = strtolower(trim((string) ($invoice['paymentmethod'] ?? '')));
            $invoiceStatus = (string) ($invoice['status'] ?? '');
            $isPaid = strtolower(trim($invoiceStatus)) === 'paid';
            $creditValue = (float) str_replace(',', '.', (string) ($invoice['credit'] ?? '0'));
            $invoiceTotal = (float) str_replace(',', '.', (string) ($invoice['total'] ?? '0'));
            $isCreditPayment = $paymentMethod === 'credit' || $creditValue > 0.00001;
            if ($paymentMethod !== '' && !(new PaymentGatewaySettingsRepository())->isEnabled($paymentMethod)) {
                $gatewayEnabled = false;
            }
        } catch (\Throwable $e) {
            $gatewayEnabled = true;
        }

        $queueActive = false;
        try {
            $queueActive = (new QueueRepository())->hasActive($invoiceId);
        } catch (\Throwable $e) {
            $queueActive = false;
        }

        $statusAtual = $nota ? (string) ($nota['status'] ?? '') : '';
        $emitDisabled = $queueActive || in_array($statusAtual, ['PROCESSANDO', 'EMITIDA'], true);
        $emitText = 'Emitir NFS-e';
        if ($queueActive) {
            $emitText = 'Emitir NFS-e (enfileirado)';
        } elseif ($statusAtual === 'PROCESSANDO') {
            $emitText = 'Emitir NFS-e (processando)';
        } elseif ($statusAtual === 'EMITIDA') {
            $emitText = 'Emitir NFS-e (emitida)';
        } elseif ($statusAtual === 'CANCELADA') {
            $emitText = 'Emitir NFS-e (nova emissão)';
        }
        $actionRowStyle = 'display:flex;flex-wrap:wrap;align-items:stretch;justify-content:center;margin:0 -5px;';
        $actionItemStyle = 'display:flex;align-items:stretch;margin:5px;';
        $actionFormStyle = 'display:block;margin:0;';
        $buttonStyle = 'display:flex;align-items:center;justify-content:center;margin:0;line-height:1.2;box-sizing:border-box;text-align:center;white-space:nowrap;padding:4px 12px;';
        $primaryButtonStyle = $buttonStyle . 'min-height:38px;padding:6px 16px;';
        $actionsDividerHtml = '<hr style="margin:10px 0;" />';
        $wrapAction = static function (string $content) use ($actionItemStyle): string {
            return '<div style="' . $actionItemStyle . '">' . $content . '</div>';
        };

        $html = '';
        $html .= '<div class="nfse-mt-10">';
        $emitMsg = (string) ($_REQUEST['nfse_emit'] ?? '');
        if ($emitMsg === 'enqueued') {
            $html .= '<div class="successbox">Emissão enfileirada. O cron processará em breve.</div>';
        } elseif ($emitMsg === 'already_queued') {
            $html .= '<div class="successbox">Emissão já está enfileirada. Aguarde o cron.</div>';
        } elseif ($emitMsg === 'requested') {
            $html .= '<div class="successbox">Emissão solicitada. Verifique o status e o XML na fatura.</div>';
        } elseif ($emitMsg === 'not_paid') {
            $html .= '<div class="errorbox">A emissão só pode ser solicitada quando a fatura estiver como Paid.</div>';
        } elseif ($emitMsg === 'credit_payment') {
            $html .= '<div class="errorbox">A emissão está desativada quando a fatura é paga com crédito.</div>';
        } elseif ($emitMsg === 'gateway_disabled') {
            $msg = 'Emissão desativada para o gateway de pagamento desta fatura.';
            if ($paymentMethod !== '') {
                $msg .= ' Gateway: ' . $paymentMethod . '.';
            }
            $html .= '<div class="errorbox">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
        } elseif ($emitMsg === 'error') {
            $detail = trim((string) ($_REQUEST['nfse_emit_detail'] ?? ''));
            $html .= '<div class="errorbox">Erro ao solicitar emissão.' . ($detail !== '' ? ' ' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') : ' Verifique os logs do módulo.') . '</div>';
        }

        $statusMsg = (string) ($_REQUEST['nfse_status'] ?? '');
        if ($statusMsg === 'done') {
            $html .= '<div class="successbox">Consulta de status executada.</div>';
        } elseif ($statusMsg === 'error') {
            $html .= '<div class="errorbox">Erro ao consultar status. Verifique os logs do módulo.</div>';
        }

        $cancelMsg = (string) ($_REQUEST['nfse_cancel'] ?? '');
        if ($cancelMsg === 'done') {
            $html .= '<div class="successbox">Cancelamento solicitado com sucesso.</div>';
        } elseif ($cancelMsg === 'error') {
            $html .= '<div class="errorbox">Erro ao cancelar NFS-e. Verifique os logs do módulo.</div>';
        }
        $emailMsg = (string) ($_REQUEST['nfse_email'] ?? '');
        if ($emailMsg === 'done') {
            $html .= '<div class="successbox">E-mail enviado ao cliente com XML e PDF anexados.</div>';
        } elseif ($emailMsg === 'error') {
            $html .= '<div class="errorbox">Erro ao enviar e-mail da NFS-e. Verifique os logs do módulo.</div>';
        }
        $primaryActions = '';
        if ($gatewayEnabled && $isPaid && !$isCreditPayment) {
            $disabled = $emitDisabled ? ' disabled="disabled"' : '';
            $primaryActions .= $wrapAction(ActionFormRenderer::render(
                $emitUrl,
                $actionFormStyle,
                [$tokenInput, ActionFormRenderer::invoiceIdInput($invoiceId)],
                '<button type="submit" class="btn btn-primary" style="' . $primaryButtonStyle . '"' . $disabled . '>' . htmlspecialchars($emitText, ENT_QUOTES, 'UTF-8') . '</button>'
            ));
        }
        if ($nota || $queueActive) {
            $primaryActions .= $wrapAction(ActionFormRenderer::render(
                $statusUrl,
                $actionFormStyle,
                [$tokenInput, ActionFormRenderer::invoiceIdInput($invoiceId)],
                '<button type="submit" class="btn btn-success" style="' . $primaryButtonStyle . '">Consultar Status</button>'
            ));
        }
        if ($nota && $statusAtual === 'EMITIDA' && (string) ($nota['chave_acesso'] ?? '') !== '') {
            $primaryActions .= $wrapAction('<a class="btn btn-danger" href="' . htmlspecialchars($cancelFormUrl, ENT_QUOTES, 'UTF-8') . '" style="' . $primaryButtonStyle . '">Cancelar NFS-e</a>');
        }
        if ($primaryActions !== '') {
            $html .= $actionsDividerHtml;
            $html .= '<div class="nfse-action-row" style="' . $actionRowStyle . '">' . $primaryActions . '</div>';
        }
        $html .= '</div>';

        if ($nota) {
            $html .= '<div class="nfse-mt-10">';
            $emitidaEmRaw = trim((string) ($nota['emitida_em'] ?? ''));
            $emitidaEmFmt = '-';
            if ($emitidaEmRaw !== '') {
                try {
                    $dt = new \DateTimeImmutable($emitidaEmRaw);
                    $emitidaEmFmt = $dt->format('d/m/Y H:i');
                } catch (\Throwable $e) {
                    $emitidaEmFmt = $emitidaEmRaw;
                }
            }

            $valorFmt = 'R$ ' . number_format($invoiceTotal, 2, ',', '.');
            $idDps = trim((string) ($nota['id_dps'] ?? ''));
            $chave = trim((string) ($nota['chave_acesso'] ?? ''));

            $statusLabel = $statusAtual !== '' ? $statusAtual : '-';
            $statusHtml = '<strong>' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</strong>';
            if ($statusAtual === 'EMITIDA') {
                $statusHtml = '<strong class="nfse-status-ok">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</strong>';
            } elseif ($statusAtual === 'CANCELADA') {
                $statusHtml = '<strong class="nfse-status-error">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</strong>';
            } elseif (in_array($statusAtual, ['REJEITADA', 'ERRO'], true)) {
                $statusHtml = '<strong class="nfse-status-error">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</strong>';
            }

            $showEmissao = !in_array($statusAtual, ['REJEITADA', 'ERRO'], true);
            $html .= '<div><strong>NFS-e:</strong> ' . $statusHtml;
            if ($showEmissao) {
                $html .= ' <strong>Valor:</strong> ' . htmlspecialchars($valorFmt, ENT_QUOTES, 'UTF-8');
            }
            if ($showEmissao) {
                $html .= ' <strong>Emitida em:</strong> ' . htmlspecialchars($emitidaEmFmt, ENT_QUOTES, 'UTF-8');
            }
            $html .= '</div>';
            $html .= '<div><strong>DPS:</strong> ' . htmlspecialchars($idDps !== '' ? $idDps : '-', ENT_QUOTES, 'UTF-8') . '</div>';
            if ($showEmissao) {
                $html .= '<div><strong>Chave:</strong> ' . htmlspecialchars($chave !== '' ? $chave : '-', ENT_QUOTES, 'UTF-8') . '</div>';
            }

            if (!empty($nota['erro_api'])) {
                $err = (string) $nota['erro_api'];
                $html .= '<div class="nfse-mt-6">';
                $html .= '<textarea readonly class="nfse-error-textarea">' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</textarea>';
                $html .= '</div>';
            }
            $hasXml = !empty($nota['xml_path']);
            $canUsePdf = $statusAtual === 'EMITIDA' && !empty($nota['xml_path']);
            if ($hasXml || $canUsePdf) {
                if ($primaryActions === '') {
                    $html .= $actionsDividerHtml;
                }
                $html .= '<div class="nfse-mt-8 nfse-action-row" style="' . $actionRowStyle . '">';
                if ($hasXml) {
                    $html .= $wrapAction(ActionFormRenderer::render(
                        $downloadXmlUrl,
                        $actionFormStyle,
                        [$tokenInput, ActionFormRenderer::invoiceIdInput($invoiceId)],
                        '<button type="submit" class="btn btn-xs btn-default nfse-btn-xs" style="' . $buttonStyle . '">Baixar XML</button>'
                    ));
                }
                if ($canUsePdf) {
                    $html .= $wrapAction(ActionFormRenderer::render(
                        $downloadPdfUrl,
                        $actionFormStyle,
                        [$tokenInput, ActionFormRenderer::invoiceIdInput($invoiceId)],
                        '<button type="submit" class="btn btn-xs btn-default nfse-btn-xs" style="' . $buttonStyle . '">Baixar PDF</button>'
                    ));

                    $html .= $wrapAction(ActionFormRenderer::render(
                        $sendEmailUrl,
                        $actionFormStyle,
                        [$tokenInput, ActionFormRenderer::invoiceIdInput($invoiceId)],
                        '<button type="submit" class="btn btn-xs btn-primary nfse-btn-xs" style="' . $buttonStyle . '">Enviar por e-mail</button>'
                    ));
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        return $html;
    }

    private function refreshAfterAutomaticStatusCheck(int $invoiceId, ?array $nota): ?array
    {
        static $processedInvoices = [];

        if (!$nota) {
            return $nota;
        }

        if (isset($processedInvoices[$invoiceId])) {
            return $nota;
        }

        $status = trim((string) ($nota['status'] ?? ''));
        $chave = trim((string) ($nota['chave_acesso'] ?? ''));
        if ($status !== 'EMITIDA' || $chave !== '') {
            return $nota;
        }

        $processedInvoices[$invoiceId] = true;

        try {
            (new NfseService())->consultarStatus($invoiceId);
        } catch (\Throwable $e) {
            return $nota;
        }

        try {
            return (new NotaRepository())->findByInvoiceId($invoiceId) ?: $nota;
        } catch (\Throwable $e) {
            return $nota;
        }
    }
}
