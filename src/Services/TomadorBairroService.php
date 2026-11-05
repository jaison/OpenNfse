<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Repositories\WhmcsCustomerRepository;

/**
 * De onde vem o bairro do tomador: o Endereço 2 do WHMCS (padrão) ou um custom field do cliente.
 */
final class TomadorBairroService
{
    public const ORIGEM_ADDRESS2 = 'address2';
    public const ORIGEM_CUSTOMFIELD = 'customfield';

    /** Chave em $client com o valor do custom field de bairro, preenchida antes de montar a DPS. */
    public const CLIENT_KEY = 'nfse_bairro_customfield';

    /** @return array<string, string> */
    public static function origens(): array
    {
        return [
            self::ORIGEM_ADDRESS2 => 'Endereço 2 é o Bairro',
            self::ORIGEM_CUSTOMFIELD => 'Campo personalizado',
        ];
    }

    public static function origem(array $config): string
    {
        $origem = strtolower(trim((string) ($config['tomador_bairro_origem'] ?? '')));

        return $origem === self::ORIGEM_CUSTOMFIELD ? self::ORIGEM_CUSTOMFIELD : self::ORIGEM_ADDRESS2;
    }

    /**
     * @param array<int, int> $knownFieldIds custom fields de cliente existentes no WHMCS
     * @return array<int, string> mensagens de erro
     */
    public static function validateConfig(string $origem, string $customFieldId, array $knownFieldIds): array
    {
        if (!array_key_exists($origem, self::origens())) {
            return ['Origem do bairro do tomador inválida.'];
        }
        if ($customFieldId !== '' && !ctype_digit($customFieldId)) {
            return ['Custom field do bairro do tomador inválido.'];
        }
        if ($origem !== self::ORIGEM_CUSTOMFIELD) {
            return [];
        }
        if ((int) $customFieldId <= 0) {
            return ['Selecione o custom field do bairro do tomador.'];
        }
        if (!in_array((int) $customFieldId, $knownFieldIds, true)) {
            return ['O custom field selecionado para o bairro do tomador não existe.'];
        }

        return [];
    }

    /**
     * No modo custom field, anexa ao cliente o valor do campo de bairro (vazio quando não houver).
     */
    public function withCustomFieldBairro(array $config, array $client, WhmcsCustomerRepository $customerRepo): array
    {
        if (self::origem($config) !== self::ORIGEM_CUSTOMFIELD) {
            return $client;
        }

        $fieldId = (int) ($config['tomador_bairro_customfield_id'] ?? 0);
        $value = $customerRepo->getCustomFieldValue((int) ($client['id'] ?? 0), $fieldId);
        $client[self::CLIENT_KEY] = trim((string) $value);

        return $client;
    }
}
