<?php

declare(strict_types=1);

namespace OpenNfse\Hooks;

use OpenNfse\Migrations\Migrator;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use OpenNfse\Services\EmissionEligibilityService;
use OpenNfse\Services\QueueService;

final class InvoicePaidHook
{
    public function handle(array $vars): void
    {
        (new Migrator())->up();
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
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

        try {
            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
            $eligibility = (new EmissionEligibilityService())->check($invoice);
            if ($eligibility !== null) {
                switch ($eligibility['reason']) {
                    case EmissionEligibilityService::SKIP_NOT_PAID:
                        (new LogRepository())->insert(
                            null,
                            'QUEUE_SKIP_NOT_PAID',
                            json_encode(['invoiceid' => $invoiceId, 'status' => $eligibility['status']], JSON_UNESCAPED_UNICODE),
                            null
                        );
                        break;
                    case EmissionEligibilityService::SKIP_CREDIT_PAYMENT:
                        (new LogRepository())->insert(
                            null,
                            'QUEUE_SKIP_CREDIT_PAYMENT',
                            json_encode(['invoiceid' => $invoiceId, 'paymentmethod' => $eligibility['paymentMethod'], 'credit' => $eligibility['credit']], JSON_UNESCAPED_UNICODE),
                            null
                        );
                        break;
                    case EmissionEligibilityService::SKIP_GATEWAY_DISABLED:
                        (new LogRepository())->insert(
                            null,
                            'QUEUE_SKIP_GATEWAY_DISABLED',
                            json_encode(['invoiceid' => $invoiceId, 'paymentmethod' => $eligibility['paymentMethod']], JSON_UNESCAPED_UNICODE),
                            null
                        );
                        break;
                }
                return;
            }
            (new QueueService())->enqueueEmit($invoiceId, 'QUEUE_ENQUEUE_AUTO_PAID');
        } catch (\Throwable $e) {
            (new LogRepository())->insert(
                null,
                'QUEUE_ENQUEUE_AUTO_PAID_ERROR',
                json_encode(['invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE),
                $e->getMessage()
            );
        }
    }
}
