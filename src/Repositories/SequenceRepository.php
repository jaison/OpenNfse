<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use OpenNfse\Exceptions\NfseModuleException;
use WHMCS\Database\Capsule;

final class SequenceRepository
{
    public function next(string $environment, string $cnpjEmissor, string $serieDps): int
    {
        $cnpjEmissor = preg_replace('/\D/', '', $cnpjEmissor);
        if (!$cnpjEmissor) {
            throw new NfseModuleException('CNPJ do emissor inválido para sequencial.');
        }

        return (int) Capsule::connection()->transaction(function () use ($environment, $cnpjEmissor, $serieDps) {
            $row = Capsule::table('mod_opennfse_sequences')
                ->where('environment', $environment)
                ->where('cnpj_emissor', $cnpjEmissor)
                ->where('serie_dps', $serieDps)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                Capsule::table('mod_opennfse_sequences')->insert([
                    'environment' => $environment,
                    'cnpj_emissor' => $cnpjEmissor,
                    'serie_dps' => $serieDps,
                    'last_number' => 1,
                ]);
                return 1;
            }

            $current = (int) $row->last_number;
            $next = $current + 1;
            Capsule::table('mod_opennfse_sequences')->where('id', (int) $row->id)->update(['last_number' => $next]);
            return $next;
        });
    }
}

