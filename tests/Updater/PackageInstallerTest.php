<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Updater;

use OpenNfse\Updater\PackageDownloader;
use OpenNfse\Updater\PackageInstaller;
use PHPUnit\Framework\TestCase;

final class PackageInstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opennfse-installer-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/addons/OpenNfse/src', 0755, true);
        mkdir($this->root . '/tmp', 0755);
        file_put_contents($this->root . '/addons/OpenNfse/OpenNfse.php', "<?php return ['version' => '1.0.0'];");
    }

    protected function tearDown(): void
    {
        PackageInstaller::removeTree($this->root);
    }

    public function testFullCycleInstallRollbackAndInstallAgain(): void
    {
        $zip = $this->makeZip($this->validFiles('1.0.1'));
        $installer = new PackageInstaller();
        $moduleDir = $this->root . '/addons/OpenNfse';

        $extracted = $installer->prepare($zip, hash_file('sha256', $zip), '1.0.1', $this->root . '/tmp');
        $installer->stage($extracted, $moduleDir);
        $installer->swap($moduleDir);

        $this->assertSame('1.0.1', PackageInstaller::declaredVersion($moduleDir));
        $this->assertSame('1.0.0', PackageInstaller::declaredVersion($moduleDir . '.old'));
        $this->assertDirectoryDoesNotExist($moduleDir . '.new');
        $this->assertFileExists($moduleDir . '/assets/.htaccess');

        $installer->rollbackFiles($moduleDir);
        $this->assertSame('1.0.0', PackageInstaller::declaredVersion($moduleDir));
        $this->assertDirectoryDoesNotExist($moduleDir . '.old');
        $this->assertSame([], glob($moduleDir . '.failed-*') ?: []);

        $extracted = $installer->prepare($zip, hash_file('sha256', $zip), '1.0.1', $this->root . '/tmp');
        $installer->stage($extracted, $moduleDir);
        $installer->swap($moduleDir);
        $installer->discardOld($moduleDir);
        $this->assertSame('1.0.1', PackageInstaller::declaredVersion($moduleDir));
        $this->assertDirectoryDoesNotExist($moduleDir . '.old');
    }

    public function testRejectsWrongHash(): void
    {
        $zip = $this->makeZip($this->validFiles('1.0.1'));
        $this->expectExceptionMessage('SHA-256');
        (new PackageInstaller())->prepare($zip, str_repeat('0', 64), '1.0.1', $this->root . '/tmp');
    }

    public function testRejectsVersionDifferentFromAnnounced(): void
    {
        $zip = $this->makeZip($this->validFiles('1.0.2'));
        $this->expectExceptionMessage('anunciada é 1.0.1');
        (new PackageInstaller())->prepare($zip, hash_file('sha256', $zip), '1.0.1', $this->root . '/tmp');
    }

    public function testRejectsPathTraversal(): void
    {
        $files = $this->validFiles('1.0.1');
        $files['OpenNfse/../../evil.php'] = '<?php';
        $zip = $this->makeZip($files);

        try {
            (new PackageInstaller())->prepare($zip, hash_file('sha256', $zip), '1.0.1', $this->root . '/tmp');
            $this->fail('Aceitou caminho com "..".');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('caminho inválido', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->root . '/evil.php');
        $this->assertSame([], glob($this->root . '/tmp/*') ?: []);
    }

    public function testRejectsSymlink(): void
    {
        $zip = $this->makeZip($this->validFiles('1.0.1'), ['OpenNfse/link' => '/etc/passwd']);
        $this->expectExceptionMessage('link simbólico');
        (new PackageInstaller())->prepare($zip, hash_file('sha256', $zip), '1.0.1', $this->root . '/tmp');
    }

    public function testRejectsSyntaxError(): void
    {
        $files = $this->validFiles('1.0.1');
        $files['OpenNfse/src/Quebrado.php'] = '<?php function (';
        $zip = $this->makeZip($files);
        $this->expectExceptionMessage('src/Quebrado.php');
        (new PackageInstaller())->prepare($zip, hash_file('sha256', $zip), '1.0.1', $this->root . '/tmp');
    }

    public function testRejectsPackageWithoutMarker(): void
    {
        $files = $this->validFiles('1.0.1');
        unset($files['OpenNfse/build-info.json']);
        $zip = $this->makeZip($files);
        $this->expectExceptionMessage('build-info.json');
        (new PackageInstaller())->prepare($zip, hash_file('sha256', $zip), '1.0.1', $this->root . '/tmp');
    }

    public function testSafeEntryNames(): void
    {
        PackageInstaller::assertSafeEntry('OpenNfse/.htaccess');
        PackageInstaller::assertSafeEntry('OpenNfse/src/A.php');
        foreach (['OpenNfse/./x', '/OpenNfse/x', 'Outro/x', 'OpenNfse/a\\b', 'OpenNfse/..', 'C:/OpenNfse/x', 'OpenNfse//x'] as $bad) {
            try {
                PackageInstaller::assertSafeEntry($bad);
                $this->fail('Aceitou ' . $bad);
            } catch (\RuntimeException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDownloaderOnlyAcceptsGithubHttps(): void
    {
        $this->assertTrue(PackageDownloader::isAllowedUrl('https://github.com/jaison/OpenNfse/releases/download/v1.0.1/OpenNfse-1.0.1.zip'));
        $this->assertTrue(PackageDownloader::isAllowedUrl('https://objects.githubusercontent.com/x?y=1'));
        $this->assertFalse(PackageDownloader::isAllowedUrl('http://github.com/x'));
        $this->assertFalse(PackageDownloader::isAllowedUrl('https://github.com.evil.com/x'));
        $this->assertFalse(PackageDownloader::isAllowedUrl('https://user@github.com/x'));
        $this->assertFalse(PackageDownloader::isAllowedUrl('https://github.com:8443/x'));
        $this->assertSame('https://github.com/a/b', PackageDownloader::resolveLocation('https://github.com/x/y', '/a/b'));
    }

    private function validFiles(string $version): array
    {
        return [
            'OpenNfse/' => null,
            'OpenNfse/OpenNfse.php' => "<?php return ['version' => '$version'];",
            'OpenNfse/hooks.php' => '<?php',
            'OpenNfse/bootstrap.php' => '<?php',
            'OpenNfse/vendor-scoped/autoload.php' => '<?php',
            'OpenNfse/src/A.php' => '<?php final class A {}',
            'OpenNfse/assets/.htaccess' => 'Require all granted',
            'OpenNfse/build-info.json' => json_encode(['module' => 'OpenNfse', 'version' => $version]),
        ];
    }

    /**
     * @param array<string, ?string> $files
     * @param array<string, string> $symlinks
     */
    private function makeZip(array $files, array $symlinks = []): string
    {
        $path = $this->root . '/pkg-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $content === null ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $content);
        }
        foreach ($symlinks as $name => $target) {
            $zip->addFromString($name, $target);
            $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, (0120777 << 16));
        }
        $zip->close();

        return $path;
    }
}
