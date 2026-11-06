<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

use OpenNfse\Helpers\WebRoot;

final class WhmcsUpdaterEnvironment implements UpdaterEnvironment
{
    public function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function tempPath(): string
    {
        return rtrim(trim($this->setting('UpdateTempPath')), '/\\');
    }

    public function whmcsVersion(): string
    {
        return trim($this->setting('Version'));
    }

    public function webRoots(): array
    {
        return WebRoot::defaultRoots();
    }

    private function setting(string $name): string
    {
        if (!class_exists(\WHMCS\Config\Setting::class)) {
            return '';
        }
        try {
            return (string) \WHMCS\Config\Setting::getValue($name);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
