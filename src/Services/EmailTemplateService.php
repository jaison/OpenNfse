<?php

declare(strict_types=1);

namespace OpenNfse\Services;

final class EmailTemplateService
{
    public const DEFAULT_TEMPLATE_NAME = 'OpenNfse - Envio de NFS-e';

    /**
     * @return array{
     *   subject:string,
     *   message:string,
     *   plain_text_message:string,
     *   invoice_number:string,
     *   merge_fields:array<string,string>
     * }
     */
    public function buildNfseEmail(array $invoice, array $nota, int $invoiceId): array
    {
        $mergeFields = $this->buildMergeFields($invoice, $nota, $invoiceId);
        $invoiceNumber = $mergeFields['invoice_num'];

        $template = $this->loadWhmcsTemplate();
        if ($template !== null) {
            $subject = $this->replaceMergeFields((string) ($template['subject'] ?? ''), $mergeFields);
            $message = $this->replaceMergeFields((string) ($template['message'] ?? ''), $mergeFields);
            $plainTextMessage = trim(html_entity_decode(strip_tags($message), ENT_QUOTES, 'UTF-8'));
            if ($subject === '') {
                $subject = 'NFS-e Fatura #' . $invoiceNumber;
            }
            if ($message === '') {
                $fallback = $this->buildFallbackBodies($mergeFields);
                $message = $fallback['message'];
                $plainTextMessage = $fallback['plain_text_message'];
            }

            return [
                'subject' => $subject,
                'message' => $message,
                'plain_text_message' => $plainTextMessage !== '' ? $plainTextMessage : strip_tags($message),
                'invoice_number' => $invoiceNumber,
                'merge_fields' => $mergeFields,
            ];
        }

        $fallback = $this->buildFallbackBodies($mergeFields);

        return [
            'subject' => 'NFS-e Fatura #' . $invoiceNumber,
            'message' => $fallback['message'],
            'plain_text_message' => $fallback['plain_text_message'],
            'invoice_number' => $invoiceNumber,
            'merge_fields' => $mergeFields,
        ];
    }

    /**
     * @return array<string,string>
     */
    public function buildMergeFields(array $invoice, array $nota, int $invoiceId): array
    {
        $invoiceNumber = trim((string) ($invoice['invoicenum'] ?? ''));
        if ($invoiceNumber === '') {
            $invoiceNumber = (string) $invoiceId;
        }

        $numeroNf = trim((string) ($nota['numero_nf'] ?? ''));
        $chave = trim((string) ($nota['chave_acesso'] ?? ''));
        $emitidaEm = $this->formatDateTime((string) ($nota['emitida_em'] ?? ''));

        return [
            'invoice_id' => (string) $invoiceId,
            'invoice_num' => $invoiceNumber,
            'nfse_numero' => $numeroNf !== '' ? $numeroNf : '-',
            'nfse_chave' => $chave !== '' ? $chave : '-',
            'nfse_emitida_em' => $emitidaEm,
            'nfse_subject' => 'NFS-e Fatura #' . $invoiceNumber,
        ];
    }

    /**
     * @return array{subject:string,message:string}|null
     */
    public function loadWhmcsTemplate(?string $templateName = null): ?array
    {
        $name = trim((string) $templateName);
        if ($name === '') {
            $config = (new \OpenNfse\Repositories\ConfigRepository())->get();
            $name = trim((string) ($config['email_template_name'] ?? ''));
        }
        if ($name === '') {
            $name = self::DEFAULT_TEMPLATE_NAME;
        }

        if (!class_exists(\WHMCS\Mail\Template::class)) {
            $row = \WHMCS\Database\Capsule::table('tblemailtemplates')
                ->where('type', 'invoice')
                ->where('name', $name)
                ->where(function ($query) {
                    $query->whereNull('language')->orWhere('language', '');
                })
                ->first();
            if (!$row) {
                return null;
            }
            return [
                'subject' => (string) ($row->subject ?? ''),
                'message' => (string) ($row->message ?? ''),
            ];
        }

        $template = \WHMCS\Mail\Template::where('type', 'invoice')
            ->where('name', $name)
            ->where(function ($query) {
                $query->whereNull('language')->orWhere('language', '');
            })
            ->first();

        if (!$template) {
            return null;
        }

        return [
            'subject' => (string) ($template->subject ?? ''),
            'message' => (string) ($template->message ?? ''),
        ];
    }

    /**
     * @param array<string,string> $mergeFields
     */
    public function replaceMergeFields(string $content, array $mergeFields): string
    {
        $replacements = [];
        foreach ($mergeFields as $key => $value) {
            $replacements['{$' . $key . '}'] = $value;
        }
        // Compatibilidade com o template legado que só usava nfse_subject/nfse_message
        return strtr($content, $replacements);
    }

    /**
     * @param array<string,string> $mergeFields
     * @return array{message:string,plain_text_message:string}
     */
    private function buildFallbackBodies(array $mergeFields): array
    {
        $invoiceNumber = $mergeFields['invoice_num'] ?? '-';
        $numeroNf = $mergeFields['nfse_numero'] ?? '-';
        $emitidaEm = $mergeFields['nfse_emitida_em'] ?? '-';

        $plainTextMessage = implode("\n", [
            'Segue em anexo o XML e o PDF da sua NFS-e.',
            '',
            'Fatura: #' . $invoiceNumber,
            'NFS-e: ' . $numeroNf,
            'Emitida em: ' . $emitidaEm,
            '',
            'Os arquivos seguem anexados para sua consulta e armazenamento.',
        ]);

        $message = '';
        $message .= '<p>Segue em anexo o XML e o PDF da sua NFS-e.</p>';
        $message .= '<p><strong>Fatura:</strong> #' . htmlspecialchars($invoiceNumber, ENT_QUOTES, 'UTF-8') . '<br />';
        $message .= '<strong>NFS-e:</strong> ' . htmlspecialchars($numeroNf, ENT_QUOTES, 'UTF-8') . '<br />';
        $message .= '<strong>Emitida em:</strong> ' . htmlspecialchars($emitidaEm, ENT_QUOTES, 'UTF-8') . '</p>';
        $message .= '<p>Os arquivos seguem anexados para sua consulta e armazenamento.</p>';

        return [
            'message' => $message,
            'plain_text_message' => $plainTextMessage,
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
