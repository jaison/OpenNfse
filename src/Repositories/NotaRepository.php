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

    public function upsert(array $data): void
    {
        $invoiceId = (int) $data['invoiceid'];
        $now = date('Y-m-d H:i:s');
        $existing = $this->findByInvoiceId($invoiceId);

        if ($existing === null) {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            Capsule::table('mod_opennfse_notas')->insert($data);
            return;
        }

        $data['updated_at'] = $now;
        Capsule::table('mod_opennfse_notas')->where('id', (int) $existing['id'])->update($data);
    }
}

