<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class LogRepository
{
    public function insert(?int $notaId, string $tipo, ?string $request, ?string $response, ?string $correlationId = null): void
    {
        Capsule::table('mod_opennfse_logs')->insert([
            'nota_id' => $notaId,
            'correlation_id' => $correlationId,
            'tipo' => $tipo,
            'request' => $request,
            'response' => $response,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
