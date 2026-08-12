<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Module;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\PaymentGatewaySettingsRepository;
use OpenNfse\Repositories\QueueRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use OpenNfse\Services\InvoiceEmailService;
use OpenNfse\Services\InvoiceHistoryService;
use OpenNfse\Services\NfseService;
use OpenNfse\Services\QueueService;
use OpenNfse\Services\StorageService;
use OpenNfse\Services\TokenService;
use OpenNfse\Controllers\Support\AdminHelpersTrait;

final class NotasController
{
    use AdminHelpersTrait;


    public function showCancelForm(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            Module::ui()->renderError('Invoice inválida.');
            return;
        }

        $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
        if (!$nota) {
            Module::ui()->renderError('Nota não encontrada para esta fatura.');
            return;
        }

        $status = (string) ($nota['status'] ?? '');
        $chave = (string) ($nota['chave_acesso'] ?? '');
        if ($status !== 'EMITIDA' || $chave === '') {
            Module::ui()->renderError('Cancelamento disponível apenas quando a nota está EMITIDA com chave de acesso.');
            return;
        }

        Module::ui()->renderHeader('Cancelar NFS-e');
        $this->renderTabs('relatorios');

        $token = (new TokenService())->token();
        $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;

        echo '<div style="margin-bottom:10px;"><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">Voltar para a fatura</a></div>';

        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=cancel">';
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
        }
        echo '<input type="hidden" name="invoiceid" value="' . (int) $invoiceId . '" />';
        $return = (string) ($_REQUEST['return'] ?? '');
        if ($return !== '') {
            echo '<input type="hidden" name="return" value="' . htmlspecialchars($return, ENT_QUOTES, 'UTF-8') . '" />';
        }
        foreach (['return_invoiceid', 'return_status', 'return_data_inicial', 'return_data_final', 'return_updated_from', 'return_updated_to'] as $k) {
            $v = (string) ($_REQUEST[$k] ?? '');
            if ($v !== '') {
                echo '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '" />';
            }
        }

        echo '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">';
        echo '<tr><td class="fieldlabel" width="25%">Código do motivo</td><td class="fieldarea"><select id="codigo_motivo" name="codigo_motivo" class="form-control">';
        echo '<option value="1">1 - Erro na emissão da NFS-e (será emitida outra nota correta)</option>';
        echo '<option value="2">2 - Serviço não prestado / cancelamento da prestação</option>';
        echo '<option value="9">9 - NFS-e emitida indevidamente</option>';
        echo '</select></td></tr>';
        echo '<tr><td class="fieldlabel" width="25%">Motivo</td><td class="fieldarea"><input id="motivo" type="text" name="motivo" class="form-control" value="Erro na emissão" /></td></tr>';
        echo '<tr><td class="fieldlabel" width="25%">Descrição</td><td class="fieldarea"><input id="descricao" type="text" name="descricao" class="form-control" value="NFS-e cancelada em razão de erro identificado na emissão. Será emitida nova nota fiscal com os dados corretos." /></td></tr>';
        echo '</table>';

        echo '<script>';
        echo '(function(){';
        echo 'var map={';
        echo '"1":{m:"Erro na emissão",d:"NFS-e cancelada em razão de erro identificado na emissão. Será emitida nova nota fiscal com os dados corretos."},';
        echo '"2":{m:"Serviço não prestado",d:"NFS-e cancelada porque o serviço não foi prestado ao tomador, não gerando efeitos fiscais para a operação."},';
        echo '"9":{m:"Emissão indevida",d:"NFS-e cancelada por ter sido emitida indevidamente, não correspondendo à operação efetivamente realizada."}';
        echo '};';
        echo 'var sel=document.getElementById("codigo_motivo");';
        echo 'var motivo=document.getElementById("motivo");';
        echo 'var desc=document.getElementById("descricao");';
        echo 'if(!sel||!motivo||!desc){return;}';
        echo 'function apply(){var v=sel.value; if(!map[v]){return;} motivo.value=map[v].m; desc.value=map[v].d;}';
        echo 'sel.addEventListener("change", apply);';
        echo '})();';
        echo '</script>';

        echo '<p><button type="submit" class="btn btn-danger" onclick="return confirm(\'Cancelar NFS-e é uma operação fiscal. Continuar?\');">Cancelar NFS-e</button></p>';
        echo '</form>';

        Module::ui()->renderFooter();
    }


    public function cancelNfse(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            Module::ui()->renderError('Invoice inválida.');
            return;
        }
        $codigo = trim((string) ($_REQUEST['codigo_motivo'] ?? ''));
        $motivo = trim((string) ($_REQUEST['motivo'] ?? ''));
        $descricao = trim((string) ($_REQUEST['descricao'] ?? ''));
        if ($codigo === '' || $motivo === '' || $descricao === '') {
            Module::ui()->renderError('Preencha código do motivo, motivo e descrição.');
            return;
        }

        $return = (string) ($_REQUEST['return'] ?? '');
        try {
            (new NfseService())->cancelarNfse($invoiceId, $codigo, $motivo, $descricao);
            if ($return === 'emitidas') {
                $this->redirectRelatorioEmitidas(['msg' => 'cancel_done'], true);
            }
            $this->redirectInvoice($invoiceId, ['nfse_cancel' => 'done']);
        } catch (\Throwable $e) {
            if ($return === 'emitidas') {
                $this->redirectRelatorioEmitidas(['msg' => 'cancel_error'], true);
            }
            $this->redirectInvoice($invoiceId, ['nfse_cancel' => 'error']);
        }
    }


    public function downloadXml(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            throw new NfseModuleException('Invoice inválida.');
        }

        $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
        if (!$nota) {
            throw new NfseModuleException('Nota não encontrada para esta fatura.');
        }

        $xmlPath = (string) ($nota['xml_path'] ?? '');
        if ($xmlPath === '') {
            throw new NfseModuleException('XML ainda não disponível.');
        }

        $storage = new StorageService();
        $absPath = $storage->resolveAbsolutePath($xmlPath);
        $filename = basename($absPath);

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($absPath));
        readfile($absPath);
        exit;
    }


    public function downloadPdf(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            throw new NfseModuleException('Fatura inválida.');
        }

        $pdfService = new \OpenNfse\Services\DanfsePdfService();
        $pdfBytes = $pdfService->generatePdfBytes($invoiceId);
        $size = strlen($pdfBytes);
        if ($size <= 0) {
            throw new NfseModuleException('PDF não disponível.');
        }
        $filename = $pdfService->getDownloadFilename($invoiceId);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) $size);
        echo $pdfBytes;
        exit;
    }


    public function sendEmail(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            throw new NfseModuleException('Invoice inválida.');
        }

        try {
            (new InvoiceEmailService())->sendToClient($invoiceId);
            $this->redirectInvoice($invoiceId, ['nfse_email' => 'done']);
        } catch (\Throwable $e) {
            $this->redirectInvoice($invoiceId, ['nfse_email' => 'error']);
        }
    }


    public function reemitir(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        if ((string) ($_REQUEST['confirm'] ?? '') !== '1') {
            Module::ui()->renderError('Confirmação ausente para reemitir.');
            return;
        }
        $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
        if ($nota) {
            $status = (string) ($nota['status'] ?? '');
            if (in_array($status, ['EMITIDA', 'PROCESSANDO'], true)) {
                Module::ui()->renderError('Não é permitido reemitir quando o status está EMITIDA ou PROCESSANDO.');
                return;
            }
        }
        $config = (new ConfigRepository())->get();
        $return = (string) ($_REQUEST['return'] ?? '');
        $origin = $this->resolveAdminActionOrigin($return);

        try {
            if ((new QueueRepository())->hasActive($invoiceId)) {
                $this->logAdminAction(
                    $nota,
                    $invoiceId,
                    'ADMIN_REEMITIR_BLOCKED',
                    ['origin' => $origin, 'reason' => 'active_queue']
                );
                if ($return === 'emitidas') {
                    $this->redirectRelatorioEmitidas(['msg' => 'reemitir_error'], true);
                }
                $this->redirectInvoice($invoiceId, ['nfse_reemit' => 'queue_active']);
                return;
            }

            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
            $paymentMethod = strtolower(trim((string) ($invoice['paymentmethod'] ?? '')));
            if ($paymentMethod !== '' && !(new PaymentGatewaySettingsRepository())->isEnabled($paymentMethod)) {
                $this->logAdminAction(
                    $nota,
                    $invoiceId,
                    'ADMIN_REEMITIR_BLOCKED',
                    ['origin' => $origin, 'reason' => 'gateway_disabled', 'gateway' => $paymentMethod]
                );
                if ($return === 'emitidas') {
                    $this->redirectRelatorioEmitidas(['msg' => 'reemitir_gateway_disabled'], true);
                }
                $this->redirectInvoice($invoiceId, ['nfse_reemit' => 'gateway_disabled']);
                return;
            }
            if ((string) ($config['queue_enabled'] ?? '0') === '1') {
                $this->logAdminAction(
                    $nota,
                    $invoiceId,
                    'ADMIN_REEMITIR_ENQUEUE',
                    ['origin' => $origin]
                );
                (new QueueService())->enqueueEmit($invoiceId, 'QUEUE_REEMITIR_ADMIN');
                (new InvoiceHistoryService())->append(
                    $invoiceId,
                    'Reemissão manual enfileirada pelo administrador via ' . $this->describeAdminActionOrigin($origin) . '.'
                );
                if ($return === 'emitidas') {
                    $this->redirectRelatorioEmitidas(['msg' => 'reemitir_enqueued'], true);
                }
                $this->redirectInvoice($invoiceId, ['nfse_reemit' => 'enqueued']);
                return;
            }

            $this->logAdminAction(
                $nota,
                $invoiceId,
                'ADMIN_REEMITIR_DIRECT',
                ['origin' => $origin]
            );
            (new NfseService())->emitir($invoiceId);
            (new InvoiceHistoryService())->append(
                $invoiceId,
                'Reemissão manual solicitada pelo administrador via ' . $this->describeAdminActionOrigin($origin) . '.'
            );
            if ($return === 'emitidas') {
                $this->redirectRelatorioEmitidas(['msg' => 'reemitir_requested'], true);
            }
            $this->redirectInvoice($invoiceId, ['nfse_reemit' => 'requested']);
            return;
        } catch (\Throwable $e) {
            $this->logAdminAction(
                $nota,
                $invoiceId,
                'ADMIN_REEMITIR_ERROR',
                ['origin' => $origin],
                $e->getMessage()
            );
            if ($return === 'emitidas') {
                $this->redirectRelatorioEmitidas(['msg' => 'reemitir_error'], true);
            }
            $this->redirectInvoice($invoiceId, ['nfse_reemit' => 'error']);
            return;
        }
    }

    public function emit(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            Module::ui()->renderError('Invoice inválida.');
            return;
        }
        $config = (new ConfigRepository())->get();
        try {
            $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
            if ($this->shouldBlockDirectEmit($nota)) {
                $this->redirectInvoice($invoiceId, ['nfse_emit' => 'already_linked']);
            }

            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
            $paymentMethod = strtolower(trim((string) ($invoice['paymentmethod'] ?? '')));
            $invoiceStatus = strtolower(trim((string) ($invoice['status'] ?? '')));
            if ($invoiceStatus !== 'paid') {
                $this->redirectInvoice($invoiceId, ['nfse_emit' => 'not_paid']);
            }
            if ((new \OpenNfse\Services\InvoiceFinancialsService())->isCreditOnlyPayment($invoice)) {
                $this->redirectInvoice($invoiceId, ['nfse_emit' => 'credit_payment']);
            }
            if ($paymentMethod !== '' && !(new PaymentGatewaySettingsRepository())->isEnabled($paymentMethod)) {
                $this->redirectInvoice($invoiceId, ['nfse_emit' => 'gateway_disabled']);
            }
            if ((string) ($config['queue_enabled'] ?? '0') === '1') {
                $queueRepo = new QueueRepository();
                if ($queueRepo->hasActive($invoiceId)) {
                    $this->redirectInvoice($invoiceId, ['nfse_emit' => 'already_queued']);
                }
                (new QueueService())->enqueueEmit($invoiceId, 'QUEUE_ENQUEUE_MANUAL');
                $this->redirectInvoice($invoiceId, ['nfse_emit' => 'enqueued']);
            }

            (new NfseService())->emitir($invoiceId);
            $this->redirectInvoice($invoiceId, ['nfse_emit' => 'requested']);
        } catch (\Throwable $e) {
            $this->redirectInvoice($invoiceId, ['nfse_emit' => 'error', 'nfse_emit_detail' => $e->getMessage()]);
        }
    }

    private function shouldBlockDirectEmit(?array $nota): bool
    {
        if (!$nota) {
            return false;
        }

        $status = trim((string) ($nota['status'] ?? ''));
        if ($status === 'CANCELADA') {
            return false;
        }

        $idDps = trim((string) ($nota['id_dps'] ?? ''));
        $chave = trim((string) ($nota['chave_acesso'] ?? ''));

        return $idDps !== ''
            || $chave !== ''
            || in_array($status, ['PROCESSANDO', 'EMITIDA', 'ERRO', 'REJEITADA'], true);
    }

    private function resolveAdminActionOrigin(string $return): string
    {
        return $return === 'emitidas' ? 'relatorio_emitidas' : 'invoice';
    }

    private function describeAdminActionOrigin(string $origin): string
    {
        return $origin === 'relatorio_emitidas' ? 'Relatorios > NFS-e Emitidas' : 'a propria invoice';
    }

    private function logAdminAction(?array $nota, int $invoiceId, string $type, array $request = [], ?string $response = null): void
    {
        $notaId = $nota ? (int) ($nota['id'] ?? 0) : null;
        (new LogRepository())->insert(
            $notaId > 0 ? $notaId : null,
            $type,
            json_encode(['invoiceid' => $invoiceId] + $request, JSON_UNESCAPED_UNICODE),
            $response
        );
    }



    public function status(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            Module::ui()->renderError('Invoice inválida.');
            return;
        }

        $return = (string) ($_REQUEST['return'] ?? '');
        try {
            (new NfseService())->consultarStatus($invoiceId);

            if ($return === 'emitidas') {
                $this->redirectRelatorioEmitidas(['msg' => 'status_done'], true);
            }

            $this->redirectInvoice($invoiceId, ['nfse_status' => 'done']);
        } catch (\Throwable $e) {
            if ($return === 'emitidas') {
                $this->redirectRelatorioEmitidas(['msg' => 'status_error'], true);
            }
            $this->redirectInvoice($invoiceId, ['nfse_status' => 'error']);
        }
    }
}
