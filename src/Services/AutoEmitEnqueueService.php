<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Migrations\Migrator;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\WhmcsCustomerRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;

/**
 * Enfileira emissão automática a partir de hooks (pagamento ou criação da fatura).
 */
final class AutoEmitEnqueueService
{
    public const TRIGGER_PAID = 'paid';
    public const TRIGGER_CREATED = 'created';

    public function handle(int $invoiceId, string $trigger): void
    {
        (new Migrator())->up();
        if ($invoiceId <= 0) {
            return;
        }

        $config = (new ConfigRepository())->get();
        if (empty($config)) {
            return;
        }

        if ((string) ($config['queue_enabled'] ?? '0') !== '1') {
            return;
        }

        if ((string) ($config['auto_emit_on_payment'] ?? '0') !== '1') {
            return;
        }

        $allowUnpaidAuto = ((string) ($config['allow_unpaid_auto_emit'] ?? '0')) === '1';
        if ($trigger === self::TRIGGER_CREATED && !$allowUnpaidAuto) {
            return;
        }

        $logPrefix = $trigger === self::TRIGGER_CREATED ? 'QUEUE_ENQUEUE_AUTO_CREATED' : 'QUEUE_ENQUEUE_AUTO_PAID';

        try {
            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
            $status = strtolower(trim((string) ($invoice['status'] ?? '')));

            // Na criação, só age se a fatura ainda não estiver paga (o fluxo Paid cuida do pago).
            if ($trigger === self::TRIGGER_CREATED && $status === 'paid') {
                return;
            }

            // No pagamento, se unpaid-auto já emitiu, a fila/serviço ignoram nota EMITIDA.
            $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
            if (is_array($nota) && strtoupper((string) ($nota['status'] ?? '')) === 'EMITIDA') {
                (new LogRepository())->insert(
                    null,
                    'QUEUE_SKIP_ALREADY_EMITIDA',
                    json_encode(['invoiceid' => $invoiceId, 'trigger' => $trigger], JSON_UNESCAPED_UNICODE),
                    null
                );
                return;
            }

            $clientFieldId = (int) ($config['auto_emit_client_customfield_id'] ?? 0);
            if ($clientFieldId > 0) {
                $userId = (int) ($invoice['userid'] ?? 0);
                $clientRepo = new WhmcsCustomerRepository();
                if ($userId <= 0 || !$clientRepo->isCustomFieldTruthy($userId, $clientFieldId)) {
                    (new LogRepository())->insert(
                        null,
                        'QUEUE_SKIP_CLIENT_AUTO_DISABLED',
                        json_encode([
                            'invoiceid' => $invoiceId,
                            'userid' => $userId,
                            'customfield_id' => $clientFieldId,
                            'trigger' => $trigger,
                        ], JSON_UNESCAPED_UNICODE),
                        null
                    );
                    return;
                }
            }

            $eligibility = (new EmissionEligibilityService())->check($invoice, [
                'context' => EmissionEligibilityService::CONTEXT_AUTO,
            ]);
            if ($eligibility !== null) {
                switch ($eligibility['reason']) {
                    case EmissionEligibilityService::SKIP_NOT_PAID:
                        (new LogRepository())->insert(
                            null,
                            'QUEUE_SKIP_NOT_PAID',
                            json_encode([
                                'invoiceid' => $invoiceId,
                                'status' => $eligibility['status'],
                                'trigger' => $trigger,
                            ], JSON_UNESCAPED_UNICODE),
                            null
                        );
                        break;
                    case EmissionEligibilityService::SKIP_CREDIT_PAYMENT:
                        (new LogRepository())->insert(
                            null,
                            'QUEUE_SKIP_CREDIT_PAYMENT',
                            json_encode([
                                'invoiceid' => $invoiceId,
                                'paymentmethod' => $eligibility['paymentMethod'],
                                'credit' => $eligibility['credit'],
                                'trigger' => $trigger,
                            ], JSON_UNESCAPED_UNICODE),
                            null
                        );
                        break;
                    case EmissionEligibilityService::SKIP_GATEWAY_DISABLED:
                        (new LogRepository())->insert(
                            null,
                            'QUEUE_SKIP_GATEWAY_DISABLED',
                            json_encode([
                                'invoiceid' => $invoiceId,
                                'paymentmethod' => $eligibility['paymentMethod'],
                                'trigger' => $trigger,
                            ], JSON_UNESCAPED_UNICODE),
                            null
                        );
                        break;
                }
                return;
            }

            (new QueueService())->enqueueEmit($invoiceId, $logPrefix);
        } catch (\Throwable $e) {
            (new LogRepository())->insert(
                null,
                $logPrefix . '_ERROR',
                json_encode(['invoiceid' => $invoiceId, 'trigger' => $trigger], JSON_UNESCAPED_UNICODE),
                $e->getMessage()
            );
        }
    }
}
