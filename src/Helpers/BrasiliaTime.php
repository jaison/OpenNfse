<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

/**
 * Converte datas da NFS-e (dhEmi, horário de Brasília) para o fuso do servidor.
 *
 * O emitida_em das notas vem do dhEmi do XML e fica gravado no relógio de Brasília
 * (UTC-03:00, sem horário de verão desde 2019). As datas do WHMCS, como o datepaid,
 * ficam no fuso do PHP do servidor. Para comparar as duas, o emitida_em é deslocado
 * para o fuso do servidor.
 */
final class BrasiliaTime
{
    public const OFFSET_SECONDS = -10800;

    /**
     * Offset atual, em segundos, do fuso do servidor (ou do fuso informado).
     */
    public static function serverOffsetSeconds(?string $timezone = null): int
    {
        try {
            $zone = new \DateTimeZone($timezone ?? date_default_timezone_get());
        } catch (\Throwable $e) {
            $zone = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable('now', $zone))->getOffset();
    }

    /**
     * Segundos a somar a um horário de Brasília para obter o mesmo instante no fuso do servidor.
     */
    public static function shiftToServerSeconds(?int $serverOffsetSeconds = null): int
    {
        return ($serverOffsetSeconds ?? self::serverOffsetSeconds()) - self::OFFSET_SECONDS;
    }

    /**
     * Mesmo instante de um horário de Brasília ('Y-m-d H:i:s'), no fuso do servidor.
     */
    public static function toServerTime(string $brasiliaDateTime, ?int $serverOffsetSeconds = null): ?string
    {
        $brasiliaDateTime = trim($brasiliaDateTime);
        if ($brasiliaDateTime === '' || str_starts_with($brasiliaDateTime, '0000-00-00')) {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $brasiliaDateTime, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return null;
        }

        return $dt->modify(sprintf('%+d seconds', self::shiftToServerSeconds($serverOffsetSeconds)))->format('Y-m-d H:i:s');
    }

    /**
     * O pagamento (fuso do servidor) é posterior à emissão (horário de Brasília)?
     * Espelha a condição SQL do relatório "NFS-e de faturas não pagas".
     */
    public static function isPaidAfterEmission(string $emitidaEm, string $datePaid, ?int $serverOffsetSeconds = null): bool
    {
        $emitidaServer = self::toServerTime($emitidaEm, $serverOffsetSeconds);
        $datePaid = trim($datePaid);
        if ($emitidaServer === null || $datePaid === '' || str_starts_with($datePaid, '0000-00-00')) {
            return false;
        }

        return strcmp($datePaid, $emitidaServer) > 0;
    }
}
