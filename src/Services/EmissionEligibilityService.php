<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\PaymentGatewaySettingsRepository;

final class EmissionEligibilityService
{
    public const SKIP_NOT_PAID = 'NOT_PAID';
    public const SKIP_CREDIT_PAYMENT = 'CREDIT_PAYMENT';
    public const SKIP_GATEWAY_DISABLED = 'GATEWAY_DISABLED';

    public const CONTEXT_AUTO = 'auto';
    public const CONTEXT_MANUAL = 'manual';

    /**
     * Returns null when the invoice is eligible for emission, or an array with
     * the skip reason and the invoice details used to evaluate it.
     *
     * @param array{context?:string} $options context=auto|manual (default manual)
     * @return array{reason: string, paymentMethod: string, status: string, credit: float, gatewayPaidAmount?: float}|null
     */
    public function check(array $invoice, array $options = []): ?array
    {
        $context = strtolower(trim((string) ($options['context'] ?? self::CONTEXT_MANUAL)));
        if ($context !== self::CONTEXT_AUTO) {
            $context = self::CONTEXT_MANUAL;
        }

        $financials = new InvoiceFinancialsService();
        $paymentMethod = strtolower(trim((string) ($invoice['paymentmethod'] ?? '')));
        $invoiceStatus = strtolower(trim((string) ($invoice['status'] ?? '')));
        $creditValue = $financials->getAppliedCredit($invoice);
        $gatewayPaidAmount = $financials->getGatewayPaidAmount($invoice);

        if ($invoiceStatus !== 'paid') {
            $allowUnpaidManual = false;
            if ($context === self::CONTEXT_MANUAL) {
                $config = (new ConfigRepository())->get();
                $allowUnpaidManual = ((string) ($config['allow_unpaid_manual_emit'] ?? '0')) === '1';
            }
            if (!$allowUnpaidManual) {
                return [
                    'reason' => self::SKIP_NOT_PAID,
                    'paymentMethod' => $paymentMethod,
                    'status' => $invoiceStatus,
                    'credit' => $creditValue,
                ];
            }
        }

        if ($financials->isCreditOnlyPayment($invoice)) {
            return [
                'reason' => self::SKIP_CREDIT_PAYMENT,
                'paymentMethod' => $paymentMethod,
                'status' => $invoiceStatus,
                'credit' => $creditValue,
                'gatewayPaidAmount' => $gatewayPaidAmount,
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
