<?php

declare(strict_types=1);

namespace OpenNfse\Hooks;

use OpenNfse\Dto\EmissionDecision;
use OpenNfse\Migrations\Migrator;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Services\EmissionPolicyContextLoader;
use OpenNfse\Services\EmissionPolicyService;
use OpenNfse\Services\InvoiceFinancialsService;
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

        // Sem fila, ou sem nenhuma automação configurada, não há o que decidir (nem o que logar).
        if (!self::automationMayApply($config)) {
            return;
        }

        try {
            $loader = new EmissionPolicyContextLoader();
            $ctx = $loader->load($invoiceId, $config);
            $decision = (new EmissionPolicyService())->decide(
                EmissionPolicyService::EVENT_PAGA,
                $ctx['invoice'],
                $ctx['client'],
                $config,
                $ctx['gateway'],
                $ctx['nota']
            );
            $loader->logWarnings($invoiceId, EmissionPolicyService::EVENT_PAGA, $decision);

            if (!$decision->emit) {
                $this->logSkip($invoiceId, $ctx['invoice'], $decision);
                return;
            }

            (new QueueService())->enqueueEmit(
                $invoiceId,
                'QUEUE_ENQUEUE_AUTO_PAID',
                false,
                EmissionPolicyService::QUEUE_ORIGEM_PAGA
            );
        } catch (\Throwable $e) {
            (new LogRepository())->insert(
                null,
                'QUEUE_ENQUEUE_AUTO_PAID_ERROR',
                json_encode(['invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE),
                $e->getMessage()
            );
        }
    }

    /**
     * Fila ligada e alguma regra que possa emitir automaticamente: gatilho global diferente de
     * "nunca" ou custom field por cliente configurado.
     */
    public static function automationMayApply(array $config): bool
    {
        if ((string) ($config['queue_enabled'] ?? '0') !== '1') {
            return false;
        }

        return EmissionPolicyService::resolveGlobalTrigger($config) !== EmissionPolicyService::TRIGGER_NUNCA
            || (int) ($config['auto_emit_customfield_id'] ?? 0) > 0;
    }

    private function logSkip(int $invoiceId, array $invoice, EmissionDecision $decision): void
    {
        if ($decision->silent) {
            return;
        }

        $paymentMethod = strtolower(trim((string) ($invoice['paymentmethod'] ?? '')));
        $status = strtolower(trim((string) ($invoice['status'] ?? '')));
        $logRepo = new LogRepository();

        switch ($decision->code) {
            case EmissionPolicyService::CODE_NOT_PAID:
                $logRepo->insert(
                    null,
                    'QUEUE_SKIP_NOT_PAID',
                    json_encode(['invoiceid' => $invoiceId, 'status' => $status], JSON_UNESCAPED_UNICODE),
                    null
                );
                return;
            case EmissionPolicyService::CODE_CREDIT_PAYMENT:
                $logRepo->insert(
                    null,
                    'QUEUE_SKIP_CREDIT_PAYMENT',
                    json_encode([
                        'invoiceid' => $invoiceId,
                        'paymentmethod' => $paymentMethod,
                        'credit' => (new InvoiceFinancialsService())->getAppliedCredit($invoice),
                    ], JSON_UNESCAPED_UNICODE),
                    null
                );
                return;
            case EmissionPolicyService::CODE_GATEWAY_DISABLED:
                $logRepo->insert(
                    null,
                    'QUEUE_SKIP_GATEWAY_DISABLED',
                    json_encode(['invoiceid' => $invoiceId, 'paymentmethod' => $paymentMethod], JSON_UNESCAPED_UNICODE),
                    null
                );
                return;
            case EmissionPolicyService::CODE_ZERO_VALUE:
                $logRepo->insert(
                    null,
                    'QUEUE_SKIP_ZERO_VALUE',
                    json_encode([
                        'invoiceid' => $invoiceId,
                        'total' => (new InvoiceFinancialsService())->getInvoiceTotal($invoice),
                    ], JSON_UNESCAPED_UNICODE),
                    null
                );
                return;
        }

        $logRepo->insert(
            null,
            'QUEUE_SKIP_POLICY',
            json_encode(
                ['invoiceid' => $invoiceId, 'evento' => EmissionPolicyService::EVENT_PAGA] + $decision->toLogContext(),
                JSON_UNESCAPED_UNICODE
            ),
            $decision->message
        );
    }
}
