<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use WHMCS\Database\Capsule;

final class InvoiceHistoryService
{
    public function append(int $invoiceId, string $message): void
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
