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

    /**
     * Faturas originais referenciadas por uma fatura de Mass Pay (itens type Invoice, relid = fatura).
     *
     * @return array<int, int>
     */
    public function getMassPayChildInvoiceIds(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (strtolower(trim((string) ($item['type'] ?? ''))) !== 'invoice') {
                continue;
            }
            $relid = (int) ($item['relid'] ?? 0);
            if ($relid > 0 && !in_array($relid, $out, true)) {
                $out[] = $relid;
            }
        }

        return $out;
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
}
