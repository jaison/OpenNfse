<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use OpenNfse\Exceptions\NfseModuleException;
use WHMCS\Database\Capsule;

final class WhmcsInvoiceRepository
{
    public function getInvoice(int $invoiceId): array
    {
        $row = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$row) {
            throw new NfseModuleException('Fatura não encontrada.');
        }
        return (array) $row;
    }

    public function getItems(int $invoiceId): array
    {
        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->orderBy('id')->get();
        $out = [];
        foreach ($items as $r) {
            $out[] = (array) $r;
        }
        return $out;
    }

    /**
     * Soma líquida dos pagamentos registrados para a fatura (entradas menos saídas em tblaccounts).
     */
    public function sumPayments(int $invoiceId): float
    {
        if ($invoiceId <= 0) {
            return 0.0;
        }

        $row = Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->selectRaw('COALESCE(SUM(amountin), 0) - COALESCE(SUM(amountout), 0) as net')
            ->first();

        return $row ? (float) ($row->net ?? 0) : 0.0;
    }
}
