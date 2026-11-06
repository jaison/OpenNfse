<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Helpers;

use OpenNfse\Helpers\AddonVersion;
use PHPUnit\Framework\TestCase;

final class AddonVersionTest extends TestCase
{
    public function testCurrentReadsVersionFromOpenNfsePhp(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) AddonVersion::current());
    }

    public function testVerAplicFollowsAddonVersion(): void
    {
        $verAplic = AddonVersion::verAplic();

        $this->assertStringStartsWith('whmcs-nfse/' . preg_replace('/-.*$/', '', (string) AddonVersion::current()), $verAplic);
        $this->assertLessThanOrEqual(20, strlen($verAplic));
    }

    public function testVerAplicCompactsPrereleaseSuffixToFit(): void
    {
        $this->assertSame('whmcs-nfse/1.0.0', AddonVersion::verAplic('1.0.0'));
        $this->assertSame('whmcs-nfse/1.0.0rc1', AddonVersion::verAplic('1.0.0-rc.1'));
        $this->assertSame('whmcs-nfse/1.0.0-rc', AddonVersion::verAplic('1.0.0-rc'));
    }

    public function testVerAplicNeverExceedsTwentyCharacters(): void
    {
        $verAplic = AddonVersion::verAplic('10.20.300-beta.1');

        $this->assertSame(20, strlen($verAplic));
        $this->assertSame('whmcs-nfse/10.20.300', $verAplic);
    }

    public function testVerAplicFallsBackWithoutReadableVersion(): void
    {
        $this->assertSame('whmcs-nfse', AddonVersion::verAplic(''));
        $this->assertSame('whmcs-nfse', AddonVersion::verAplic('0.1.26 <x>'));
    }
}
