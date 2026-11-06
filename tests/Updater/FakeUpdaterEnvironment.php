<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Updater;

use OpenNfse\Updater\UpdaterEnvironment;

final class FakeUpdaterEnvironment implements UpdaterEnvironment
{
    /** @param list<string> $webRoots */
    public function __construct(
        public string $moduleDir,
        public string $tempPath,
        public string $whmcsVersion = '8.13.1-release.1',
        public array $webRoots = []
    ) {
    }

    public function moduleDir(): string
    {
        return $this->moduleDir;
    }

    public function tempPath(): string
    {
        return $this->tempPath;
    }

    public function whmcsVersion(): string
    {
        return $this->whmcsVersion;
    }

    public function webRoots(): array
    {
        return $this->webRoots;
    }
}
