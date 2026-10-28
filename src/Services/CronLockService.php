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

    private static ?bool $multipleLocksSupported = null;

    /** @var array<string, int> locks obtidos por este processo (nome => quantidade) */
    private static array $held = [];

    /**
     * MySQL 5.7.5+ e MariaDB 10.0.2+ aceitam vários GET_LOCK na mesma sessão. Nas versões
     * anteriores, um GET_LOCK novo solta o lock que a sessão já tinha. Versão desconhecida
     * conta como "não aceita", o lado seguro.
     */
    public static function supportsMultipleLocks(string $version): bool
    {
        $version = strtolower(trim($version));
        $isMariaDb = strpos($version, 'mariadb') !== false;
        if ($isMariaDb && strpos($version, '5.5.5-') === 0) {
            $version = substr($version, 6);
        }

        if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $version, $m)) {
            return false;
        }

        $normalized = $m[1] . '.' . $m[2] . '.' . ($m[3] ?? '0');

        return version_compare($normalized, $isMariaDb ? '10.0.2' : '5.7.5', '>=');
    }

    public function serverSupportsMultipleLocks(): bool
    {
        if (self::$multipleLocksSupported === null) {
            $row = Capsule::selectOne('SELECT VERSION() AS version');
            self::$multipleLocksSupported = self::supportsMultipleLocks((string) ($row->version ?? ''));
        }

        return self::$multipleLocksSupported;
    }

    public function holdsAnyLock(): bool
    {
        return !empty(self::$held);
    }

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
        if ($val !== 1) {
            return false;
        }

        self::$held[$name] = (self::$held[$name] ?? 0) + 1;
        return true;
    }

    public function release(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        Capsule::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);

        if (isset(self::$held[$name])) {
            self::$held[$name]--;
            if (self::$held[$name] <= 0) {
                unset(self::$held[$name]);
            }
        }
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
