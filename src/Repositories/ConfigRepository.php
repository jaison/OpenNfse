<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class ConfigRepository
{
    public function get(): array
    {
        $row = Capsule::table('mod_opennfse_config')->orderBy('id', 'desc')->first();
        if (!$row) {
            return [];
        }
        return (array) $row;
    }

    public function save(array $data): void
    {
        $now = date('Y-m-d H:i:s');
        $current = $this->get();
        if (empty($current)) {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            Capsule::table('mod_opennfse_config')->insert($data);
            return;
        }

        $id = (int) $current['id'];
        $data['updated_at'] = $now;
        Capsule::table('mod_opennfse_config')->where('id', $id)->update($data);
    }
}

