<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use OpenNfse\Exceptions\NfseValidationException;
use WHMCS\Database\Capsule;

final class WhmcsCustomerRepository
{
    public function getClient(int $userId): array
    {
        $row = Capsule::table('tblclients')->where('id', $userId)->first();
        if (!$row) {
            throw new NfseValidationException('Cliente não encontrado.');
        }
        return (array) $row;
    }

    public function getCpfCnpjFromCustomField(int $userId, int $customFieldId): string
    {
        $row = Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $userId)
            ->where('fieldid', $customFieldId)
            ->first();

        $value = $row ? (string) ($row->value ?? '') : '';
        $value = preg_replace('/\D/', '', $value);
        if (!$value) {
            throw new NfseValidationException('CPF/CNPJ do tomador não informado (custom field).');
        }
        if (strlen($value) !== 11 && strlen($value) !== 14) {
            throw new NfseValidationException('CPF/CNPJ do tomador inválido (tamanho).');
        }
        return $value;
    }

    public function getCustomFieldRawValue(int $userId, int $customFieldId): string
    {
        if ($userId <= 0 || $customFieldId <= 0) {
            return '';
        }

        $row = Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $userId)
            ->where('fieldid', $customFieldId)
            ->first();

        return trim((string) ($row->value ?? ''));
    }

    /**
     * Interpreta tickbox/dropdown do WHMCS como afirmativo (Sim).
     */
    public function isCustomFieldTruthy(int $userId, int $customFieldId): bool
    {
        $value = strtolower($this->getCustomFieldRawValue($userId, $customFieldId));
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return in_array($value, ['1', 'on', 'yes', 'sim', 'true', 's', 'y'], true);
    }
}

