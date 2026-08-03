<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use WHMCS\Database\Capsule;

final class CronLockService
{
    public function acquire(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $res = Capsule::selectOne('SELECT GET_LOCK(?, 0) AS locked', [$name]);
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
}

