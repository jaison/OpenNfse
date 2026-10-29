<?php

declare(strict_types=1);

namespace OpenNfse\Hooks;

use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Services\EmissionPolicyContextLoader;
use OpenNfse\Services\EmissionPolicyService;
use OpenNfse\Services\QueueService;

/**
 * Hook InvoiceCreated do WHMCS: roda quando a fatura sai de Draft e fica disponível ao cliente
 * (geração automática, admin ou API). Enfileira a emissão quando a política autoriza o gatilho
 * "gerada" para a fatura.
 */
final class InvoiceCreatedHook
{
    public function handle(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }

        // O status recebido só serve de atalho para o rascunho. A decisão relê o status no banco:
        // com crédito automático, o InvoicePaid pode chegar antes e a fatura já estar Paid.
        if (self::hookStatusIsDraft($vars)) {
            return;
        }

        // Sem Migrator aqui: o hook roda para cada fatura da geração em lote. Com o gatilho global
        // padrão, a decisão sai só da config; o enqueueEmit já aplica as migrations antes de gravar.
        // Tudo fica dentro do try: uma falha do addon não pode interromper a geração da fatura.
        try {
            $config = (new ConfigRepository())->get();
            if (empty($config) || !self::onCreateMayApply($config)) {
                return;
            }

            $loader = new EmissionPolicyContextLoader();
            $ctx = $loader->load($invoiceId, $config);
            $decision = (new EmissionPolicyService())->decide(
                EmissionPolicyService::EVENT_GERADA,
                $ctx['invoice'],
                $ctx['client'],
                $config,
                $ctx['gateway'],
                $ctx['nota']
            );
            $loader->logWarnings($invoiceId, EmissionPolicyService::EVENT_GERADA, $decision);

            if (!$decision->emit) {
                if (!$decision->silent) {
                    (new LogRepository())->insert(
                        null,
                        'QUEUE_SKIP_POLICY',
                        json_encode(
                            ['invoiceid' => $invoiceId, 'evento' => EmissionPolicyService::EVENT_GERADA]
                                + $decision->toLogContext(),
                            JSON_UNESCAPED_UNICODE
                        ),
                        $decision->message
                    );
                }
                return;
            }

            (new QueueService())->enqueueEmit(
                $invoiceId,
                'QUEUE_ENQUEUE_AUTO_CREATED',
                true,
                EmissionPolicyService::QUEUE_ORIGEM_GERADA
            );
        } catch (\Throwable $e) {
            try {
                (new LogRepository())->insert(
                    null,
                    'QUEUE_ENQUEUE_AUTO_CREATED_ERROR',
                    json_encode(['invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE),
                    $e->getMessage()
                );
            } catch (\Throwable $loggingError) {
            }
        }
    }

    public static function hookStatusIsDraft(array $vars): bool
    {
        return strtolower(trim((string) ($vars['status'] ?? ''))) === 'draft';
    }

    /**
     * Só vale a pena ler fatura e cliente se a fila estiver ligada e alguma regra puder emitir
     * na geração: gatilho global "gerada" ou custom field por cliente configurado.
     */
    public static function onCreateMayApply(array $config): bool
    {
        if ((string) ($config['queue_enabled'] ?? '0') !== '1') {
            return false;
        }

        return EmissionPolicyService::resolveGlobalTrigger($config) === EmissionPolicyService::TRIGGER_GERADA
            || (int) ($config['auto_emit_customfield_id'] ?? 0) > 0;
    }
}
