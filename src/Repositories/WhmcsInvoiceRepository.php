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
}
