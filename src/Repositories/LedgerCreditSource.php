<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

/**
 * Crédito de saldo do cliente aplicado na fatura (WHMCS 9, tblaccounts).
 */
interface LedgerCreditSource
{
    public function appliedClientCredit(int $invoiceId): float;
}
