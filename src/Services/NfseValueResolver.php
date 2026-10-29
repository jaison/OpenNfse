<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Repositories\NotaRepository;

/**
 * Ponto único do valor do serviço na NFS-e (vServ) e dos itens que entram na descrição.
 *
 * Fatura comum: o valor é o total pago pelo gateway (getGatewayPaidAmount), igual à v0.1.24.
 *
 * Fatura de Mass Pay (agregadora M, itens type Invoice com relid = fatura original): as filhas que
 * já têm nota EMITIDA ou PROCESSANDO (emitida antes do pagamento, na geração ou na emissão manual
 * de não paga) já estão cobertas pela própria nota. A nota de M desconta os itens dessas filhas e
 * descreve só o resto (filhas sem nota e itens que não são type Invoice, como uma multa).
 */
final class NfseValueResolver
{
    /** Status de nota que contam como filha já coberta. */
    public const COVERED_NOTA_STATUSES = ['EMITIDA', 'PROCESSANDO'];

    /**
     * Cálculo puro. all_children_covered diz se todas as filhas da agregadora têm nota (só faz
     * sentido com excluded_children não vazio).
     *
     * @param array<int, int> $issuedChildIds filhas com nota EMITIDA ou PROCESSANDO
     *
     * @return array{value: float, base: float, excluded_amount: float, excluded_children: array<int, int>,
     *               items: array, partial: bool, all_children_covered: bool}
     */
    public static function compute(array $invoice, array $items, array $issuedChildIds): array
    {
        $base = (new InvoiceFinancialsService())->getGatewayPaidAmount($invoice);
        $issued = array_map('intval', $issuedChildIds);

        $excludedAmount = 0.0;
        $excludedChildren = [];
        $describedItems = [];
        foreach ($items as $item) {
            $isMassPayItem = strtolower(trim((string) ($item['type'] ?? ''))) === 'invoice';
            $relid = (int) ($item['relid'] ?? 0);
            if ($isMassPayItem && $relid > 0 && in_array($relid, $issued, true)) {
                $excludedAmount += (float) str_replace(',', '.', (string) ($item['amount'] ?? 0));
                if (!in_array($relid, $excludedChildren, true)) {
                    $excludedChildren[] = $relid;
                }
                continue;
            }
            $describedItems[] = $item;
        }

        if ($excludedChildren === []) {
            return [
                'value' => $base,
                'base' => $base,
                'excluded_amount' => 0.0,
                'excluded_children' => [],
                'items' => $items,
                'partial' => false,
                'all_children_covered' => false,
            ];
        }

        $excludedAmount = round($excludedAmount, 2);
        $value = max(0.0, round($base - $excludedAmount, 2));

        return [
            'value' => $value,
            'base' => $base,
            'excluded_amount' => $excludedAmount,
            'excluded_children' => $excludedChildren,
            'items' => $describedItems,
            'partial' => $value > 0 && $value < $base,
            'all_children_covered' => count($excludedChildren) >= count(self::massPayChildIds($items)),
        ];
    }

    /**
     * Valor e itens da NFS-e da fatura. Só consulta as notas das filhas quando a fatura tem item
     * type Invoice (Mass Pay); nas demais, não há consulta extra.
     *
     * @return array{value: float, base: float, excluded_amount: float, excluded_children: array<int, int>,
     *               items: array, partial: bool, all_children_covered: bool}
     */
    public function resolve(array $invoice, array $items): array
    {
        $children = self::massPayChildIds($items);
        $issued = $children === []
            ? []
            : (new NotaRepository())->findInvoiceIdsWithStatus($children, self::COVERED_NOTA_STATUSES);

        return self::compute($invoice, $items, $issued);
    }

    /**
     * Faturas originais referenciadas pelos itens type Invoice (relid).
     *
     * @return array<int, int>
     */
    public static function massPayChildIds(array $items): array
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
}
