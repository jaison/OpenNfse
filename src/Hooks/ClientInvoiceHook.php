<?php

declare(strict_types=1);

namespace OpenNfse\Hooks;

use OpenNfse\Helpers\ActionFormRenderer;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use OpenNfse\Services\TokenService;

final class ClientInvoiceHook
{
    public function capturePageContext(array $vars): array
    {
        $html = $this->buildHtml($vars);
        $response = [];

        if ($html !== '') {
            $payto = (string) ($vars['payto'] ?? '');
            $response['payto'] = $payto . '<div id="nfse-client-invoice-hook" class="nfse-mt-15">' . $html . '</div>';
        }

        return $response;
    }

    private function buildHtml(array $vars): string
    {
        $context = $this->resolveContext($vars);
        if ($context === null) {
            return '';
        }

        return $this->renderHtmlFromContext($context['invoiceId'], $context['nota']);
    }

    private function renderHtmlFromContext(int $invoiceId, array $nota): string
    {
        $token = (new TokenService())->token();
        $url = 'index.php?m=OpenNfse&action=downloadXml';
        $pdfUrl = 'index.php?m=OpenNfse&action=downloadPdf';
        $emailUrl = 'index.php?m=OpenNfse&action=sendEmail';
        $status = trim((string) ($nota['status'] ?? ''));
        $xmlPath = trim((string) ($nota['xml_path'] ?? ''));
        $emitidaEm = $this->formatDateTime((string) ($nota['emitida_em'] ?? ''));
        $canceladoEm = $this->formatDateTime((string) ($nota['cancelado_em'] ?? ''));
        $numeroNf = trim((string) ($nota['numero_nf'] ?? ''));
        $chave = trim((string) ($nota['chave_acesso'] ?? ''));
        $erro = trim((string) ($nota['erro_api'] ?? ''));

        $alertClass = 'alert-info';
        $statusClass = '';
        if ($status === 'EMITIDA') {
            $alertClass = 'alert-success';
            $statusClass = 'nfse-status-ok';
        } elseif (in_array($status, ['ERRO', 'REJEITADA', 'CANCELADA'], true)) {
            $alertClass = 'alert-danger';
            $statusClass = 'nfse-status-error';
        } elseif ($status === 'PROCESSANDO') {
            $alertClass = 'alert-warning';
        }

        $html = '';
        $emailMsg = (string) ($_REQUEST['nfse_email'] ?? '');
        if ($emailMsg === 'done') {
            $html .= '<div class="alert alert-success nfse-client-invoice-alert nfse-mt-15" style="margin-top:15px;">E-mail enviado com XML e PDF anexados.</div>';
        } elseif ($emailMsg === 'error') {
            $html .= '<div class="alert alert-danger nfse-client-invoice-alert nfse-mt-15" style="margin-top:15px;">Erro ao enviar o e-mail da NFS-e.</div>';
        }
        $html .= '<div class="alert ' . $alertClass . ' nfse-client-invoice-alert nfse-client-summary nfse-mt-15" style="margin-top:15px;">';
        $html .= '<div class="nfse-client-summary-line"><strong>NFS-e:</strong> <span class="' . htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($status !== '' ? $status : '-', ENT_QUOTES, 'UTF-8') . '</span></div>';
        $html .= '<div class="nfse-client-summary-line"><strong>Número:</strong> ' . htmlspecialchars($numeroNf !== '' ? $numeroNf : '-', ENT_QUOTES, 'UTF-8') . '</div>';
        if ($emitidaEm !== '-') {
            $html .= '<div class="nfse-client-summary-line"><strong>Emitida em:</strong> ' . htmlspecialchars($emitidaEm, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        if ($canceladoEm !== '-') {
            $html .= '<div class="nfse-client-summary-line"><strong>Cancelada em:</strong> ' . htmlspecialchars($canceladoEm, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        if ($chave !== '' && $status === 'EMITIDA') {
            $chaveShort = mb_strlen($chave, 'UTF-8') > 20 ? (mb_substr($chave, 0, 20, 'UTF-8') . '...') : $chave;
            $html .= '<div class="nfse-client-summary-line nfse-chave-line" style="display:block;margin-bottom:10px;padding-bottom:10px;"><strong>Chave:</strong> <span class="nfse-chave-wrap" style="margin-bottom:10px;"><span class="nfse-chave-short" title="' . htmlspecialchars($chave, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($chaveShort, ENT_QUOTES, 'UTF-8') . '</span> <button type="button" class="btn btn-xs btn-default nfse-btn-xs nfse-copy-btn nfse-mb-10" style="margin-bottom:10px;" data-nfse-copy="' . htmlspecialchars($chave, ENT_QUOTES, 'UTF-8') . '" onclick="(function(b){var t=b.getAttribute(&quot;data-nfse-copy&quot;)||&quot;&quot;;if(!t){return;}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(function(){b.innerText=&quot;Copiado&quot;;setTimeout(function(){b.innerText=&quot;Copiar&quot;;},1200);});return;}var ta=document.createElement(&quot;textarea&quot;);ta.value=t;ta.style.position=&quot;fixed&quot;;ta.style.left=&quot;-10000px&quot;;ta.style.top=&quot;-10000px&quot;;document.body.appendChild(ta);ta.focus();ta.select();try{document.execCommand(&quot;copy&quot;);b.innerText=&quot;Copiado&quot;;setTimeout(function(){b.innerText=&quot;Copiar&quot;;},1200);}catch(e){}document.body.removeChild(ta);})(this)">Copiar</button></span><br /></div>';
        }
        if ($erro !== '') {
            $html .= '<div class="nfse-client-summary-line"><strong>Detalhe:</strong> ' . htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        if ($status === 'EMITIDA' && $xmlPath !== '') {
            $html .= '<div class="nfse-client-summary-line nfse-client-actions">';
            $html .= ActionFormRenderer::render(
                $url,
                'display:inline-block;margin-right:6px;',
                [ActionFormRenderer::invoiceIdInput($invoiceId), ActionFormRenderer::tokenInput($token)],
                '<button type="submit" class="btn btn-xs btn-default nfse-btn-xs">Baixar XML</button>'
            );

            $html .= ActionFormRenderer::render(
                $pdfUrl,
                'display:inline-block;margin-right:6px;',
                [ActionFormRenderer::invoiceIdInput($invoiceId), ActionFormRenderer::tokenInput($token)],
                '<button type="submit" class="btn btn-xs btn-default nfse-btn-xs">Baixar PDF</button>'
            );

            $html .= ActionFormRenderer::render(
                $emailUrl,
                'display:inline-block;',
                [ActionFormRenderer::invoiceIdInput($invoiceId), '<input type="hidden" name="return" value="viewinvoice" />', ActionFormRenderer::tokenInput($token)],
                '<button type="submit" class="btn btn-xs btn-primary nfse-btn-xs">Enviar por e-mail</button>'
            );
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function resolveContext(array $vars): ?array
    {
        $filename = trim((string) ($vars['filename'] ?? ''));
        if ($filename !== '' && !in_array($filename, ['viewinvoice', 'viewinvoice.php'], true)) {
            return null;
        }

        $invoiceId = (int) ($vars['invoiceid'] ?? $vars['id'] ?? $_REQUEST['id'] ?? 0);
        if ($invoiceId <= 0) {
            return null;
        }

        $clientId = (int) ($vars['userid'] ?? $vars['clientid'] ?? $vars['client_id'] ?? 0);
        if ($clientId <= 0 && isset($vars['clientsdetails']) && is_array($vars['clientsdetails'])) {
            $clientId = (int) ($vars['clientsdetails']['id'] ?? 0);
        }
        if ($clientId <= 0 && isset($_SESSION['cid'])) {
            $clientId = (int) $_SESSION['cid'];
        }

        try {
            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
            if ($clientId > 0 && (int) ($invoice['userid'] ?? 0) !== $clientId) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        try {
            $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
        } catch (\Throwable $e) {
            $nota = null;
        }
        if (!$nota) {
            return null;
        }

        return [
            'invoiceId' => $invoiceId,
            'nota' => $nota,
        ];
    }

    private function formatDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        try {
            return (new \DateTimeImmutable($value))->format('d/m/Y H:i');
        } catch (\Throwable $e) {
            return $value;
        }
    }
}
