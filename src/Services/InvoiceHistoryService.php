<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use WHMCS\Database\Capsule;

/**
 * Linhas "[NFS-e]" no campo tblinvoices.notes.
 *
 * O WHMCS mostra esse campo ao cliente ("Observações" na fatura e no PDF). Por isso ele recebe
 * só a emissão e o cancelamento da NFS-e. Consultas, e-mails, alertas e ações do admin ficam
 * no log do addon (mod_opennfse_logs). Não acrescente aqui um método genérico de escrita.
 */
final class InvoiceHistoryService
{
    public function appendEmission(int $invoiceId, ?string $numeroNf, ?string $chaveAcesso): void
    {
        $message = 'NFS-e emitida com sucesso.';
        if ($numeroNf !== null && trim($numeroNf) !== '') {
            $message .= ' Número: ' . trim($numeroNf) . '.';
        }
        if ($chaveAcesso !== null && trim($chaveAcesso) !== '') {
            $message .= ' Chave: ' . trim($chaveAcesso) . '.';
        }

        $this->append($invoiceId, $message);
    }

    /**
     * Cancelamento da NFS-e da fatura. Com $chaveOrfa, registra o cancelamento de uma NFS-e órfã
     * (XML da fatura que não é a nota atual) e mostra a chave dela.
     */
    public function appendCancellation(int $invoiceId, string $motivo, ?string $chaveOrfa = null): void
    {
        $message = $chaveOrfa !== null && trim($chaveOrfa) !== ''
            ? 'NFS-e órfã cancelada com sucesso. Chave: ' . trim($chaveOrfa) . '. Motivo: ' . $motivo
            : 'NFS-e cancelada com sucesso. Motivo: ' . $motivo;

        $this->append($invoiceId, $message);
    }

    private function append(int $invoiceId, string $message): void
    {
        if ($invoiceId <= 0) {
            return;
        }

        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if ($message === '') {
            return;
        }

        try {
            $row = Capsule::table('tblinvoices')->where('id', $invoiceId)->select(['notes'])->first();
            if (!$row) {
                return;
            }

            $existing = trim((string) ($row->notes ?? ''));
            $line = '[' . date('d/m/Y H:i:s') . '] [NFS-e] ' . $message;
            $notes = $existing === '' ? $line : rtrim($existing) . PHP_EOL . $line;

            Capsule::table('tblinvoices')->where('id', $invoiceId)->update([
                'notes' => $notes,
            ]);
        } catch (\Throwable $e) {
            return;
        }
    }
}
