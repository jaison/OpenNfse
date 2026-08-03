<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Exceptions\NfseModuleException;

final class TokenService
{
    public function token(): string
    {
        if (function_exists('generate_token')) {
            return (string) generate_token('plain');
        }

        if (!empty($_SESSION['token'])) {
            return (string) $_SESSION['token'];
        }

        return '';
    }

    public function validate(): void
    {
        if (function_exists('check_token')) {
            check_token();
            return;
        }

        $token = (string) ($_POST['token'] ?? '');
        if ($token === '' || empty($_SESSION['token']) || !hash_equals((string) $_SESSION['token'], $token)) {
            throw new NfseModuleException('Token CSRF inválido.');
        }
    }
}
