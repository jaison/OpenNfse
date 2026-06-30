<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Helpers\ActionFormRenderer;
use OpenNfse\Module;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use OpenNfse\Services\DanfsePdfService;
use OpenNfse\Services\InvoiceEmailService;
use OpenNfse\Services\StorageService;
use OpenNfse\Services\TokenService;
use WHMCS\Database\Capsule;

final class ClientController
{
    public function handle(string $action, array $vars): array
    {
        $userId = (int) ($vars['userid'] ?? $vars['clientid'] ?? $vars['client_id'] ?? 0);
        if ($userId <= 0 && isset($_SESSION['uid'])) {
            $userId = (int) $_SESSION['uid'];
        }
        if ($userId <= 0) {
            return [
                'pagetitle' => 'Notas Fiscais',
                'breadcrumb' => ['index.php?m=OpenNfse' => 'Notas Fiscais'],
                'requirelogin' => true,
                'templatefile' => 'clienthome',
                'vars' => [
                    'output' => '<div class="alert alert-danger">Acesso não autorizado.</div>',
                ],
            ];
        }

        Module::migrator()->up();

        if ($action === 'downloadXml') {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                throw new NfseModuleException('Método inválido para download do XML da NFS-e.');
            }
            (new TokenService())->validate();
            $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
            $this->downloadXml($invoiceId, $userId);
        }

        if ($action === 'downloadPdf') {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                throw new NfseModuleException('Método inválido para download do PDF da NFS-e.');
            }
            (new TokenService())->validate();
            $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
            $this->downloadPdf($invoiceId, $userId);
        }

        if ($action === 'sendEmail') {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                throw new NfseModuleException('Método inválido para envio por e-mail da NFS-e.');
            }
            (new TokenService())->validate();
            $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
            $this->sendEmail($invoiceId, $userId);
        }

        return $this->listNotasEmitidas($userId);
    }

    private function listNotasEmitidas(int $userId): array
    {
        $token = (new TokenService())->token();
        $config = (new ConfigRepository())->get();

        $rows = Capsule::table('mod_opennfse_notas as n')
            ->join('tblinvoices as i', 'i.id', '=', 'n.invoiceid')
            ->select([
                'n.invoiceid',
                'n.numero_nf',
                'n.competencia',
                'n.chave_acesso',
                'n.emitida_em',
                'n.updated_at',
                'n.xml_path',
            ])
            ->where('i.userid', $userId)
            ->where('n.status', 'EMITIDA')
            ->whereNotNull('n.xml_path')
            ->orderByRaw('n.competencia IS NULL, n.competencia DESC, n.emitida_em IS NULL, n.emitida_em DESC, n.updated_at DESC')
            ->limit(50)
            ->get();

        $html = '';
        $html .= $this->renderClientAreaNotice($config);
        $emailMsg = (string) ($_REQUEST['nfse_email'] ?? '');
        if ($emailMsg === 'done') {
            $html .= '<div class="alert alert-success">E-mail enviado com XML e PDF anexados.</div>';
        } elseif ($emailMsg === 'error') {
            $html .= '<div class="alert alert-danger">Erro ao enviar o e-mail da NFS-e.</div>';
        }
        if (!$rows || count($rows) === 0) {
            $html .= '<div class="alert alert-info">Nenhuma NFS-e emitida disponível para download.</div>';
            return [
                'pagetitle' => 'Notas Fiscais',
                'breadcrumb' => ['index.php?m=OpenNfse' => 'Notas Fiscais'],
                'requirelogin' => true,
                'templatefile' => 'clienthome',
                'vars' => [
                    'output' => $html,
                ],
            ];
        }

        $html .= '<div class="table-responsive nfse-client-table">';
        $html .= '<table class="table table-striped">';
        $html .= '<thead><tr><th>Fatura</th><th>Número NFS-e</th><th>Competência</th><th>Chave de Acesso da NFS-e</th><th>Emitida em</th><th></th></tr></thead>';
        $html .= '<tbody>';
        foreach ($rows as $r) {
            $invoiceId = (int) ($r->invoiceid ?? 0);
            $numero = (string) ($r->numero_nf ?? '');
            $competenciaRaw = (string) ($r->competencia ?? '');
            $competencia = '-';
            if ($competenciaRaw !== '') {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $competenciaRaw);
                $competencia = $dt ? $dt->format('m/Y') : $competenciaRaw;
            }
            $chave = (string) ($r->chave_acesso ?? '');
            $emitidaEmRaw = (string) ($r->emitida_em ?? '');
            $emitidaEm = '-';
            if ($emitidaEmRaw !== '') {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $emitidaEmRaw);
                $emitidaEm = $dt ? $dt->format('d/m/Y H:i') : $emitidaEmRaw;
            }
            $downloadUrl = 'index.php?m=OpenNfse&action=downloadXml';
            $downloadPdfUrl = 'index.php?m=OpenNfse&action=downloadPdf';
            $sendEmailUrl = 'index.php?m=OpenNfse&action=sendEmail';
            $invoiceUrl = 'viewinvoice.php?id=' . $invoiceId;

            $html .= '<tr>';
            $html .= '<td><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">#' . (int) $invoiceId . '</a></td>';
            $html .= '<td>' . htmlspecialchars($numero, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($competencia, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($chave, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($emitidaEm, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>';
            $html .= ActionFormRenderer::render(
                $downloadUrl,
                'display:inline-block;margin:0 4px 0 0;',
                [ActionFormRenderer::invoiceIdInput($invoiceId), ActionFormRenderer::tokenInput($token)],
                '<button type="submit" class="btn btn-xs btn-primary nfse-btn-xs">Baixar XML</button>'
            );

            $html .= ActionFormRenderer::render(
                $downloadPdfUrl,
                'display:inline-block;margin:0 4px 0 0;',
                [ActionFormRenderer::invoiceIdInput($invoiceId), ActionFormRenderer::tokenInput($token)],
                '<button type="submit" class="btn btn-xs btn-default nfse-btn-xs">Baixar PDF</button>'
            );

            $html .= ActionFormRenderer::render(
                $sendEmailUrl,
                'display:inline-block;margin:0;',
                [ActionFormRenderer::invoiceIdInput($invoiceId), ActionFormRenderer::tokenInput($token)],
                '<button type="submit" class="btn btn-xs btn-success nfse-btn-xs">Enviar por e-mail</button>'
            );
            $html .= '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return [
            'pagetitle' => 'Notas Fiscais',
            'breadcrumb' => ['index.php?m=OpenNfse' => 'Notas Fiscais'],
            'templatefile' => 'clienthome',
            'vars' => [
                'output' => $html,
            ],
        ];
    }

    private function renderClientAreaNotice(array $config): string
    {
        $enabled = (string) ($config['client_area_notice_enabled'] ?? '1');
        if ($enabled !== '1') {
            return '';
        }

        $type = trim((string) ($config['client_area_notice_type'] ?? 'warning'));
        $message = trim((string) ($config['client_area_notice_message'] ?? ''));
        if ($message === '') {
            $message = $this->getDefaultClientAreaNoticeMessage();
        }

        $alertClass = match ($type) {
            'info' => 'alert-info',
            'success' => 'alert-success',
            default => 'alert-warning',
        };

        return '<div class="alert ' . htmlspecialchars($alertClass, ENT_QUOTES, 'UTF-8') . '">' . $this->formatClientAreaNoticeMessage($message) . '</div>';
    }

    private function formatClientAreaNoticeMessage(string $message): string
    {
        $escaped = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $escaped = preg_replace_callback('/https?:\/\/[^\s<]+/i', static function (array $matches): string {
            $url = (string) ($matches[0] ?? '');
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            return '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">' . $safeUrl . '</a>';
        }, $escaped);

        return nl2br((string) $escaped);
    }

    private function getDefaultClientAreaNoticeMessage(): string
    {
        return 'A partir de 01/07/2026 mudamos nosso sistema de emissão de NFS-e para nova API da Nota Nacional. Portanto serão exibidas apenas as notas emitidas depois desta data.' . PHP_EOL . PHP_EOL . 'Caso precise do PDF ou XML de alguma nota fiscal anterior a esta data, solicite via https://my.minivps.com.br/submitticket.php';
    }


    private function downloadXml(int $invoiceId, int $userId): void
    {
        if ($invoiceId <= 0) {
            throw new NfseModuleException('Fatura inválida.');
        }

        $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
        if ((int) ($invoice['userid'] ?? 0) !== $userId) {
            throw new NfseModuleException('Acesso negado.');
        }

        $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
        if (!$nota) {
            throw new NfseModuleException('NFS-e não encontrada para esta fatura.');
        }
        if ((string) ($nota['status'] ?? '') !== 'EMITIDA') {
            throw new NfseModuleException('NFS-e ainda não está disponível para download.');
        }

        $xmlPath = (string) ($nota['xml_path'] ?? '');
        if ($xmlPath === '') {
            throw new NfseModuleException('XML não disponível.');
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

    private function downloadPdf(int $invoiceId, int $userId): void
    {
        if ($invoiceId <= 0) {
            throw new NfseModuleException('Fatura inválida.');
        }

        $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
        if ((int) ($invoice['userid'] ?? 0) !== $userId) {
            throw new NfseModuleException('Acesso negado.');
        }

        $pdfService = new DanfsePdfService();
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

    private function sendEmail(int $invoiceId, int $userId): void
    {
        if ($invoiceId <= 0) {
            throw new NfseModuleException('Fatura inválida.');
        }

        try {
            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
            if ((int) ($invoice['userid'] ?? 0) !== $userId) {
                throw new NfseModuleException('Acesso negado.');
            }

            (new InvoiceEmailService())->sendToClient($invoiceId);
            $this->redirectAfterEmail($invoiceId, 'done');
        } catch (\Throwable $e) {
            (new LogRepository())->insert(
                null,
                'CLIENT_SEND_EMAIL_ERROR',
                json_encode(['invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE),
                $e->getMessage()
            );
            $this->redirectAfterEmail($invoiceId, 'error');
        }
    }

    private function redirectAfterEmail(int $invoiceId, string $status): void
    {
        $return = trim((string) ($_REQUEST['return'] ?? ''));
        if ($return === 'viewinvoice') {
            header('Location: viewinvoice.php?id=' . $invoiceId . '&nfse_email=' . rawurlencode($status));
            exit;
        }

        header('Location: index.php?m=OpenNfse&nfse_email=' . rawurlencode($status));
        exit;
    }
}
