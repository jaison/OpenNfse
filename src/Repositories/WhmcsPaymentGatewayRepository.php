<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class WhmcsPaymentGatewayRepository
{
    public function listActive(): array
    {
        $rows = [];
        try {
            $rows = Capsule::table('tblpaymentgateways as pg')
                ->select(
                    'pg.gateway',
                    Capsule::raw("MAX(CASE WHEN pg.setting='name' THEN pg.value ELSE '' END) as display_name"),
                    Capsule::raw("MAX(CASE WHEN pg.setting='visible' THEN pg.value ELSE '' END) as visible_value")
                )
                ->groupBy('pg.gateway')
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $gateway = (string) ($r->gateway ?? '');
            if ($gateway === '') {
                continue;
            }
            $gateway = strtolower(trim($gateway));
            if ($gateway === '') {
                continue;
            }

            $visible = trim((string) ($r->visible_value ?? ''));
            if ($visible !== '') {
                $v = strtolower($visible);
                if (!in_array($v, ['on', '1', 'true', 'yes'], true)) {
                    continue;
                }
            }

            $name = trim((string) ($r->display_name ?? ''));
            if ($name === '') {
                $name = $gateway;
            }

            $out[] = [
                'gateway' => $gateway,
                'name' => $name,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $out;
    }
}
