<?php

declare(strict_types=1);

namespace OpenNfse\Services;

/**
 * Decide, sem acessar banco, se um evento da fatura gera alerta de NFS-e.
 *
 * Cada método devolve null (sem alerta) ou ['tipo' => ..., 'detalhes' => ...].
 * Só há alerta quando a fatura tem nota EMITIDA (uma nota CANCELADA não alerta).
 */
final class InvoiceAlertPolicy
{
    private const MONEY_TOLERANCE = 0.009;

    public static function hasEmittedNota(?array $nota): bool
    {
        if ($nota === null) {
            return false;
        }

        return strtoupper(trim((string) ($nota['status'] ?? ''))) === 'EMITIDA';
    }

    public static function onCancelled(?array $nota): ?array
    {
        if (!self::hasEmittedNota($nota)) {
            return null;
        }

        return [
            'tipo' => AlertService::FATURA_CANCELADA_COM_NFSE,
            'detalhes' => 'Fatura cancelada com ' . self::notaLabel($nota) . ' emitida. Avalie o cancelamento manual da NFS-e dentro do prazo do município.',
        ];
    }

    public static function onRefunded(?array $nota): ?array
    {
        if (!self::hasEmittedNota($nota)) {
            return null;
        }

        return [
            'tipo' => AlertService::FATURA_ESTORNADA_COM_NFSE,
            'detalhes' => 'Fatura estornada com ' . self::notaLabel($nota) . ' emitida. Avalie o cancelamento manual da NFS-e dentro do prazo do município.',
        ];
    }

    /**
     * Fatura que volta para Unpaid. Só alerta quando há sinal de pagamento anterior
     * (transações registradas ou crédito aplicado), ou seja, uma fatura paga que foi reaberta.
     */
    public static function onUnpaid(?array $nota, float $paymentsReceived, float $appliedCredit): ?array
    {
        if (!self::hasEmittedNota($nota)) {
            return null;
        }

        if ($paymentsReceived <= self::MONEY_TOLERANCE && $appliedCredit <= self::MONEY_TOLERANCE) {
            return null;
        }

        return [
            'tipo' => AlertService::FATURA_REABERTA_COM_NFSE,
            'detalhes' => 'Fatura paga voltou para Unpaid com ' . self::notaLabel($nota) . ' emitida. Pagamentos registrados: '
                . self::money($paymentsReceived) . '; crédito aplicado: ' . self::money($appliedCredit) . '.',
        ];
    }

    /**
     * Itens ou total alterados depois da emissão. Compara o valor cobrado da fatura
     * (o mesmo usado no vServ da DPS) com o valor da nota.
     *
     * Quando o valor da nota é desconhecido não há decisão. Fatura quitada só com crédito
     * fica para o alerta CREDITO_APOS_EMISSAO, gerado no pagamento.
     */
    public static function onTotalChanged(?array $nota, ?float $notaValue, array $invoice): ?array
    {
        if (!self::hasEmittedNota($nota) || $notaValue === null) {
            return null;
        }

        $financials = new InvoiceFinancialsService();
        if ($financials->isCreditOnlyPayment($invoice)) {
            return null;
        }

        $currentValue = $financials->getGatewayPaidAmount($invoice);
        if (abs(round($currentValue, 2) - round($notaValue, 2)) <= self::MONEY_TOLERANCE) {
            return null;
        }

        $detalhes = 'Total da fatura (' . self::money($currentValue) . ') difere do valor da ' . self::notaLabel($nota)
            . ' (' . self::money($notaValue) . ').';
        $credit = $financials->getAppliedCredit($invoice);
        if ($credit > self::MONEY_TOLERANCE) {
            $detalhes .= ' Crédito aplicado: ' . self::money($credit) . '.';
        }

        return [
            'tipo' => AlertService::FATURA_ALTERADA_COM_NFSE,
            'detalhes' => $detalhes,
        ];
    }

    /**
     * Fatura paga só com crédito que já tinha nota emitida.
     *
     * O hook roda no InvoicePaid e o enfileiramento da emissão ao pagar não emite na hora,
     * então uma nota que já está EMITIDA nesse momento foi emitida antes deste pagamento.
     * Assim a decisão não depende de comparar datas em fusos diferentes.
     */
    public static function onPaid(?array $nota, array $invoice): ?array
    {
        if (!self::hasEmittedNota($nota)) {
            return null;
        }

        if (strtolower(trim((string) ($invoice['status'] ?? ''))) !== 'paid') {
            return null;
        }

        $financials = new InvoiceFinancialsService();
        if (!$financials->isCreditOnlyPayment($invoice)) {
            return null;
        }

        return [
            'tipo' => AlertService::CREDITO_APOS_EMISSAO,
            'detalhes' => self::notaLabel($nota) . ' emitida antes do pagamento, e a fatura foi quitada só com crédito ('
                . self::money($financials->getAppliedCredit($invoice)) . '). Revise se a nota deve ser mantida.',
        ];
    }

    /**
     * Faturas cuja conferência diferida do total deve ser pedida por um hook que altera
     * o total sem passar necessariamente pelo UpdateInvoiceTotal.
     *
     * - AddInvoiceLateFee: a fatura que recebeu a multa (invoiceid);
     * - InvoiceSplit: a fatura original e a nova (originalinvoiceid, newinvoiceid).
     *
     * @return int[]
     */
    public static function deferredCheckInvoiceIds(string $hook, array $vars): array
    {
        $keys = match ($hook) {
            'AddInvoiceLateFee' => ['invoiceid'],
            'InvoiceSplit' => ['originalinvoiceid', 'newinvoiceid'],
            default => [],
        };

        $ids = [];
        foreach ($keys as $key) {
            $id = (int) ($vars[$key] ?? 0);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Fatura do parâmetro do hook AddTransaction. A documentação do WHMCS grafa "invocieid",
     * então as duas grafias são aceitas.
     */
    public static function transactionInvoiceId(array $vars): int
    {
        foreach (['invoiceid', 'invocieid'] as $key) {
            $id = (int) ($vars[$key] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * A transação devolve dinheiro (estorno total ou parcial, ou reversão de pagamento)?
     */
    public static function isOutgoingTransaction(array $vars): bool
    {
        return self::parseMoney($vars['amountout'] ?? 0) > self::MONEY_TOLERANCE || (int) ($vars['refundid'] ?? 0) > 0;
    }

    /**
     * Saída de dinheiro registrada numa fatura com nota emitida (hook AddTransaction).
     *
     * Usa o mesmo tipo do InvoiceRefunded: num estorno total os dois hooks disparam e a
     * idempotência por (fatura, tipo, chave) mantém um único alerta aberto.
     */
    public static function onTransaction(?array $nota, array $vars): ?array
    {
        if (!self::hasEmittedNota($nota) || !self::isOutgoingTransaction($vars)) {
            return null;
        }

        $detalhes = 'Saída de ' . self::money(self::parseMoney($vars['amountout'] ?? 0)) . ' registrada na fatura com '
            . self::notaLabel($nota) . ' emitida (estorno, estorno parcial ou reversão de pagamento).';
        $gateway = trim((string) ($vars['gateway'] ?? ''));
        $transId = trim((string) ($vars['transid'] ?? ''));
        if ($gateway !== '' || $transId !== '') {
            $detalhes .= ' Transação: ' . trim($gateway . ' ' . $transId) . '.';
        }
        $detalhes .= ' Avalie o cancelamento manual da NFS-e dentro do prazo do município.';

        return [
            'tipo' => AlertService::FATURA_ESTORNADA_COM_NFSE,
            'detalhes' => $detalhes,
        ];
    }

    private static function parseMoney(mixed $value): float
    {
        return (float) str_replace(',', '.', (string) $value);
    }

    private static function notaLabel(array $nota): string
    {
        $numero = trim((string) ($nota['numero_nf'] ?? ''));

        return $numero !== '' ? 'NFS-e nº ' . $numero : 'NFS-e';
    }

    private static function money(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}
