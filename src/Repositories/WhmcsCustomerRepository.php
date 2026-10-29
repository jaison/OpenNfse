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

    /**
     * Valor bruto de um custom field do cliente, ou null se o cliente não tiver valor gravado.
     */
    public function getCustomFieldValue(int $userId, int $customFieldId): ?string
    {
        if ($userId <= 0 || $customFieldId <= 0) {
            return null;
        }

        // Com linhas duplicadas para o mesmo cliente e campo, vale a mais recente.
        $row = Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $userId)
            ->where('fieldid', $customFieldId)
            ->orderBy('id', 'desc')
            ->first();

        return $row ? (string) ($row->value ?? '') : null;
    }

    /**
     * Diz se o custom field de cliente ainda existe no WHMCS.
     */
    public function clientCustomFieldExists(int $customFieldId): bool
    {
        if ($customFieldId <= 0) {
            return false;
        }

        $row = Capsule::table('tblcustomfields')
            ->where('id', $customFieldId)
            ->where('type', 'client')
            ->first();

        return $row !== null;
    }
}
