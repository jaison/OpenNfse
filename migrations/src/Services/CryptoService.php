<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Exceptions\NfseModuleException;

final class CryptoService
{
    public function encrypt(string $plain): string
    {
        if (!function_exists('encrypt')) {
            throw new NfseModuleException('Função encrypt() não disponível no WHMCS.');
        }
        return (string) encrypt($plain);
    }

    public function decrypt(string $encrypted): string
    {
        if (!function_exists('decrypt')) {
            throw new NfseModuleException('Função decrypt() não disponível no WHMCS.');
        }
        return (string) decrypt($encrypted);
    }
}

