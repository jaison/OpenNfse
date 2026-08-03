<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class IbgeMunicipioRepository
{
    public function countAll(): int
    {
        return (int) Capsule::table('mod_opennfse_ibge_municipios')->count();
    }

    public function findByIbgeCode(string $ibgeCode): ?array
    {
        $ibgeCode = preg_replace('/\D/', '', $ibgeCode);
        if ($ibgeCode === '') {
            return null;
        }

        $row = Capsule::table('mod_opennfse_ibge_municipios')
            ->where('ibge_code', $ibgeCode)
            ->first();

        if (!$row) {
            return null;
        }

        return (array) $row;
    }

    public function findByUfAndNormalizedName(string $uf, string $nomeNormalizado): ?string
    {
        $uf = strtoupper($uf);
        $row = Capsule::table('mod_opennfse_ibge_municipios')
            ->where('uf', $uf)
            ->where('nome_normalizado', $nomeNormalizado)
            ->first();

        if (!$row) {
            return null;
        }

        return (string) $row->ibge_code;
    }

    public function upsert(string $ibgeCode, string $nomeNormalizado, string $nomeOriginal, string $uf): void
    {
        $ibgeCode = preg_replace('/\D/', '', $ibgeCode);
        $nomeNormalizado = $this->sanitizeNome($nomeNormalizado);
        $nomeOriginal = $this->sanitizeNome($nomeOriginal);
        $uf = strtoupper(trim($uf));
        if (!preg_match('/^\d{7}$/', $ibgeCode)) {
            throw new \InvalidArgumentException('Código IBGE inválido para persistência.');
        }
        if ($nomeNormalizado === '' || $nomeOriginal === '') {
            throw new \InvalidArgumentException('Nome do município inválido para persistência.');
        }
        if (!preg_match('/^[A-Z]{2}$/', $uf)) {
            throw new \InvalidArgumentException('UF inválida para persistência.');
        }

        Capsule::table('mod_opennfse_ibge_municipios')->updateOrInsert(
            ['ibge_code' => $ibgeCode],
            [
                'nome_normalizado' => $nomeNormalizado,
                'nome_original' => $nomeOriginal,
                'uf' => $uf,
            ]
        );
    }

    public function replaceCatalog(array $rows): int
    {
        if ($rows === []) {
            throw new \InvalidArgumentException('Nenhuma linha informada para substituir o catálogo.');
        }

        return (int) Capsule::connection()->transaction(function () use ($rows): int {
            $ibgeCodes = [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('Linha inválida ao substituir o catálogo.');
                }

                $ibgeCode = (string) ($row['ibge_code'] ?? '');
                $nomeNormalizado = (string) ($row['nome_normalizado'] ?? '');
                $nomeOriginal = (string) ($row['nome_original'] ?? '');
                $uf = (string) ($row['uf'] ?? '');

                $this->upsert($ibgeCode, $nomeNormalizado, $nomeOriginal, $uf);
                $ibgeCodes[] = preg_replace('/\D/', '', $ibgeCode);
            }

            $ibgeCodes = array_values(array_unique(array_filter($ibgeCodes, static fn ($code): bool => $code !== '')));
            Capsule::table('mod_opennfse_ibge_municipios')
                ->whereNotIn('ibge_code', $ibgeCodes)
                ->delete();

            return count($ibgeCodes);
        });
    }

    private function sanitizeNome(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if ($value === '') {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > 120) {
            throw new \InvalidArgumentException('Nome do município excede o limite permitido.');
        }

        return $value;
    }
}
