<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Updater;

use OpenNfse\Controllers\UpdaterController;
use PHPUnit\Framework\TestCase;

final class BackupDownloadStatusTest extends TestCase
{
    public function testPayloadIsEmptyUntilTheDownloadIsRecorded(): void
    {
        $this->assertSame(
            ['downloaded_at' => null],
            UpdaterController::backupDownloadStatusPayload([])
        );
        $this->assertSame(
            ['downloaded_at' => null],
            UpdaterController::backupDownloadStatusPayload(['backup' => ['downloaded_at' => null]])
        );
    }

    public function testPayloadReturnsTheRecordedTimestamp(): void
    {
        $this->assertSame(
            ['downloaded_at' => '2026-11-16 10:00:00'],
            UpdaterController::backupDownloadStatusPayload([
                'backup' => ['downloaded_at' => '2026-11-16 10:00:00'],
            ])
        );
    }
}
