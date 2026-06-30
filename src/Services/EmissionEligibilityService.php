<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Repositories\PaymentGatewaySettingsRepository;

final class EmissionEligibilityService
{
    public const SKIP_NOT_PAID = 'NOT_PAID';
    public const SKIP_CREDIT_PAYMENT = 'CREDIT_PAYMENT';
    public const SKIP_GATEWAY_DISABLED = 'GATEWAY_DISABLED';

    /**
     * Returns null when the invoice is eligible for emission, or an array with
     * the skip reason and the invoice details used to evaluate it.
     *
     * @return array{reason: string, paymentMethod: string, status: string, credit: float}|null
     */
    public function check(array $invoice): ?array
    {
        $paymentMethod = strtolower(trim((string) ($invoice['paymentmethod'] ?? '')));
        $invoiceStatus = strtolower(trim((string) ($invoice['status'] ?? '')));
        $creditValue = (float) str_replace(',', '.', (string) ($invoice['credit'] ?? '0'));

        if ($invoiceStatus !== 'paid') {
            return [
                'reason' => self::SKIP_NOT_PAID,
                'paymentMethod' => $paymentMethod,
                'status' => $invoiceStatus,
                'credit' => $creditValue,
            ];
        }

        if ($paymentMethod === 'credit' || $creditValue > 0.00001) {
            return [
                'reason' => self::SKIP_CREDIT_PAYMENT,
                'paymentMethod' => $paymentMethod,
                'status' => $invoiceStatus,
                'credit' => $creditValue,
            ];
        }

        if ($paymentMethod !== '' && !(new PaymentGatewaySettingsRepository())->isEnabled($paymentMethod)) {
            return [
                'reason' => self::SKIP_GATEWAY_DISABLED,
                'paymentMethod' => $paymentMethod,
                'status' => $invoiceStatus,
                'credit' => $creditValue,
            ];
        }

        return null;
    }
}
