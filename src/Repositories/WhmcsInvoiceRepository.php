<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Services\NfseValueResolver;
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
        return NfseValueResolver::massPayChildIds($items);
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
