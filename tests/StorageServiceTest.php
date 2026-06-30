<?php

declare(strict_types=1);

namespace OpenNfse\Tests;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Services\StorageService;
use PHPUnit\Framework\TestCase;

final class StorageServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = rtrim(sys_get_temp_dir(), '/') . '/nfse_whmcs_tests_' . bin2hex(random_bytes(4));
        mkdir($base, 0750, true);
        $this->tmpDir = $base;

        $attachments = $this->tmpDir . '/attachments';
        mkdir($attachments . '/nfse/xml', 0750, true);
        mkdir($attachments . '/nfse/pdf', 0750, true);

        $GLOBALS['attachments_dir'] = $attachments;
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
        parent::tearDown();
    }

    public function testResolveAbsolutePathAllowsOnlyModuleDirs(): void
    {
        $svc = new StorageService();

        $xmlRel = 'nfse/xml/nfse_invoice_1_test.xml';
        $xmlAbs = $GLOBALS['attachments_dir'] . '/' . $xmlRel;
        file_put_contents($xmlAbs, '<xml />');

        $resolved = $svc->resolveAbsolutePath($xmlRel);
        $this->assertSame(realpath($xmlAbs), $resolved);
    }

    public function testResolveAbsolutePathBlocksTraversal(): void
    {
        $svc = new StorageService();

        $secretAbs = $this->tmpDir . '/attachments/secret.txt';
        file_put_contents($secretAbs, 'secret');

        $this->expectException(NfseModuleException::class);
        $svc->resolveAbsolutePath('nfse/xml/../../secret.txt');
    }

    private function rmDir(string $dir): void
    {
        $dir = rtrim($dir, '/');
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

