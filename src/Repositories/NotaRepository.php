<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class NotaRepository
{
    public function findByInvoiceId(int $invoiceId): ?array
    {
        $row = Capsule::table('mod_opennfse_notas')->where('invoiceid', $invoiceId)->first();
        return $row ? (array) $row : null;
    }

    public function upsert(array $data, array $options = []): void
    {
        $invoiceId = (int) $data['invoiceid'];
        $now = date('Y-m-d H:i:s');
        $existing = $this->findByInvoiceId($invoiceId);
        $touchLastStatusCheckedAt = (bool) ($options['touch_last_status_checked_at'] ?? false);

        if ($existing === null) {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            if ($touchLastStatusCheckedAt && !array_key_exists('last_status_checked_at', $data)) {
                $data['last_status_checked_at'] = $now;
            }
            Capsule::table('mod_opennfse_notas')->insert($data);
            return;
        }

        if ($touchLastStatusCheckedAt && !array_key_exists('last_status_checked_at', $data)) {
            $data['last_status_checked_at'] = $now;
        }

        $hasSubstantiveChange = false;
        foreach ($data as $field => $value) {
            if (in_array($field, ['invoiceid', 'created_at', 'updated_at', 'last_status_checked_at'], true)) {
                continue;
            }

            $current = $existing[$field] ?? null;
            if (!$this->valuesAreEquivalent($current, $value)) {
                $hasSubstantiveChange = true;
                break;
            }
        }

        if ($hasSubstantiveChange) {
            $data['updated_at'] = $now;
        } elseif (count($data) === 1 && array_key_exists('invoiceid', $data)) {
            return;
        }

        Capsule::table('mod_opennfse_notas')->where('id', (int) $existing['id'])->update($data);
    }

    private function valuesAreEquivalent($current, $next): bool
    {
        if ($current === null || $next === null) {
            return $current === $next;
        }

        if (is_bool($current) || is_bool($next)) {
            return (bool) $current === (bool) $next;
        }

        if (is_numeric($current) && is_numeric($next)) {
            return (string) (+$current) === (string) (+$next);
        }

        return trim((string) $current) === trim((string) $next);
    }
}
