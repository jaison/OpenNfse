<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class GroupServiceCodeRepository
{
    public function getAllByGroupId(): array
    {
        $rows = Capsule::table('mod_opennfse_group_service_codes')
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $gid = (int) ($r->groupid ?? 0);
            if ($gid <= 0) {
                continue;
            }
            if (isset($out[$gid])) {
                continue;
            }
            $out[$gid] = [
                'codigo_servico' => $r->codigo_servico !== null ? (string) $r->codigo_servico : null,
                'nbs' => $r->nbs !== null ? (string) $r->nbs : null,
            ];
        }
        return $out;
    }

    public function upsert(int $groupId, ?string $codigoServico, ?string $nbs): void
    {
        $groupId = (int) $groupId;
        if ($groupId <= 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        Capsule::connection()->transaction(function () use ($groupId, $codigoServico, $nbs, $now): void {
            Capsule::table('mod_opennfse_group_service_codes')->where('groupid', $groupId)->delete();

            Capsule::table('mod_opennfse_group_service_codes')->insert([
                'groupid' => $groupId,
                'codigo_servico' => $codigoServico,
                'nbs' => $nbs,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function delete(int $groupId): void
    {
        $groupId = (int) $groupId;
        if ($groupId <= 0) {
            return;
        }
        Capsule::table('mod_opennfse_group_service_codes')->where('groupid', $groupId)->delete();
    }
}
