<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\UpdateCheckService;
use PHPUnit\Framework\TestCase;

final class UpdateCheckReleasesTest extends TestCase
{
    public function testPicksHighestReleaseWithPackageIncludingPrereleases(): void
    {
        $picked = UpdateCheckService::pickRelease([
            $this->release('v1.0.0-rc.1'),
            $this->release('v1.0.0-rc.2'),
            $this->release('v0.1.28', false),
            $this->release('v1.0.0-rc.3', true, true),
            ['tag_name' => 'v9.9.9', 'assets' => []],
            ['tag_name' => 'v1.0.0-rc.9', 'draft' => true, 'assets' => $this->release('v1.0.0-rc.9')['assets']],
            ['tag_name' => 'sem-v', 'assets' => []],
        ]);

        $this->assertNotNull($picked);
        $this->assertSame('1.0.0-rc.2', $picked['version']);
        $this->assertSame('https://github.com/jaison/OpenNfse/releases/download/v1.0.0-rc.2/OpenNfse-1.0.0-rc.2.zip', $picked['zip_url']);
    }

    public function testFinalReleaseWinsOverItsPrereleases(): void
    {
        $picked = UpdateCheckService::pickRelease([$this->release('v1.0.0-rc.2'), $this->release('v1.0.0')]);

        $this->assertSame('1.0.0', $picked['version'] ?? null);
    }

    public function testNothingToPick(): void
    {
        $this->assertNull(UpdateCheckService::pickRelease([]));
    }

    public function testVersionPatternAcceptsPrereleases(): void
    {
        foreach (['1.0.0', '1.0.0-rc.1', '1.0.0-beta'] as $ok) {
            $this->assertSame(1, preg_match(UpdateCheckService::VERSION_PATTERN, $ok), $ok);
        }
        foreach (['1.0', '1.0.0-', '1.0.0-rc..1', 'v1.0.0', '1.0.0/../x'] as $bad) {
            $this->assertSame(0, preg_match(UpdateCheckService::VERSION_PATTERN, $bad), $bad);
        }
    }

    public function testChannelDefaultsToStable(): void
    {
        $this->assertSame('stable', UpdateCheckService::channel([]));
        $this->assertSame('stable', UpdateCheckService::channel(['update_channel' => 'outro']));
        $this->assertSame('beta', UpdateCheckService::channel(['update_channel' => 'beta']));
    }

    private function release(string $tag, bool $withSha = true, bool $missingZip = false): array
    {
        $version = substr($tag, 1);
        $base = 'https://github.com/jaison/OpenNfse/releases/download/' . $tag . '/OpenNfse-' . $version . '.zip';
        $assets = [];
        if (!$missingZip) {
            $assets[] = ['name' => 'OpenNfse-' . $version . '.zip', 'browser_download_url' => $base];
        }
        if ($withSha) {
            $assets[] = ['name' => 'OpenNfse-' . $version . '.zip.sha256', 'browser_download_url' => $base . '.sha256'];
        }

        return ['tag_name' => $tag, 'draft' => false, 'prerelease' => strpos($tag, '-') !== false, 'assets' => $assets, 'html_url' => 'https://github.com/jaison/OpenNfse/releases/tag/' . $tag];
    }
}
