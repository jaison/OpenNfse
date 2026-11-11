<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Repositories\LedgerCreditRepository;
use OpenNfse\Repositories\LedgerCreditSource;

/**
 * Valores da fatura usados na emissão.
 *
 * WHMCS 8.x grava o crédito em tblinvoices.credit e já reduz tblinvoices.total.
 * WHMCS 9.x deixa credit em 0 e o total cheio; o saldo do cliente aplicado entra
 * em tblaccounts. O valor do gateway é o total menos esse ledger (nunca o credit
 * da tabela de novo, senão o 8.x descontaria duas vezes).
 */
final class InvoiceFinancialsService
{
    private LedgerCreditSource $ledgerCredit;

    public function __construct(?LedgerCreditSource $ledgerCredit = null)
    {
        $this->ledgerCredit = $ledgerCredit ?? new LedgerCreditRepository();
    }

    public function getInvoiceTotal(array $invoice): float
    {
        return $this->parseMoney($invoice['total'] ?? 0);
    }

    public function getAppliedCredit(array $invoice): float
    {
        return $this->appliedCredit($invoice, $this->ledgerCreditFor($invoice));
    }

    public function getGatewayPaidAmount(array $invoice): float
    {
        return $this->gatewayPaid($invoice, $this->ledgerCreditFor($invoice));
    }

    public function isCreditOnlyPayment(array $invoice): bool
    {
        $paymentMethod = strtolower(trim((string) ($invoice['paymentmethod'] ?? '')));
        if ($paymentMethod === 'credit') {
            return true;
        }

        $ledger = $this->ledgerCreditFor($invoice);
        if ($this->appliedCredit($invoice, $ledger) <= 0.00001) {
            return false;
        }

        return $this->gatewayPaid($invoice, $ledger) <= 0.00001;
    }

    /**
     * Total zero (ou negativo) sem crédito aplicado. Com crédito e total zero, é pagamento só com crédito.
     */
    public function isZeroValue(array $invoice): bool
    {
        return $this->getInvoiceTotal($invoice) <= 0.00001
            && $this->getAppliedCredit($invoice) <= 0.00001;
    }

    private function appliedCredit(array $invoice, float $ledger): float
    {
        return round($this->parseMoney($invoice['credit'] ?? 0) + $ledger, 2);
    }

    private function gatewayPaid(array $invoice, float $ledger): float
    {
        $amount = round($this->getInvoiceTotal($invoice) - $ledger, 2);

        return $amount > 0 ? $amount : 0.0;
    }

    /**
     * Uma leitura por chamada pública. Sem id, a fonte não é consultada.
     * Valor negativo não aumenta a NFS-e: o gateway nunca passa do total.
     */
    private function ledgerCreditFor(array $invoice): float
    {
        $id = (int) ($invoice['id'] ?? 0);
        if ($id <= 0) {
            return 0.0;
        }

        $amount = $this->ledgerCredit->appliedClientCredit($id);

        return $amount > 0 ? round($amount, 2) : 0.0;
    }

    private function parseMoney(mixed $value): float
    {
        return (float) str_replace(',', '.', (string) $value);
    }
}
