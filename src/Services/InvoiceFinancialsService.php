<?php

declare(strict_types=1);

namespace OpenNfse\Services;

final class InvoiceFinancialsService
{
    public function getInvoiceTotal(array $invoice): float
    {
        return $this->parseMoney($invoice['total'] ?? 0);
    }

    public function getAppliedCredit(array $invoice): float
    {
        return $this->parseMoney($invoice['credit'] ?? 0);
    }

    public function getGatewayPaidAmount(array $invoice): float
    {
        $amount = round($this->getInvoiceTotal($invoice), 2);

        return $amount > 0 ? $amount : 0.0;
    }

    public function isCreditOnlyPayment(array $invoice): bool
    {
        $paymentMethod = strtolower(trim((string) ($invoice['paymentmethod'] ?? '')));
        if ($paymentMethod === 'credit') {
            return true;
        }

        if ($this->getAppliedCredit($invoice) <= 0.00001) {
            return false;
        }

        return $this->getGatewayPaidAmount($invoice) <= 0.00001;
    }

    private function parseMoney(mixed $value): float
    {
        return (float) str_replace(',', '.', (string) $value);
    }
}
