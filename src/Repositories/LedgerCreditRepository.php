<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use Throwable;
use WHMCS\Database\Capsule;

/**
 * Soma lançamentos de tblaccounts que não são pagamento de gateway.
 *
 * No WHMCS 9 o crédito do cliente é type = invoice_credit_applied (amountin - amountout).
 * Mark Paid grava invoice_billing_adjustment_credit. Esse ajuste só entra na soma
 * quando a opção de emitir nessa marcação está desligada.
 * No 8.x a coluna type não existe: devolve 0, e o crédito continua em tblinvoices.credit.
 * A existência da coluna é lembrada pelo processo; o valor não, porque o crédito pode
 * entrar no meio do mesmo request.
 */
final class LedgerCreditRepository implements LedgerCreditSource
{
    private const TABLE = 'tblaccounts';

    private const CREDIT_TYPE = 'invoice_credit_applied';

    private const MANUAL_PAID_TYPE = 'invoice_billing_adjustment_credit';

    private static ?bool $typeColumnExists = null;

    public static function forgetColumnCache(): void
    {
        self::$typeColumnExists = null;
    }

    /**
     * WHMCS 9 tem tblaccounts.type. No 8.x a coluna não existe e o retorno é false.
     * Falha ao consultar o schema não entra no cache: a próxima leitura tenta de novo.
     */
    public static function hasLedgerTypeColumn(): bool
    {
        if (self::$typeColumnExists !== null) {
            return self::$typeColumnExists;
        }

        try {
            self::$typeColumnExists = Capsule::schema()->hasColumn(self::TABLE, 'type');
        } catch (Throwable) {
            return false;
        }

        return self::$typeColumnExists;
    }

    /**
     * Mark Paid deixa de contar como gateway só no WHMCS 9, com a opção desligada.
     * Sem a coluna type, ou se a config não puder ser lida, o retorno é false.
     */
    public static function deductsManualPaidFromGateway(): bool
    {
        if (!self::hasLedgerTypeColumn()) {
            return false;
        }

        try {
            $value = (new ConfigRepository())->get()['emit_on_manual_paid'] ?? '1';
        } catch (Throwable) {
            return false;
        }

        return (string) $value === '0';
    }

    public function appliedClientCredit(int $invoiceId): float
    {
        return $this->sumTypes($invoiceId, [self::CREDIT_TYPE]);
    }

    public function appliedNonGatewayAmount(int $invoiceId): float
    {
        $types = [self::CREDIT_TYPE];
        if (self::deductsManualPaidFromGateway()) {
            $types[] = self::MANUAL_PAID_TYPE;
        }

        return $this->sumTypes($invoiceId, $types);
    }

    /**
     * @param list<string> $types
     */
    private function sumTypes(int $invoiceId, array $types): float
    {
        if ($invoiceId <= 0 || $types === []) {
            return 0.0;
        }

        try {
            if (!self::hasLedgerTypeColumn()) {
                return 0.0;
            }

            $net = 0.0;
            $rows = Capsule::table(self::TABLE)
                ->where('invoiceid', $invoiceId)
                ->whereIn('type', $types)
                ->get();
            foreach ($rows as $row) {
                $data = (array) $row;
                if ((int) ($data['invoiceid'] ?? 0) !== $invoiceId) {
                    continue;
                }
                if (!in_array((string) ($data['type'] ?? ''), $types, true)) {
                    continue;
                }
                $net += (float) ($data['amountin'] ?? 0) - (float) ($data['amountout'] ?? 0);
            }

            return round($net, 2);
        } catch (Throwable) {
            return 0.0;
        }
    }
}
