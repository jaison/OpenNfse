<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class ServiceNbsCatalogRepository
{
    public function countAll(): int
    {
        return (int) Capsule::table('mod_opennfse_service_nbs_catalog')->count();
    }

    public function listAll(): array
    {
        $out = [];
        $seen = [];
        foreach (Capsule::table('mod_opennfse_service_nbs_catalog')
            ->orderBy('codigo_servico', 'asc')
            ->orderBy('nbs', 'asc')
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->get() as $row) {
            $codigoServico = preg_replace('/\D/', '', (string) ($row->codigo_servico ?? ''));
            $nbs = preg_replace('/\D/', '', (string) ($row->nbs ?? ''));
            if ($codigoServico === '' || $nbs === '') {
                continue;
            }
            $key = $codigoServico . '|' . $nbs;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'id' => (int) ($row->id ?? 0),
                'codigo_servico' => $codigoServico,
                'nbs' => $nbs,
                'descricao' => (string) ($row->descricao ?? ''),
            ];
        }

        return $out;
    }

    public function getServiceOptions(bool $includeDefault = false): array
    {
        $options = [];
        if ($includeDefault) {
            $options[''] = 'Padrão';
        }

        foreach (Capsule::table('mod_opennfse_service_nbs_catalog')
            ->select('codigo_servico')
            ->distinct()
            ->orderBy('codigo_servico', 'asc')
            ->get() as $row) {
            $codigo = preg_replace('/\D/', '', (string) ($row->codigo_servico ?? ''));
            if ($codigo === '') {
                continue;
            }
            $options[$codigo] = $codigo;
        }

        return $options;
    }

    public function getNbsOptionsByServiceCode(): array
    {
        $out = [];
        foreach (Capsule::table('mod_opennfse_service_nbs_catalog')
            ->orderBy('codigo_servico', 'asc')
            ->orderBy('nbs', 'asc')
            ->get() as $row) {
            $codigo = preg_replace('/\D/', '', (string) ($row->codigo_servico ?? ''));
            $nbs = preg_replace('/\D/', '', (string) ($row->nbs ?? ''));
            $descricao = trim((string) ($row->descricao ?? ''));
            if ($codigo === '' || $nbs === '') {
                continue;
            }

            $label = $nbs;
            if ($descricao !== '') {
                $label .= ' - ' . $descricao;
            }

            if (!isset($out[$codigo])) {
                $out[$codigo] = [];
            }
            $out[$codigo][$nbs] = $label;
        }

        return $out;
    }

    public function exists(int $id): bool
    {
        return Capsule::table('mod_opennfse_service_nbs_catalog')->where('id', $id)->exists();
    }

    public function existsByServiceAndNbs(string $codigoServico, string $nbs): bool
    {
        return Capsule::table('mod_opennfse_service_nbs_catalog')
            ->where('codigo_servico', $codigoServico)
            ->where('nbs', $nbs)
            ->exists();
    }

    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = Capsule::table('mod_opennfse_service_nbs_catalog')->where('id', $id)->first();
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) ($row->id ?? 0),
            'codigo_servico' => (string) ($row->codigo_servico ?? ''),
            'nbs' => (string) ($row->nbs ?? ''),
            'descricao' => (string) ($row->descricao ?? ''),
        ];
    }

    public function insert(string $codigoServico, string $nbs, string $descricao): void
    {
        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_opennfse_service_nbs_catalog')->insert([
            'codigo_servico' => $codigoServico,
            'nbs' => $nbs,
            'descricao' => $descricao,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        Capsule::table('mod_opennfse_service_nbs_catalog')->where('id', $id)->delete();
    }

    public function deleteByServiceAndNbs(string $codigoServico, string $nbs): int
    {
        if ($codigoServico === '' || $nbs === '') {
            return 0;
        }

        return (int) Capsule::table('mod_opennfse_service_nbs_catalog')
            ->where('codigo_servico', $codigoServico)
            ->where('nbs', $nbs)
            ->delete();
    }

    public function listMatchingIdsByNormalizedPair(string $codigoServico, string $nbs): array
    {
        $ids = [];
        if ($codigoServico === '' || $nbs === '') {
            return $ids;
        }

        foreach (Capsule::table('mod_opennfse_service_nbs_catalog')->get() as $candidate) {
            $candidateId = (int) ($candidate->id ?? 0);
            if ($candidateId <= 0) {
                continue;
            }

            $candidateCodigoServico = preg_replace('/\D/', '', (string) ($candidate->codigo_servico ?? ''));
            $candidateNbs = preg_replace('/\D/', '', (string) ($candidate->nbs ?? ''));
            if ($candidateCodigoServico === $codigoServico && $candidateNbs === $nbs) {
                $ids[] = $candidateId;
            }
        }

        return $ids;
    }

    public function deleteIds(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $candidateId) {
            $candidateId = (int) $candidateId;
            if ($candidateId <= 0) {
                continue;
            }
            $deleted += (int) Capsule::table('mod_opennfse_service_nbs_catalog')
                ->where('id', $candidateId)
                ->delete();
        }

        return $deleted;
    }

    public function deleteRelationById(int $id): int
    {
        $row = $this->findById($id);
        if ($row === null) {
            return 0;
        }

        $targetCodigoServico = preg_replace('/\D/', '', (string) ($row['codigo_servico'] ?? ''));
        $targetNbs = preg_replace('/\D/', '', (string) ($row['nbs'] ?? ''));
        if ($targetCodigoServico === '' || $targetNbs === '') {
            return 0;
        }

        $idsToDelete = $this->listMatchingIdsByNormalizedPair($targetCodigoServico, $targetNbs);
        if (empty($idsToDelete)) {
            return 0;
        }

        $deleted = 0;
        Capsule::connection()->transaction(function () use ($idsToDelete, &$deleted): void {
            $deleted = $this->deleteIds($idsToDelete);
        });

        return $deleted;
    }
}
