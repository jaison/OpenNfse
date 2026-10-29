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
     * Fatura paga só com crédito cuja nota foi emitida antes do pagamento.
     */
    public static function onPaid(?array $nota, array $invoice, ?int $now = null): ?array
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

        $emitidaEm = trim((string) ($nota['emitida_em'] ?? ''));
        $datePaid = trim((string) ($invoice['datepaid'] ?? ''));
        if (!self::emittedBeforePayment($emitidaEm, $datePaid, $now)) {
            return null;
        }

        return [
            'tipo' => AlertService::CREDITO_APOS_EMISSAO,
            'detalhes' => self::notaLabel($nota) . ' emitida antes do pagamento, e a fatura foi quitada só com crédito ('
                . self::money($financials->getAppliedCredit($invoice)) . '). Revise se a nota deve ser mantida.',
        ];
    }

    /**
     * A nota foi emitida antes do pagamento?
     *
     * Sem data de pagamento válida, vale o momento atual (o hook roda no pagamento).
     * Sem data de emissão, a nota que já está EMITIDA no pagamento conta como anterior.
     */
    public static function emittedBeforePayment(string $emitidaEm, string $datePaid, ?int $now = null): bool
    {
        $paidTs = self::parseTimestamp($datePaid);
        if ($paidTs === null) {
            $paidTs = $now ?? time();
        }

        $emittedTs = self::parseTimestamp($emitidaEm);
        if ($emittedTs === null) {
            return true;
        }

        return $emittedTs < $paidTs;
    }

    private static function parseTimestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $ts = strtotime($value);

        return $ts === false ? null : $ts;
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
