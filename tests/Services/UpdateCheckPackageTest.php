<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\UpdateCheckService;
use PHPUnit\Framework\TestCase;

final class UpdateCheckPackageTest extends TestCase
{
    public function testAcceptsReleaseAssetOfAnnouncedVersion(): void
    {
        $sha = str_repeat('ab', 32);
        [$url, $hash] = UpdateCheckService::parsePackage([
            'package_url' => 'https://github.com/jaison/OpenNfse/releases/download/v1.0.1/OpenNfse-1.0.1.zip',
            'package_sha256' => strtoupper($sha),
        ], '1.0.1');

        $this->assertSame('https://github.com/jaison/OpenNfse/releases/download/v1.0.1/OpenNfse-1.0.1.zip', $url);
        $this->assertSame($sha, $hash);
    }

    public function testPackageIsOptional(): void
    {
        $this->assertSame(['', ''], UpdateCheckService::parsePackage([], '1.0.1'));
    }

    /**
     * @dataProvider invalidPackages
     */
    public function testRejectsOtherUrlsAndBadHash(string $url, string $sha): void
    {
        $this->expectException(\RuntimeException::class);
        UpdateCheckService::parsePackage(['package_url' => $url, 'package_sha256' => $sha], '1.0.1');
    }

    public static function invalidPackages(): array
    {
        $sha = str_repeat('a', 64);

        return [
            'outra versão' => ['https://github.com/jaison/OpenNfse/releases/download/v1.0.0/OpenNfse-1.0.0.zip', $sha],
            'outro repositório' => ['https://github.com/outro/OpenNfse/releases/download/v1.0.1/OpenNfse-1.0.1.zip', $sha],
            'http' => ['http://github.com/jaison/OpenNfse/releases/download/v1.0.1/OpenNfse-1.0.1.zip', $sha],
            'hash curto' => ['https://github.com/jaison/OpenNfse/releases/download/v1.0.1/OpenNfse-1.0.1.zip', 'abc'],
            'só hash' => ['', $sha],
        ];
    }
}
