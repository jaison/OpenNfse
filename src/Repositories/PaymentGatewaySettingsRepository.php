<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class PaymentGatewaySettingsRepository
{
    private ?array $enabledByGateway = null;

    private ?array $emitOnCreateByGateway = null;

    public function getEnabledByGateway(): array
    {
        if ($this->enabledByGateway === null) {
            $this->load();
        }

        return $this->enabledByGateway ?? [];
    }

    /**
     * Gateways marcados para "Emitir ao gerar a fatura". Sem linha salva, o gateway não emite na geração.
     *
     * @return array<string, int>
     */
    public function getEmitOnCreateByGateway(): array
    {
        if ($this->emitOnCreateByGateway === null) {
            $this->load();
        }

        return $this->emitOnCreateByGateway ?? [];
    }

    public function isEmitOnCreate(string $gateway): bool
    {
        $gateway = strtolower(trim($gateway));
        if ($gateway === '') {
            return false;
        }

        return (int) ($this->getEmitOnCreateByGateway()[$gateway] ?? 0) === 1;
    }

    /**
     * Ajustes do gateway no formato que EmissionPolicyService::decide() recebe.
     *
     * @return array{enabled: bool, emit_on_create: bool}
     */
    public function getPolicySettings(string $gateway): array
    {
        return [
            'enabled' => $this->isEnabled($gateway),
            'emit_on_create' => $this->isEmitOnCreate($gateway),
        ];
    }

    private function load(): void
    {
        $enabled = [];
        $emitOnCreate = [];
        try {
            $rows = Capsule::table('mod_opennfse_payment_gateway_settings')->get();
            foreach ($rows as $r) {
                $gateway = (string) ($r->gateway ?? '');
                if ($gateway === '') {
                    continue;
                }
                $key = strtolower($gateway);
                $enabled[$key] = (int) ($r->enabled ?? 1);
                $emitOnCreate[$key] = (int) ($r->emit_on_create ?? 0);
            }
        } catch (\Throwable $e) {
            $enabled = [];
            $emitOnCreate = [];
        }

        $this->enabledByGateway = $enabled;
        $this->emitOnCreateByGateway = $emitOnCreate;
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

    /**
     * Grava "Emissão de NFS-e" e, quando $emitOnCreateGateways não é nulo, "Emitir ao gerar a fatura".
     */
    public function saveStatusForGateways(
        array $gateways,
        array $enabledGateways,
        ?array $emitOnCreateGateways = null
    ): void {
        $enabledSet = $this->toSet($enabledGateways);
        $emitOnCreateSet = $emitOnCreateGateways !== null ? $this->toSet($emitOnCreateGateways) : null;

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
            $values = ['enabled' => $enabled];
            if ($emitOnCreateSet !== null) {
                $values['emit_on_create'] = isset($emitOnCreateSet[$gw]) ? 1 : 0;
            }

            $existing = null;
            try {
                $existing = Capsule::table('mod_opennfse_payment_gateway_settings')->where('gateway', $gw)->first();
            } catch (\Throwable $e) {
                return;
            }

            if ($existing) {
                Capsule::table('mod_opennfse_payment_gateway_settings')->where('gateway', $gw)->update($values + [
                    'updated_at' => $now,
                ]);
                continue;
            }

            Capsule::table('mod_opennfse_payment_gateway_settings')->insert(['gateway' => $gw] + $values + [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->enabledByGateway = null;
        $this->emitOnCreateByGateway = null;
    }

    /** @return array<string, true> */
    private function toSet(array $gateways): array
    {
        $set = [];
        foreach ($gateways as $gw) {
            $gw = strtolower(trim((string) $gw));
            if ($gw === '') {
                continue;
            }
            $set[$gw] = true;
        }

        return $set;
    }
}
