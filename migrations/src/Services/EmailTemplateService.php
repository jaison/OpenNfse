<?php

declare(strict_types=1);

namespace OpenNfse\Services;

final class EmailTemplateService
{
    public function buildNfseEmail(array $invoice, array $nota, int $invoiceId): array
    {
        $invoiceNumber = trim((string) ($invoice['invoicenum'] ?? ''));
        if ($invoiceNumber === '') {
            $invoiceNumber = (string) $invoiceId;
        }

        $numeroNf = trim((string) ($nota['numero_nf'] ?? ''));
        $emitidaEm = $this->formatDateTime((string) ($nota['emitida_em'] ?? ''));

        $subject = 'NFS-e Fatura #' . $invoiceNumber;

        $plainTextMessage = implode("\n", [
            'Segue em anexo o XML e o PDF da sua NFS-e.',
            '',
            'Fatura: #' . $invoiceNumber,
            'NFS-e: ' . ($numeroNf !== '' ? $numeroNf : '-'),
            'Emitida em: ' . $emitidaEm,
            '',
            'Os arquivos seguem anexados para sua consulta e armazenamento.',
        ]);

        $message = '';
        $message .= '<p>Segue em anexo o XML e o PDF da sua NFS-e.</p>';
        $message .= '<p><strong>Fatura:</strong> #' . htmlspecialchars($invoiceNumber, ENT_QUOTES, 'UTF-8') . '<br />';
        $message .= '<strong>NFS-e:</strong> ' . htmlspecialchars($numeroNf !== '' ? $numeroNf : '-', ENT_QUOTES, 'UTF-8') . '<br />';
        $message .= '<strong>Emitida em:</strong> ' . htmlspecialchars($emitidaEm, ENT_QUOTES, 'UTF-8') . '</p>';
        $message .= '<p>Os arquivos seguem anexados para sua consulta e armazenamento.</p>';

        return [
            'subject' => $subject,
            'message' => $message,
            'plain_text_message' => $plainTextMessage,
            'invoice_number' => $invoiceNumber,
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
