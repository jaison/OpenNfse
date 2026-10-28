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

    /**
     * Conta os logs $tipo de um correlation_id (indexado) gravados depois do último log $resetTipo.
     */
    public function countByCorrelationSinceLast(string $correlationId, string $tipo, string $resetTipo): int
    {
        $correlationId = trim($correlationId);
        if ($correlationId === '') {
            return 0;
        }

        $resetId = (int) Capsule::table('mod_opennfse_logs')
            ->where('correlation_id', $correlationId)
            ->where('tipo', $resetTipo)
            ->max('id');

        return (int) Capsule::table('mod_opennfse_logs')
            ->where('correlation_id', $correlationId)
            ->where('tipo', $tipo)
            ->where('id', '>', $resetId)
            ->count();
    }
}
