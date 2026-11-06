<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

use OpenNfse\Module;

/**
 * Porta de entrada dos hooks de fatura. Fora da pausa, chama o handler na hora.
 * Na pausa, guarda o evento; replayPending() roda os guardados depois da volta,
 * na ordem em que chegaram, já com o código da versão nova.
 */
final class HookGate
{
    /** @var array<string, callable(array): void>|null */
    private static ?array $handlers = null;

    /**
     * @param array<string, callable(array): void>|null $handlers null volta aos handlers do módulo
     */
    public static function useHandlers(?array $handlers): void
    {
        self::$handlers = $handlers;
    }

    /**
     * @param array<string, mixed> $vars
     */
    public static function run(string $key, array $vars): void
    {
        if (MaintenanceMode::isPaused()) {
            try {
                MaintenanceMode::defer($key, $vars);

                return;
            } catch (\Throwable $e) {
                // Sem conseguir guardar, segue como se não houvesse pausa: perder o evento é pior.
            }
        }

        self::dispatch($key, $vars);
    }

    /**
     * @param array<string, mixed> $vars
     */
    public static function dispatch(string $key, array $vars): void
    {
        $handler = self::handlers()[$key] ?? null;
        if ($handler === null) {
            throw new \InvalidArgumentException('Evento de hook desconhecido: ' . $key);
        }
        $handler($vars);
    }

    /**
     * Roda os eventos guardados durante a pausa. Não roda com a pausa ativa.
     *
     * @return array{replayed: int, failed: int, remaining: int}
     */
    public static function replayPending(int $limit = 200): array
    {
        $store = MaintenanceMode::store();
        if (MaintenanceMode::isPaused()) {
            return ['replayed' => 0, 'failed' => 0, 'remaining' => $store->countPendingEvents()];
        }

        $replayed = 0;
        $failed = 0;
        foreach ($store->pendingEvents($limit) as $event) {
            $error = null;
            try {
                self::dispatch($event['hook'], $event['vars']);
            } catch (\Throwable $e) {
                $error = substr($e->getMessage(), 0, 1000);
                $failed++;
            }
            $store->markEventReplayed($event['id'], $error);
            $replayed++;
        }

        return ['replayed' => $replayed, 'failed' => $failed, 'remaining' => $store->countPendingEvents()];
    }

    /**
     * @return array<string, callable(array): void>
     */
    private static function handlers(): array
    {
        if (self::$handlers !== null) {
            return self::$handlers;
        }

        return [
            'invoice_paid_emit' => static fn (array $v) => Module::invoicePaidHook()->handle($v),
            'invoice_created' => static fn (array $v) => Module::invoiceCreatedHook()->handle($v),
            'invoice_pre_email' => static fn (array $v) => Module::invoiceCreatedHook()->handlePreEmail($v),
            'invoice_creation' => static fn (array $v) => Module::invoiceCreatedHook()->registerCreation($v),
            'invoice_paid_alert' => static fn (array $v) => Module::invoiceAlertHook()->onPaid($v),
            'invoice_cancelled' => static fn (array $v) => Module::invoiceAlertHook()->onCancelled($v),
            'invoice_refunded' => static fn (array $v) => Module::invoiceAlertHook()->onRefunded($v),
            'invoice_unpaid' => static fn (array $v) => Module::invoiceAlertHook()->onUnpaid($v),
            'invoice_total' => static fn (array $v) => Module::invoiceAlertHook()->onTotalChanged($v),
            'invoice_late_fee' => static fn (array $v) => Module::invoiceAlertHook()->onTotalChangedBy('AddInvoiceLateFee', $v),
            'invoice_split' => static fn (array $v) => Module::invoiceAlertHook()->onTotalChangedBy('InvoiceSplit', $v),
            'add_transaction' => static fn (array $v) => Module::invoiceAlertHook()->onTransaction($v),
        ];
    }
}
