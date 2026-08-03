<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

final class CorrelationIdGenerator
{
    public static function generate(int $invoiceId): string
    {
        try {
            $rand = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $rand = bin2hex((string) microtime(true));
        }

        return 'inv' . $invoiceId . '_' . $rand;
    }
}
