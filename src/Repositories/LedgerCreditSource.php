<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

/**
 * Valores de tblaccounts que não são pagamento de gateway (WHMCS 9).
 */
interface LedgerCreditSource
{
    public function appliedClientCredit(int $invoiceId): float;

    /**
     * Crédito do cliente e, com a opção de Mark Paid desligada, o ajuste
     * invoice_billing_adjustment_credit. Com a opção ligada, só o crédito.
     */
    public function appliedNonGatewayAmount(int $invoiceId): float;
}
