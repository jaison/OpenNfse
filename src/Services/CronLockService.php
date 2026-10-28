<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use WHMCS\Database\Capsule;

final class CronLockService
{
    public const CRON_LOCK_SUFFIX = 'mod_opennfse_cron';

    // Limite do MySQL 5.7+ para nomes de GET_LOCK.
    public const MAX_LOCK_NAME_LENGTH = 64;

    private static ?string $databaseName = null;

    /**
     * Prefixa o nome do lock com o banco atual, para que duas instalações no
     * mesmo servidor MySQL não disputem o mesmo lock. Se o nome passar de 64
     * caracteres, o banco é trocado por um hash curto.
     */
    public static function buildScopedName(string $database, string $suffix): string
    {
        $database = trim($database);
        $suffix = trim($suffix);
        if ($database === '') {
            return $suffix;
        }

        $name = $database . '.' . $suffix;
        if (strlen($name) <= self::MAX_LOCK_NAME_LENGTH) {
            return $name;
        }

        return substr(sha1($database), 0, 16) . '.' . $suffix;
    }

    public function scopedName(string $suffix): string
    {
        return self::buildScopedName($this->currentDatabase(), $suffix);
    }

    public function cronLockName(): string
    {
        return $this->scopedName(self::CRON_LOCK_SUFFIX);
    }

    public function acquire(string $name, int $timeoutSeconds = 0): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $res = Capsule::selectOne('SELECT GET_LOCK(?, ?) AS locked', [$name, max(0, $timeoutSeconds)]);
        if (!$res) {
            return false;
        }

        $val = (int) ($res->locked ?? 0);
        return $val === 1;
    }

    public function release(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        Capsule::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
    }

    private function currentDatabase(): string
    {
        if (self::$databaseName === null) {
            $row = Capsule::selectOne('SELECT DATABASE() AS db');
            self::$databaseName = trim((string) ($row->db ?? ''));
        }

        return self::$databaseName;
    }
}
