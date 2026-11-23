<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Repositories\WhmcsCustomerRepository;

/**
 * Compra governamental: o custom field do cliente indica o ente (1 a 4).
 * Vazio não é governo. tpOper 2 e 3 ficam de fora (exigem NFS-e referenciada).
 */
final class CompraGovernamental
{
    public const CLIENT_KEY = 'nfse_compra_gov';
    public const TPOPER_PADRAO = '5';

    /** @var array<int, string> */
    public const TPOPER_VALIDOS = ['1', '4', '5'];

    /** @return array<string, string> */
    public static function tpOperOptions(): array
    {
        return [
            '1' => '1 - Fornecimento com pagamento posterior',
            '4' => '4 - Recebimento do pagamento com fornecimento posterior',
            '5' => '5 - Fornecimento e recebimento do pagamento concomitantes',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function validateConfig(string $tpOper, string $customFieldId): array
    {
        $errors = [];
        if (!in_array($tpOper, self::TPOPER_VALIDOS, true)) {
            $errors[] = 'Tipo de operação da compra governamental inválido (use 1, 4 ou 5).';
        }
        if ($customFieldId !== '' && $customFieldId !== '0' && !ctype_digit($customFieldId)) {
            $errors[] = 'Custom field de compra governamental inválido.';
        }

        return $errors;
    }

    public static function tpOper(array $config): int
    {
        $valor = trim((string) ($config['compra_gov_tpoper'] ?? self::TPOPER_PADRAO));
        if (!in_array($valor, self::TPOPER_VALIDOS, true)) {
            $valor = self::TPOPER_PADRAO;
        }

        return (int) $valor;
    }

    /**
     * "1".."4", ou texto que começa por esse dígito ("4 - Município"). O resto não é ente.
     */
    public static function parseEnte(?string $raw): ?int
    {
        $raw = trim((string) $raw);
        if (preg_match('/^([1-4])(?:\D.*)?$/u', $raw) !== 1) {
            return null;
        }

        return (int) $raw[0];
    }

    public function withValor(array $config, array $client, WhmcsCustomerRepository $customerRepo): array
    {
        $fieldId = (int) ($config['compra_gov_customfield_id'] ?? 0);
        if ($fieldId <= 0) {
            return $client;
        }

        $valor = $customerRepo->getCustomFieldValue((int) ($client['id'] ?? 0), $fieldId);
        $client[self::CLIENT_KEY] = trim((string) $valor);

        return $client;
    }

    public static function ente(array $config, array $client, ?WhmcsCustomerRepository $customerRepo = null): ?int
    {
        if (array_key_exists(self::CLIENT_KEY, $client)) {
            return self::parseEnte((string) $client[self::CLIENT_KEY]);
        }

        $fieldId = (int) ($config['compra_gov_customfield_id'] ?? 0);
        if ($fieldId <= 0) {
            return null;
        }

        $customerRepo ??= new WhmcsCustomerRepository();
        $valor = $customerRepo->getCustomFieldValue((int) ($client['id'] ?? 0), $fieldId);

        return self::parseEnte($valor);
    }
}
