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

    /** Status da fatura em tblinvoices, ou null se ela não existe. */
    public function getStatus(int $invoiceId): ?string
    {
        if ($invoiceId <= 0) {
            return null;
        }
        $row = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

        return $row ? (string) ($row->status ?? '') : null;
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
     * Faturas de Mass Pay que agrupam a fatura informada (item type Invoice com relid = fatura),
     * com o status de cada uma.
     *
     * @return array<int, string> id da agregadora => status
     */
    public function findMassPayAggregatorStatuses(int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            return [];
        }

        $ids = [];
        $items = Capsule::table('tblinvoiceitems')
            ->where('type', 'Invoice')
            ->where('relid', $invoiceId)
            ->get(['invoiceid']);
        foreach ($items as $item) {
            $id = (int) ($item->invoiceid ?? 0);
            if ($id > 0 && $id !== $invoiceId && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Capsule::table('tblinvoices')->whereIn('id', $ids)->get(['id', 'status']) as $row) {
            $out[(int) ($row->id ?? 0)] = (string) ($row->status ?? '');
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
