<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use Throwable;
use WHMCS\Database\Capsule;

/**
 * Soma o crédito de saldo do cliente aplicado na fatura.
 *
 * No WHMCS 9 isso é tblaccounts.type = invoice_credit_applied (amountin - amountout).
 * No 8.x a coluna type não existe: devolve 0, e o crédito continua em tblinvoices.credit.
 * A existência da coluna é lembrada pelo processo; o valor não, porque o crédito pode
 * entrar no meio do mesmo request.
 */
final class LedgerCreditRepository implements LedgerCreditSource
{
    private const TABLE = 'tblaccounts';

    private const CREDIT_TYPE = 'invoice_credit_applied';

    private static ?bool $typeColumnExists = null;

    public static function forgetColumnCache(): void
    {
        self::$typeColumnExists = null;
    }

    public function appliedClientCredit(int $invoiceId): float
    {
        if ($invoiceId <= 0) {
            return 0.0;
        }

        try {
            if (!$this->hasTypeColumn()) {
                return 0.0;
            }

            $net = 0.0;
            $rows = Capsule::table(self::TABLE)
                ->where('invoiceid', $invoiceId)
                ->where('type', self::CREDIT_TYPE)
                ->get();
            foreach ($rows as $row) {
                $data = (array) $row;
                if ((int) ($data['invoiceid'] ?? 0) !== $invoiceId) {
                    continue;
                }
                if ((string) ($data['type'] ?? '') !== self::CREDIT_TYPE) {
                    continue;
                }
                $net += (float) ($data['amountin'] ?? 0) - (float) ($data['amountout'] ?? 0);
            }

            return round($net, 2);
        } catch (Throwable) {
            return 0.0;
        }
    }

    private function hasTypeColumn(): bool
    {
        if (self::$typeColumnExists === null) {
            self::$typeColumnExists = Capsule::schema()->hasColumn(self::TABLE, 'type');
        }

        return self::$typeColumnExists;
    }
}
