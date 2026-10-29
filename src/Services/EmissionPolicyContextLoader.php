<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Dto\EmissionDecision;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\PaymentGatewaySettingsRepository;
use OpenNfse\Repositories\WhmcsCustomerRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;

/**
 * Lê do banco os dados que EmissionPolicyService::decide() recebe e grava os logs da decisão.
 */
final class EmissionPolicyContextLoader
{
    /**
     * @return array{invoice: array, client: array, gateway: array{enabled: bool, emit_on_create: bool}, nota: ?array}
     */
    public function load(int $invoiceId, array $config): array
    {
        $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
        $userId = (int) ($invoice['userid'] ?? 0);

        $client = ['groupid' => 0, 'customfield_value' => null];
        if ($userId > 0) {
            $customerRepo = new WhmcsCustomerRepository();
            try {
                $row = $customerRepo->getClient($userId);
                $client['groupid'] = (int) ($row['groupid'] ?? 0);
            } catch (\Throwable $e) {
                $client['groupid'] = 0;
            }
            $customFieldId = (int) ($config['auto_emit_customfield_id'] ?? 0);
            if ($customFieldId > 0) {
                $client['customfield_value'] = $customerRepo->getCustomFieldValue($userId, $customFieldId);
            }
        }

        $paymentMethod = strtolower(trim((string) ($invoice['paymentmethod'] ?? '')));

        return [
            'invoice' => $invoice,
            'client' => $client,
            'gateway' => (new PaymentGatewaySettingsRepository())->getPolicySettings($paymentMethod),
            'nota' => (new NotaRepository())->findByInvoiceId($invoiceId),
        ];
    }

    /**
     * Grava os avisos da decisão (ex.: valor desconhecido no custom field).
     */
    public function logWarnings(int $invoiceId, string $event, EmissionDecision $decision): void
    {
        if (empty($decision->warnings)) {
            return;
        }

        $logRepo = new LogRepository();
        foreach ($decision->warnings as $warning) {
            $logRepo->insert(
                null,
                'POLICY_CUSTOMFIELD_WARNING',
                json_encode(
                    ['invoiceid' => $invoiceId, 'evento' => $event, 'aviso' => $warning['code']],
                    JSON_UNESCAPED_UNICODE
                ),
                $warning['message']
            );
        }
    }
}
