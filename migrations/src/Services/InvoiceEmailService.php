<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use WHMCS\Database\Capsule;

final class InvoiceEmailService
{
    private const EMAIL_TEMPLATE_NAME = 'OpenNfse - Envio de NFS-e';

    public function sendToClient(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            throw new NfseModuleException('Invoice inválida.');
        }

        $notaRepo = new NotaRepository();
        $invoiceRepo = new WhmcsInvoiceRepository();
        $logRepo = new LogRepository();
        $storage = new StorageService();

        $nota = $notaRepo->findByInvoiceId($invoiceId);
        if (!$nota) {
            throw new NfseModuleException('NFS-e não encontrada para esta fatura.');
        }

        $status = (string) ($nota['status'] ?? '');
        if ($status !== 'EMITIDA') {
            throw new NfseModuleException('O envio por e-mail está disponível apenas para NFS-e emitida.');
        }

        $xmlPath = (string) ($nota['xml_path'] ?? '');
        if ($xmlPath === '') {
            throw new NfseModuleException('XML da NFS-e não disponível.');
        }

        $xmlAbsPath = $storage->resolveAbsolutePath($xmlPath);
        $pdfService = new DanfsePdfService();
        $pdfBytes = $pdfService->generatePdfBytes($invoiceId);
        if ($pdfBytes === '') {
            throw new NfseModuleException('PDF da NFS-e não disponível.');
        }
        $pdfFilename = $pdfService->getDownloadFilename($invoiceId);
        $pdfTempPath = tempnam(sys_get_temp_dir(), 'opennfse_pdf_');
        if ($pdfTempPath === false || file_put_contents($pdfTempPath, $pdfBytes) === false) {
            throw new NfseModuleException('Falha ao preparar anexo temporário do PDF.');
        }

        $invoice = $invoiceRepo->getInvoice($invoiceId);
        $templateData = (new EmailTemplateService())->buildNfseEmail($invoice, $nota, $invoiceId);
        $invoiceNumber = (string) ($templateData['invoice_number'] ?? $invoiceId);
        $subject = (string) ($templateData['subject'] ?? ('NFS-e Fatura #' . $invoiceNumber));
        $message = (string) ($templateData['message'] ?? '');
        $plainTextMessage = trim((string) ($templateData['plain_text_message'] ?? ''));
        $client = $this->getClientForInvoice($invoice);

        $notaId = (int) ($nota['id'] ?? 0);
        $logRepo->insert(
            $notaId > 0 ? $notaId : null,
            'EMAIL_NFSE_REQUEST',
            json_encode([
                'invoiceid' => $invoiceId,
                'xml_path' => $storage->relativePathFromAbsolute($xmlAbsPath) ?? $xmlPath,
                'pdf_filename' => $pdfFilename,
                'subject' => $subject,
                'transport' => 'sendmessage',
                'to' => $client['email'],
            ], JSON_UNESCAPED_UNICODE),
            null
        );

        try {
            if (!is_file($xmlAbsPath)) {
                throw new NfseModuleException('Arquivo XML da NFS-e não foi encontrado.');
            }

            $xmlBytes = @file_get_contents($xmlAbsPath);
            if (!is_string($xmlBytes) || $xmlBytes === '') {
                throw new NfseModuleException('Falha ao ler o XML da NFS-e.');
            }

            $transport = 'whmcs_provider';
            $providerSent = $this->sendViaWhmcsProvider(
                $client,
                $subject,
                $message,
                $plainTextMessage,
                [
                    [
                        'filename' => basename($xmlAbsPath),
                        'data' => $xmlBytes,
                    ],
                    [
                        'filename' => $pdfFilename,
                        'data' => $pdfBytes,
                    ],
                ],
                $notaId > 0 ? $notaId : null,
                $logRepo
            );

            if ($providerSent) {
                $logRepo->insert(
                    $notaId > 0 ? $notaId : null,
                    'EMAIL_NFSE_RESPONSE',
                    null,
                    json_encode(['result' => 'success', 'invoiceid' => $invoiceId, 'transport' => $transport], JSON_UNESCAPED_UNICODE)
                );
                (new InvoiceHistoryService())->append($invoiceId, 'E-mail da NFS-e enviado ao cliente com XML e PDF anexados.');
                return;
            }

            if (!function_exists('sendmessage')) {
                throw new NfseModuleException('Função sendmessage() não está disponível no WHMCS.');
            }

            $this->ensureNativeEmailTemplate();

            $attachments = [
                [
                    'filepath' => $xmlAbsPath,
                    'filename' => basename($xmlAbsPath),
                    'displayname' => basename($xmlAbsPath),
                ],
                [
                    'filepath' => $pdfTempPath,
                    'filename' => $pdfFilename,
                    'displayname' => $pdfFilename,
                ],
            ];

            $logRepo->insert(
                $notaId > 0 ? $notaId : null,
                'EMAIL_NFSE_MAILER_DEBUG',
                json_encode([
                    'transport' => 'sendmessage',
                    'stage' => 'before_send',
                    'template' => self::EMAIL_TEMPLATE_NAME,
                    'invoice_id' => $invoiceId,
                    'attachments' => $attachments,
                ], JSON_UNESCAPED_UNICODE),
                null
            );

            ob_start();
            $result = sendmessage(
                self::EMAIL_TEMPLATE_NAME,
                $invoiceId,
                [
                    'nfse_subject' => $subject,
                    'nfse_message' => $message,
                ],
                true,
                $attachments
            );
            $sendmessageOutput = trim((string) ob_get_clean());

            $logRepo->insert(
                $notaId > 0 ? $notaId : null,
                'EMAIL_NFSE_MAILER_DEBUG',
                json_encode([
                    'transport' => 'sendmessage',
                    'stage' => 'after_send',
                    'template' => self::EMAIL_TEMPLATE_NAME,
                    'result_type' => gettype($result),
                    'result' => $this->normalizeDebugValue($result),
                    'displayresult_output' => $sendmessageOutput,
                    'last_error' => $this->normalizeDebugValue(error_get_last()),
                    'attachments' => $attachments,
                ], JSON_UNESCAPED_UNICODE),
                null
            );

            if ($result === false) {
                throw new NfseModuleException('sendmessage() retornou false ao tentar enviar o e-mail.');
            }
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage() !== '' ? $e->getMessage() : 'Falha ao enviar e-mail pelo WHMCS.';
            $logRepo->insert($notaId > 0 ? $notaId : null, 'EMAIL_NFSE_RESPONSE', null, $errorMessage);
            (new InvoiceHistoryService())->append(
                $invoiceId,
                'Falha no envio por e-mail da NFS-e. Motivo: ' . $errorMessage
            );
            throw new NfseModuleException($errorMessage);
        } finally {
            if (is_file($pdfTempPath)) {
                @unlink($pdfTempPath);
            }
        }

        $logRepo->insert(
            $notaId > 0 ? $notaId : null,
            'EMAIL_NFSE_RESPONSE',
            null,
            json_encode(['result' => 'success', 'invoiceid' => $invoiceId, 'transport' => 'sendmessage'], JSON_UNESCAPED_UNICODE)
        );
        (new InvoiceHistoryService())->append($invoiceId, 'E-mail da NFS-e enviado ao cliente com XML e PDF anexados.');
    }

    private function ensureNativeEmailTemplate(): void
    {
        if (!class_exists(\WHMCS\Mail\Template::class)) {
            throw new NfseModuleException('Classe de template de e-mail do WHMCS não está disponível.');
        }

        $template = \WHMCS\Mail\Template::where('type', 'invoice')
            ->where('name', self::EMAIL_TEMPLATE_NAME)
            ->where(function ($query) {
                $query->whereNull('language')->orWhere('language', '');
            })
            ->first();

        if (!$template) {
            $template = new \WHMCS\Mail\Template();
        }

        $template->type = 'invoice';
        $template->name = self::EMAIL_TEMPLATE_NAME;
        $template->subject = '{$nfse_subject}';
        $template->message = '{$nfse_message}';
        $template->attachments = [];
        $template->fromName = '';
        $template->fromEmail = '';
        $template->disabled = false;
        $template->custom = true;
        $template->language = '';
        $template->copyTo = [];
        if (property_exists($template, 'blindCopyTo')) {
            $template->blindCopyTo = [];
        }
        $template->plaintext = false;
        $template->save();
    }

    private function getClientForInvoice(array $invoice): array
    {
        $userId = (int) ($invoice['userid'] ?? 0);
        if ($userId <= 0) {
            throw new NfseModuleException('Cliente da fatura não encontrado.');
        }

        $client = Capsule::table('tblclients')->where('id', $userId)->first();
        if (!$client) {
            throw new NfseModuleException('Cliente da fatura não encontrado.');
        }

        $email = trim((string) ($client->email ?? ''));
        if ($email === '') {
            throw new NfseModuleException('E-mail do cliente não encontrado.');
        }

        return [
            'id' => $userId,
            'email' => $email,
            'name' => trim(((string) ($client->firstname ?? '')) . ' ' . ((string) ($client->lastname ?? ''))),
        ];
    }

    private function sendViaWhmcsProvider(array $client, string $subject, string $htmlBody, string $plainTextBody, array $attachments, ?int $notaId, LogRepository $logRepo): bool
    {
        if (!class_exists(\WHMCS\Mail\Message::class) || !interface_exists(\WHMCS\Module\Contracts\SenderModuleInterface::class)) {
            return false;
        }

        $config = $this->getMailConfiguration();
        $moduleName = trim((string) (($config['_mailconfig_raw']['module'] ?? '')));
        if ($moduleName === '') {
            return false;
        }

        $providerClass = $this->resolveWhmcsProviderClass($moduleName);
        if ($providerClass === null) {
            $logRepo->insert(
                $notaId,
                'EMAIL_NFSE_MAILER_DEBUG',
                json_encode([
                    'transport' => 'whmcs_provider',
                    'stage' => 'provider_class_missing',
                    'provider_class' => '\\WHMCS\\Module\\Mail\\' . $moduleName,
                    'module_name' => $moduleName,
                    'root_path' => $this->getWhmcsRootPath(),
                ], JSON_UNESCAPED_UNICODE),
                null
            );
            return false;
        }

        $providerConfig = $this->buildProviderConfiguration($config);
        $message = new \WHMCS\Mail\Message();
        $simpleHtmlBody = $this->buildSimpleHtmlEmail($plainTextBody !== '' ? $plainTextBody : $this->buildPlainTextBody($htmlBody));
        $message->setType('general');
        $message->setSubject($subject);
        $message->setBody($simpleHtmlBody);
        $message->setPlainText($plainTextBody !== '' ? $plainTextBody : $this->buildPlainTextBody($htmlBody));
        $message->setFromEmail((string) ($config['from_email'] ?? ''));
        $message->setFromName((string) ($config['from_name'] ?? ''));
        $message->addRecipient('to', (string) ($client['email'] ?? ''), (string) ($client['name'] ?? ''));

        foreach ($attachments as $attachment) {
            $filename = trim((string) ($attachment['filename'] ?? ''));
            $data = (string) ($attachment['data'] ?? '');
            if ($filename === '' || $data === '') {
                continue;
            }
            $message->addStringAttachment($filename, $data);
        }

        $logRepo->insert(
            $notaId,
            'EMAIL_NFSE_MAILER_DEBUG',
            json_encode([
                'transport' => 'whmcs_provider',
                'stage' => 'before_send',
                'provider_class' => $providerClass,
                'message_type' => 'general',
                'provider_config_keys' => array_keys($providerConfig),
                'attachments' => array_map(static function (array $attachment): array {
                    return ['filename' => (string) ($attachment['filename'] ?? '')];
                }, $attachments),
            ], JSON_UNESCAPED_UNICODE),
            null
        );

        try {
            $provider = new $providerClass();
            $provider->send($providerConfig, $message);
        } catch (\Throwable $e) {
            $logRepo->insert(
                $notaId,
                'EMAIL_NFSE_MAILER_DEBUG',
                json_encode([
                    'transport' => 'whmcs_provider',
                    'stage' => 'send_exception',
                    'provider_class' => $providerClass,
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE),
                null
            );
            return false;
        }

        $logRepo->insert(
            $notaId,
            'EMAIL_NFSE_MAILER_DEBUG',
            json_encode([
                'transport' => 'whmcs_provider',
                'stage' => 'after_send',
                'provider_class' => $providerClass,
            ], JSON_UNESCAPED_UNICODE),
            null
        );

        return true;
    }

    private function resolveWhmcsProviderClass(string $moduleName): ?string
    {
        $candidateClasses = [
            '\\WHMCS\\Module\\Mail\\' . $moduleName,
            '\\WHMCS\\Module\\Mail\\' . $moduleName . '\\' . $moduleName,
        ];

        foreach ($candidateClasses as $candidateClass) {
            if (class_exists($candidateClass)) {
                return $candidateClass;
            }
        }

        $rootPath = $this->getWhmcsRootPath();
        $moduleNameLower = strtolower($moduleName);
        $candidateFiles = [
            $rootPath . '/modules/mail/' . $moduleName . '/' . $moduleName . '.php',
            $rootPath . '/modules/mail/' . $moduleName . '/' . $moduleNameLower . '.php',
            $rootPath . '/modules/mail/' . $moduleNameLower . '/' . $moduleName . '.php',
            $rootPath . '/modules/mail/' . $moduleNameLower . '/' . $moduleNameLower . '.php',
        ];

        foreach ($candidateFiles as $candidateFile) {
            if (is_file($candidateFile)) {
                require_once $candidateFile;
            }
        }

        foreach ($candidateClasses as $candidateClass) {
            if (class_exists($candidateClass)) {
                return $candidateClass;
            }
        }

        foreach (get_declared_classes() as $declaredClass) {
            if (!is_string($declaredClass)) {
                continue;
            }
            if (stripos($declaredClass, $moduleName) === false) {
                continue;
            }
            if (in_array(\WHMCS\Module\Contracts\SenderModuleInterface::class, class_implements($declaredClass) ?: [], true)) {
                return $declaredClass;
            }
        }

        return null;
    }

    private function getWhmcsRootPath(): string
    {
        return dirname(__DIR__, 5);
    }

    private function buildProviderConfiguration(array $config): array
    {
        $providerConfig = is_array($config['_mailconfig_raw']['configuration'] ?? null)
            ? $config['_mailconfig_raw']['configuration']
            : [];

        $providerConfig['host'] = trim((string) ($providerConfig['host'] ?? $config['SMTPHost'] ?? ''));
        $providerConfig['port'] = trim((string) ($providerConfig['port'] ?? $config['SMTPPort'] ?? ''));
        $providerConfig['username'] = (string) ($providerConfig['username'] ?? $config['SMTPUsername'] ?? '');
        $providerConfig['password'] = (string) ($providerConfig['password'] ?? $config['SMTPPassword'] ?? '');
        $providerConfig['secure'] = (string) ($providerConfig['secure'] ?? $config['SMTPSSLType'] ?? '');
        $providerConfig['auth_type'] = (string) ($providerConfig['auth_type'] ?? $config['SMTPAuthType'] ?? '');

        return $providerConfig;
    }

    private function normalizeDebugValue($value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (is_string($json) && $json !== false) {
            return $json;
        }

        return var_export($value, true);
    }

    private function sendDirectEmail(array $client, string $subject, string $htmlBody, array $attachments): void
    {
        $mailer = $this->instantiateMailer();
        $config = $this->getMailConfiguration();

        $fromEmail = trim((string) ($config['from_email'] ?? ''));
        if ($fromEmail === '') {
            throw new NfseModuleException('E-mail remetente do WHMCS não configurado.');
        }

        $fromName = trim((string) ($config['from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = 'WHMCS';
        }

        $logRepo = new LogRepository();
        $logRepo->insert(
            null,
            'EMAIL_NFSE_MAILER_DEBUG',
            json_encode([
                'provider' => (string) ($config['detected_mail_provider'] ?? ''),
                'mailconfig_detected' => !empty($config['_mailconfig_flat'] ?? []),
                'mailconfig_keys' => array_keys((array) ($config['_mailconfig_flat'] ?? [])),
                'from_email' => $fromEmail,
                'smtp_host' => trim((string) ($config['SMTPHost'] ?? '')),
                'smtp_port' => (string) ($config['SMTPPort'] ?? ''),
                'smtp_ssl' => (string) ($config['SMTPSSLType'] ?? $config['SMTPSSL'] ?? ''),
                'smtp_auth_type' => (string) ($config['SMTPAuthType'] ?? ''),
            ], JSON_UNESCAPED_UNICODE),
            null
        );

        $this->configureMailerTransport($mailer, $config);

        $mailer->CharSet = 'UTF-8';
        $mailer->isHTML(true);
        $mailer->setFrom($fromEmail, $fromName);
        $mailer->addAddress((string) $client['email'], (string) ($client['name'] ?? ''));
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $this->buildPlainTextBody($htmlBody);

        foreach ($attachments as $attachment) {
            $data = (string) ($attachment['data'] ?? '');
            $filename = trim((string) ($attachment['filename'] ?? ''));
            if ($data === '' || $filename === '') {
                continue;
            }
            $mailer->addStringAttachment($data, $filename);
        }

        if (!$mailer->send()) {
            $error = property_exists($mailer, 'ErrorInfo') ? trim((string) $mailer->ErrorInfo) : '';
            throw new NfseModuleException($error !== '' ? $error : 'Falha ao enviar e-mail diretamente pelo WHMCS.');
        }
    }

    private function instantiateMailer(): object
    {
        foreach (['\\PHPMailer\\PHPMailer\\PHPMailer', '\\PHPMailer'] as $className) {
            if (class_exists($className)) {
                return new $className(true);
            }
        }

        throw new NfseModuleException('PHPMailer não está disponível na instalação do WHMCS.');
    }

    private function getMailConfiguration(): array
    {
        $settings = [];
        $rows = Capsule::table('tblconfiguration')
            ->whereIn('setting', [
                'MailType',
                'MailProvider',
                'MailMailProvider',
                'MailConfig',
                'SMTPHost',
                'SMTPUsername',
                'SMTPPassword',
                'SMTPPort',
                'SMTPSSLType',
                'SMTPSSL',
                'SMTPAuthType',
                'Email',
                'SystemEmailsFromEmail',
                'SystemEmailsFromName',
                'CompanyName',
            ])
            ->get();

        foreach ($rows as $row) {
            $settings[(string) $row->setting] = (string) $row->value;
        }

        global $CONFIG;
        if (isset($CONFIG) && is_array($CONFIG)) {
            foreach ([
                'MailType',
                'MailProvider',
                'MailMailProvider',
                'MailConfig',
                'SMTPHost',
                'SMTPUsername',
                'SMTPPassword',
                'SMTPPort',
                'SMTPSSLType',
                'SMTPSSL',
                'SMTPAuthType',
                'Email',
                'SystemEmailsFromEmail',
                'SystemEmailsFromName',
                'CompanyName',
            ] as $key) {
                if ((trim((string) ($settings[$key] ?? '')) === '') && isset($CONFIG[$key])) {
                    $settings[$key] = is_scalar($CONFIG[$key]) ? (string) $CONFIG[$key] : '';
                }
            }
        }

        $mailConfigData = $this->parseMailConfig((string) ($settings['MailConfig'] ?? ''));
        $settings = $this->mergeMailConfigIntoSettings($settings, $mailConfigData);
        $settings['_mailconfig_raw'] = $mailConfigData;

        $smtpPassword = trim((string) ($settings['SMTPPassword'] ?? ''));
        if ($smtpPassword !== '' && function_exists('localAPI')) {
            try {
                $decrypted = localAPI('DecryptPassword', ['password2' => $smtpPassword]);
                if (is_array($decrypted) && (string) ($decrypted['result'] ?? '') === 'success') {
                    $settings['SMTPPassword'] = (string) ($decrypted['password'] ?? '');
                }
            } catch (\Throwable $e) {
            }
        }

        $settings['from_email'] = trim((string) ($settings['SystemEmailsFromEmail'] ?? ''));
        if ($settings['from_email'] === '') {
            $settings['from_email'] = trim((string) ($settings['Email'] ?? ''));
        }

        $settings['from_name'] = trim((string) ($settings['SystemEmailsFromName'] ?? ''));
        if ($settings['from_name'] === '') {
            $settings['from_name'] = trim((string) ($settings['CompanyName'] ?? ''));
        }

        $settings['detected_mail_provider'] = $this->detectMailProvider($settings);

        return $settings;
    }

    private function configureMailerTransport(object $mailer, array $config): void
    {
        $mailProvider = strtolower(trim((string) ($config['detected_mail_provider'] ?? '')));
        $smtpHost = trim((string) ($config['SMTPHost'] ?? ''));
        $smtpAuthType = strtolower(trim((string) ($config['SMTPAuthType'] ?? '')));
        $supportedDirectProviders = ['', 'mail', 'phpmail', 'php mail', 'smtp'];

        if ($smtpAuthType === 'oauth' || $mailProvider === 'microsoft') {
            throw new NfseModuleException('O provedor de e-mail configurado no WHMCS usa OAuth e não e suportado por este envio direto.');
        }
        if (!in_array($mailProvider, $supportedDirectProviders, true)) {
            throw new NfseModuleException('O provedor de e-mail configurado no WHMCS (' . $mailProvider . ') nao e suportado por este envio direto.');
        }

        $useSmtp = $mailProvider === 'smtp';
        if (!$useSmtp) {
            if (method_exists($mailer, 'isMail')) {
                $mailer->isMail();
                return;
            }
            throw new NfseModuleException('O transporte de e-mail padrao do WHMCS nao esta disponivel.');
        }

        if (!method_exists($mailer, 'isSMTP')) {
            throw new NfseModuleException('Transporte SMTP nao disponivel no PHPMailer do WHMCS.');
        }
        if ($smtpHost === '') {
            throw new NfseModuleException('O WHMCS esta configurado para SMTP, mas o host SMTP nao foi definido.');
        }

        $mailer->isSMTP();
        $mailer->Host = $smtpHost;
        $mailer->Port = max(1, (int) ($config['SMTPPort'] ?? 25));

        $smtpUser = trim((string) ($config['SMTPUsername'] ?? ''));
        $smtpPass = (string) ($config['SMTPPassword'] ?? '');
        $mailer->SMTPAuth = $smtpUser !== '' || $smtpPass !== '';
        if ($mailer->SMTPAuth) {
            $mailer->Username = $smtpUser;
            $mailer->Password = $smtpPass;
        }

        $sslType = strtolower(trim((string) ($config['SMTPSSLType'] ?? '')));
        if ($sslType === '') {
            $legacySsl = strtolower(trim((string) ($config['SMTPSSL'] ?? '')));
            if (in_array($legacySsl, ['1', 'on', 'true', 'ssl'], true)) {
                $sslType = 'ssl';
            } elseif ($legacySsl === 'tls') {
                $sslType = 'tls';
            }
        }

        if (in_array($sslType, ['ssl', 'tls'], true)) {
            $mailer->SMTPSecure = $sslType;
        }
    }

    private function detectMailProvider(array $settings): string
    {
        foreach (['MailProvider', 'MailMailProvider', 'MailType'] as $key) {
            $value = strtolower(trim((string) ($settings[$key] ?? '')));
            if ($value !== '') {
                return $value;
            }
        }

        $mailConfig = trim((string) ($settings['MailConfig'] ?? ''));
        if ($mailConfig !== '') {
            $decoded = json_decode($mailConfig, true);
            if (is_array($decoded)) {
                foreach (['provider', 'mailProvider', 'type', 'name'] as $key) {
                    if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                        return strtolower(trim($decoded[$key]));
                    }
                }
            }

            $unserialized = @unserialize($mailConfig);
            if (is_array($unserialized)) {
                foreach (['provider', 'mailProvider', 'type', 'name'] as $key) {
                    if (!empty($unserialized[$key]) && is_string($unserialized[$key])) {
                        return strtolower(trim($unserialized[$key]));
                    }
                }
            }

            $mailConfigLower = strtolower($mailConfig);
            foreach (['smtp', 'phpmail', 'php mail', 'mailgun', 'sendgrid', 'sparkpost', 'microsoft', 'google'] as $provider) {
                if (strpos($mailConfigLower, $provider) !== false) {
                    return $provider;
                }
            }
        }

        return '';
    }

    private function parseMailConfig(string $mailConfig): array
    {
        $mailConfig = trim($mailConfig);
        if ($mailConfig === '') {
            return [];
        }

        $candidates = [$mailConfig, base64_decode($mailConfig, true)];
        $decryptedMailConfig = $this->decryptConfigValue($mailConfig);
        if ($decryptedMailConfig !== '') {
            $candidates[] = $decryptedMailConfig;
            $candidates[] = base64_decode($decryptedMailConfig, true);
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            if ($this->looksSerialized($candidate)) {
                $unserialized = @unserialize($candidate);
                if (is_array($unserialized)) {
                    return $unserialized;
                }
            }
        }

        return [];
    }

    private function decryptConfigValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('localAPI')) {
            try {
                $decrypted = localAPI('DecryptPassword', ['password2' => $value]);
                if (is_array($decrypted) && (string) ($decrypted['result'] ?? '') === 'success') {
                    $password = (string) ($decrypted['password'] ?? '');
                    if ($password !== '') {
                        return $password;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        if (function_exists('decrypt')) {
            try {
                $decrypted = decrypt($value);
                if (is_string($decrypted) && trim($decrypted) !== '') {
                    return $decrypted;
                }
            } catch (\Throwable $e) {
            }
        }

        return '';
    }

    private function looksSerialized(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return preg_match('/^(a|s|i|b|d|O|C):/', $value) === 1;
    }

    private function mergeMailConfigIntoSettings(array $settings, array $mailConfigData): array
    {
        if (empty($mailConfigData)) {
            $settings['_mailconfig_flat'] = [];
            return $settings;
        }

        $flat = [];
        $this->flattenConfigArray($mailConfigData, '', $flat);
        $settings['_mailconfig_flat'] = $flat;

        if (trim((string) ($settings['SMTPHost'] ?? '')) === '') {
            $settings['SMTPHost'] = $this->findFirstConfigValue($flat, [
                'smtp.host',
                'smtp.hostname',
                'smtp.server',
                'settings.host',
                'settings.hostname',
                'settings.server',
                'configuration.host',
                'configuration.hostname',
                'configuration.server',
                'host',
                'hostname',
                'server',
            ]);
        }

        if (trim((string) ($settings['SMTPPort'] ?? '')) === '') {
            $settings['SMTPPort'] = $this->findFirstConfigValue($flat, [
                'smtp.port',
                'settings.port',
                'configuration.port',
                'port',
            ]);
        }

        if (trim((string) ($settings['SMTPUsername'] ?? '')) === '') {
            $settings['SMTPUsername'] = $this->findFirstConfigValue($flat, [
                'smtp.username',
                'smtp.user',
                'settings.username',
                'settings.user',
                'configuration.username',
                'configuration.user',
                'username',
                'user',
            ]);
        }

        if (trim((string) ($settings['SMTPPassword'] ?? '')) === '') {
            $settings['SMTPPassword'] = $this->findFirstConfigValue($flat, [
                'smtp.password',
                'settings.password',
                'configuration.password',
                'password',
            ]);
        }

        if (trim((string) ($settings['SMTPSSLType'] ?? '')) === '') {
            $settings['SMTPSSLType'] = $this->normalizeSslType($this->findFirstConfigValue($flat, [
                'smtp.secure',
                'smtp.encryption',
                'smtp.ssltype',
                'settings.secure',
                'settings.encryption',
                'settings.ssltype',
                'configuration.secure',
                'configuration.encryption',
                'configuration.ssltype',
                'secure',
                'encryption',
                'ssltype',
            ]));
        }

        if (trim((string) ($settings['SMTPAuthType'] ?? '')) === '') {
            $settings['SMTPAuthType'] = $this->findFirstConfigValue($flat, [
                'smtp.authtype',
                'settings.authtype',
                'configuration.authtype',
                'authtype',
            ]);
        }

        return $settings;
    }

    private function flattenConfigArray(array $data, string $prefix, array &$flat): void
    {
        foreach ($data as $key => $value) {
            $key = strtolower((string) $key);
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $this->flattenConfigArray($value, $path, $flat);
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $flat[$path] = $value === null ? '' : (string) $value;
            }
        }
    }

    private function findFirstConfigValue(array $flat, array $candidateKeys): string
    {
        foreach ($candidateKeys as $candidateKey) {
            if (isset($flat[$candidateKey])) {
                $value = trim((string) $flat[$candidateKey]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $tailCandidates = [];
        foreach ($candidateKeys as $candidateKey) {
            $parts = explode('.', $candidateKey);
            $tailCandidates[] = end($parts);
        }
        $tailCandidates = array_unique($tailCandidates);

        foreach ($flat as $key => $value) {
            $tail = (string) substr($key, (int) strrpos('.' . $key, '.'));
            $tail = ltrim($tail, '.');
            if (in_array($tail, $tailCandidates, true)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function normalizeSslType(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            'none', 'off', 'false', '0' => '',
            'starttls' => 'tls',
            default => $value,
        };
    }

    private function buildPlainTextBody(string $htmlBody): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ["\n", "\n", "\n", "\n\n"], $htmlBody)), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim((string) $text);

        return $text !== '' ? $text : 'Segue em anexo o XML e o PDF da sua NFS-e.';
    }

    private function buildSimpleHtmlEmail(string $plainTextBody): string
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($plainTextBody)) ?: [];
        $paragraphs = [];
        $currentParagraph = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                if ($currentParagraph !== []) {
                    $paragraphs[] = implode('<br />', $currentParagraph);
                    $currentParagraph = [];
                }
                continue;
            }

            $currentParagraph[] = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        }

        if ($currentParagraph !== []) {
            $paragraphs[] = implode('<br />', $currentParagraph);
        }

        if ($paragraphs === []) {
            $paragraphs[] = 'Segue em anexo o XML e o PDF da sua NFS-e.';
        }

        return '<!DOCTYPE html><html><body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#333;">'
            . implode('', array_map(static fn (string $paragraph): string => '<p style="margin:0 0 12px 0;">' . $paragraph . '</p>', $paragraphs))
            . '</body></html>';
    }
}
