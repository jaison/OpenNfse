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

    public function testFallbackIgnoresFilesFromInvoicesWithSimilarIds(): void
    {
        $svc = new StorageService();
        $nota = $this->nota(1, '1', $this->chave('1'));

        $this->writeXml('nfse_11_11_102026.xml', '11', $this->chave('11'));
        $this->writeXml('nfse_10_10_102026.xml', '10', $this->chave('10'));
        $this->writeXml('nfse_1_11_102026.xml', '1', $this->chave('1'));
        $this->writeXml('nfse_1_10_102026.xml', '1', $this->chave('1'));
        $this->writeXml('nfse_invoice_11_test.xml', '1', $this->chave('1'));

        $this->assertNull($svc->findLatestXmlAbsolutePath(1, $nota));
    }

    public function testFallbackIgnoresLegacyFaturaFileFromAnotherInvoice(): void
    {
        $svc = new StorageService();
        $nota = $this->nota(1, '1', $this->chave('1'));

        $this->writeXml('nfse_1_fatura11_082026.xml', '1', $this->chave('1'));
        $this->writeXml('nfse_7_fatura2_082026.xml', '7', $this->chave('7'));
        $this->writeXml('nfse_1_faturaABC_082026.xml', '1', $this->chave('1'));

        $this->assertNull($svc->findLatestXmlAbsolutePath(1, $nota));
    }

    public function testFallbackFindsLegacyFaturaFileFromSameInvoice(): void
    {
        $svc = new StorageService();
        $nota = $this->nota(1, '1', $this->chave('1'));

        $expected = $this->writeXml('nfse_1_fatura1_082026.xml', '1', $this->chave('1'));

        $this->assertSame(realpath($expected), $svc->findLatestXmlAbsolutePath(1, $nota));
    }

    public function testFallbackFindsCorrectFileAmongOthers(): void
    {
        $svc = new StorageService();
        $nota = $this->nota(1, '42', $this->chave('42'));

        $expected = $this->writeXml('nfse_42_1_102026.xml', '42', $this->chave('42'), time() - 3600);
        $this->writeXml('nfse_43_11_102026.xml', '43', $this->chave('43'));
        $this->writeXml('nfse_44_10_102026.xml', '44', $this->chave('44'));
        $this->writeXml('nfse_45_fatura11_082026.xml', '45', $this->chave('45'));

        $this->assertSame(realpath($expected), $svc->findLatestXmlAbsolutePath(1, $nota));
    }

    public function testFallbackSkipsNewerFileWhoseContentDoesNotMatch(): void
    {
        $svc = new StorageService();
        $nota = $this->nota(1, '42', $this->chave('42'));

        $expected = $this->writeXml('nfse_42_1_092026.xml', '42', $this->chave('42'), time() - 3600);
        $this->writeXml('nfse_42_1_102026.xml', '99', $this->chave('99'));

        $this->assertSame(realpath($expected), $svc->findLatestXmlAbsolutePath(1, $nota));
    }

    public function testFallbackRejectsFileWithDifferentChave(): void
    {
        $svc = new StorageService();
        $nota = $this->nota(1, '42', $this->chave('42'));

        $this->writeXml('nfse_42_1_102026.xml', '42', $this->chave('99'));

        $this->assertNull($svc->findLatestXmlAbsolutePath(1, $nota));
    }

    public function testFallbackReturnsNullWithoutCandidates(): void
    {
        $svc = new StorageService();

        $this->assertNull($svc->findLatestXmlAbsolutePath(1, $this->nota(1, '42', $this->chave('42'))));
    }

    public function testFallbackReturnsNullWhenNotaHasNothingToCompare(): void
    {
        $svc = new StorageService();
        $this->writeXml('nfse_42_1_102026.xml', '42', $this->chave('42'));

        $this->assertNull($svc->findLatestXmlAbsolutePath(1, $this->nota(1, '', '')));
    }

    public function testFallbackReturnsNullWhenXmlHasNothingToCompare(): void
    {
        $svc = new StorageService();
        $path = $GLOBALS['attachments_dir'] . '/nfse/xml/nfse_42_1_102026.xml';
        file_put_contents($path, '<NFSe><infNFSe /></NFSe>');

        $this->assertNull($svc->findLatestXmlAbsolutePath(1, $this->nota(1, '42', $this->chave('42'))));
    }

    public function testFallbackRejectsNotaFromAnotherInvoice(): void
    {
        $svc = new StorageService();
        $this->writeXml('nfse_42_1_102026.xml', '42', $this->chave('42'));

        $this->assertNull($svc->findLatestXmlAbsolutePath(1, $this->nota(11, '42', $this->chave('42'))));
    }

    /**
     * @return array<string, mixed>
     */
    private function nota(int $invoiceId, string $numeroNf, string $chave): array
    {
        return [
            'id' => 1,
            'invoiceid' => $invoiceId,
            'numero_nf' => $numeroNf,
            'chave_acesso' => $chave,
        ];
    }

    private function chave(string $numero): string
    {
        return str_pad($numero, 50, '0', STR_PAD_LEFT);
    }

    private function writeXml(string $filename, string $numero, string $chave, ?int $mtime = null): string
    {
        $dir = $GLOBALS['attachments_dir'] . '/nfse/xml/homologacao/sem-serie/2026/10';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $path = $dir . '/' . $filename;
        file_put_contents(
            $path,
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe Id="NFS' . $chave . '">'
            . '<nNFSe>' . $numero . '</nNFSe></infNFSe></NFSe>'
        );
        touch($path, $mtime ?? time());

        return $path;
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
