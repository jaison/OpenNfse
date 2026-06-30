<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class PaymentGatewaySettingsRepository
{
    private ?array $enabledByGateway = null;

    public function getEnabledByGateway(): array
    {
        if ($this->enabledByGateway !== null) {
            return $this->enabledByGateway;
        }

        $out = [];
        try {
            $rows = Capsule::table('mod_opennfse_payment_gateway_settings')->get();
            foreach ($rows as $r) {
                $gateway = (string) ($r->gateway ?? '');
                if ($gateway === '') {
                    continue;
                }
                $out[strtolower($gateway)] = (int) ($r->enabled ?? 1);
            }
        } catch (\Throwable $e) {
            $out = [];
        }

        $this->enabledByGateway = $out;
        return $out;
    }

    public function isEnabled(string $gateway): bool
    {
        $gateway = strtolower(trim($gateway));
        if ($gateway === '') {
            return true;
        }

        $map = $this->getEnabledByGateway();
        if (!array_key_exists($gateway, $map)) {
            return empty($map);
        }

        return (int) $map[$gateway] === 1;
    }

    public function saveStatusForGateways(array $gateways, array $enabledGateways): void
    {
        $enabledSet = [];
        foreach ($enabledGateways as $gw) {
            $gw = strtolower(trim((string) $gw));
            if ($gw === '') {
                continue;
            }
            $enabledSet[$gw] = true;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($gateways as $gw) {
            $gw = strtolower(trim((string) $gw));
            if ($gw === '') {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $gw)) {
                continue;
            }

            $enabled = isset($enabledSet[$gw]) ? 1 : 0;

            $existing = null;
            try {
                $existing = Capsule::table('mod_opennfse_payment_gateway_settings')->where('gateway', $gw)->first();
            } catch (\Throwable $e) {
                return;
            }

            if ($existing) {
                Capsule::table('mod_opennfse_payment_gateway_settings')->where('gateway', $gw)->update([
                    'enabled' => $enabled,
                    'updated_at' => $now,
                ]);
                continue;
            }

            Capsule::table('mod_opennfse_payment_gateway_settings')->insert([
                'gateway' => $gw,
                'enabled' => $enabled,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->enabledByGateway = null;
    }
}
