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
    /** @var array<int, bool> existência do custom field por ID, consultada uma vez por processo */
    private static array $customFieldExists = [];

    /**
     * @return array{invoice: array, client: array, gateway: array{enabled: bool, emit_on_create: bool}, nota: ?array}
     */
    public function load(int $invoiceId, array $config): array
    {
        $invoice = $this->withItemInfo($invoiceId, (new WhmcsInvoiceRepository())->getInvoice($invoiceId));
        $userId = (int) ($invoice['userid'] ?? 0);

        $client = ['groupid' => 0, 'customfield_value' => null, 'customfield_missing' => false];
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
                if (self::customFieldExists($customerRepo, $customFieldId)) {
                    $client['customfield_value'] = $customerRepo->getCustomFieldValue($userId, $customFieldId);
                } else {
                    $client['customfield_missing'] = true;
                }
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
     * Acrescenta à fatura os tipos dos itens (item_types: Mass Pay e Add Funds) e o valor da NFS-e
     * calculado pelo NfseValueResolver (nfse_value; na agregadora de Mass Pay, sem as filhas com nota).
     */
    public function withItemInfo(int $invoiceId, array $invoice): array
    {
        $invoiceRepo = new WhmcsInvoiceRepository();
        $items = $invoiceRepo->getItems($invoiceId);
        $types = [];
        foreach ($items as $item) {
            $type = trim((string) ($item['type'] ?? ''));
            if ($type !== '' && !in_array($type, $types, true)) {
                $types[] = $type;
            }
        }
        $invoice['item_types'] = $types;

        // Valor da NFS-e pelo resolver único: na agregadora de Mass Pay, desconta as filhas com nota.
        $invoice['nfse_value'] = (new NfseValueResolver())->resolve($invoice, $items);

        return $invoice;
    }

    /**
     * Diz se o custom field configurado para a política ainda existe (tela de configuração,
     * dashboard e hooks). Campo apagado: a regra por cliente é ignorada, sem log.
     */
    public static function configuredCustomFieldMissing(array $config): bool
    {
        $customFieldId = (int) ($config['auto_emit_customfield_id'] ?? 0);
        if ($customFieldId <= 0) {
            return false;
        }

        try {
            return !self::customFieldExists(new WhmcsCustomerRepository(), $customFieldId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function customFieldExists(WhmcsCustomerRepository $repo, int $customFieldId): bool
    {
        if (!array_key_exists($customFieldId, self::$customFieldExists)) {
            self::$customFieldExists[$customFieldId] = $repo->clientCustomFieldExists($customFieldId);
        }

        return self::$customFieldExists[$customFieldId];
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
