<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Updater;

use OpenNfse\Updater\PreflightCheck;
use PHPUnit\Framework\TestCase;

final class PreflightCheckTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/opennfse-preflight-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public_html/modules/addons/OpenNfse/src', 0755, true);
        file_put_contents($this->root . '/public_html/modules/addons/OpenNfse/OpenNfse.php', '<?php');
        file_put_contents($this->root . '/public_html/modules/addons/OpenNfse/build-info.json', '{"module":"OpenNfse","version":"1.0.0"}');
        mkdir($this->root . '/tmp', 0755);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testPassesWhenEverythingIsInPlace(): void
    {
        $result = (new PreflightCheck($this->env()))->run($this->manifest(), '1.0.0');

        $this->assertTrue($result['can_update'], json_encode($this->failures($result)));
        $this->assertTrue($result['backup_copy_on_server']);
        $this->assertSame([], glob($this->root . '/tmp/.opennfse-probe*') ?: []);
        $this->assertSame([], glob($this->root . '/public_html/modules/addons/.opennfse-probe*') ?: []);
    }

    public function testBlocksWithoutTempPath(): void
    {
        $env = $this->env();
        $env->tempPath = '';

        $result = (new PreflightCheck($env))->run($this->manifest(), '1.0.0');

        $this->assertFalse($result['can_update']);
        $this->assertContains('temp', $this->failures($result));
        $this->assertFalse($result['backup_copy_on_server']);
    }

    public function testTempInsideWebRootOnlyWarns(): void
    {
        mkdir($this->root . '/public_html/tmp');
        $env = $this->env();
        $env->tempPath = $this->root . '/public_html/tmp';

        $result = (new PreflightCheck($env))->run($this->manifest(), '1.0.0');

        $this->assertTrue($result['can_update']);
        $this->assertFalse($result['backup_copy_on_server']);
        $this->assertSame(PreflightCheck::WARN, $this->itemStatus($result, 'temp_location'));
    }

    public function testBlocksGitCheckout(): void
    {
        mkdir($this->root . '/public_html/modules/addons/OpenNfse/.git');

        $result = (new PreflightCheck($this->env()))->run($this->manifest(), '1.0.0');

        $this->assertContains('package_install', $this->failures($result));
    }

    public function testBlocksInstallWithoutPackageMarker(): void
    {
        // Ex.: checkout com git-dir fora da pasta, ou zip do código-fonte.
        unlink($this->root . '/public_html/modules/addons/OpenNfse/build-info.json');

        $result = (new PreflightCheck($this->env()))->run($this->manifest(), '1.0.0');

        $this->assertContains('package_install', $this->failures($result));
    }

    public function testBlocksMarkerFromAnotherVersion(): void
    {
        // Ex.: arquivos de outra versão copiados por cima de uma instalação por pacote.
        $result = (new PreflightCheck($this->env()))->run($this->manifest(), '0.9.0');

        $this->assertContains('package_install', $this->failures($result));
    }

    public function testBlocksWithoutPackageOrNewerVersion(): void
    {
        $manifest = $this->manifest();
        unset($manifest['package_sha256']);
        $this->assertContains('package', $this->failures((new PreflightCheck($this->env()))->run($manifest, '1.0.0')));

        $this->assertContains('package', $this->failures((new PreflightCheck($this->env()))->run($this->manifest(), '1.0.1')));
    }

    public function testBlocksOldWhmcsAndPhp(): void
    {
        $env = $this->env();
        $env->whmcsVersion = '8.12.0-release.1';
        $manifest = $this->manifest();
        $manifest['minimum_php'] = '99.0';

        $failures = $this->failures((new PreflightCheck($env))->run($manifest, '1.0.0'));

        $this->assertContains('whmcs', $failures);
        $this->assertContains('php', $failures);
    }

    public function testBlocksReadOnlyModuleFolder(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignora permissões.');
        }
        $src = $this->root . '/public_html/modules/addons/OpenNfse/src';
        chmod($src, 0555);
        try {
            $result = (new PreflightCheck($this->env()))->run($this->manifest(), '1.0.0');
        } finally {
            chmod($src, 0755);
        }

        $this->assertContains('module', $this->failures($result));
    }

    public function testNumericVersion(): void
    {
        $this->assertSame('8.13.1', PreflightCheck::numericVersion('8.13.1-release.1'));
        $this->assertSame('', PreflightCheck::numericVersion('desconhecida'));
    }

    private function env(): FakeUpdaterEnvironment
    {
        return new FakeUpdaterEnvironment(
            $this->root . '/public_html/modules/addons/OpenNfse',
            $this->root . '/tmp',
            '8.13.1-release.1',
            [$this->root . '/public_html']
        );
    }

    private function manifest(): array
    {
        return [
            'latest_version' => '1.0.1',
            'package_url' => 'https://github.com/jaison/OpenNfse/releases/download/v1.0.1/OpenNfse-1.0.1.zip',
            'package_sha256' => str_repeat('a', 64),
            'minimum_php' => '8.1',
            'minimum_whmcs' => '8.13.0',
        ];
    }

    private function failures(array $result): array
    {
        $keys = [];
        foreach ($result['items'] as $item) {
            if ($item['status'] === PreflightCheck::FAIL) {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    private function itemStatus(array $result, string $key): ?string
    {
        foreach ($result['items'] as $item) {
            if ($item['key'] === $key) {
                return $item['status'];
            }
        }

        return null;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path => $info) {
            $info->isDir() && !$info->isLink() ? rmdir((string) $path) : unlink((string) $path);
        }
        rmdir($dir);
    }
}
