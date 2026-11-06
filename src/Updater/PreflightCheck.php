<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

use OpenNfse\Helpers\WebRoot;

/**
 * Etapa 1 do atualizador: confere se dá para atualizar sem mexer em nada.
 *
 * Cada item volta com status ok, warn (segue, mas avisa) ou fail (bloqueia).
 * As provas de escrita criam e apagam arquivos de teste de verdade, porque
 * is_writable() engana em alguns servidores (ACL, open_basedir, NFS).
 */
final class PreflightCheck
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    private const REQUIRED_EXTENSIONS = ['zip' => 'ZipArchive', 'curl' => null, 'openssl' => null, 'json' => null];
    private const MIN_FREE_BYTES = 100 * 1024 * 1024;

    /** Marcador que o workflow do release põe no zip. */
    public const BUILD_INFO_FILE = 'build-info.json';

    private UpdaterEnvironment $env;

    public function __construct(?UpdaterEnvironment $env = null)
    {
        $this->env = $env ?? new WhmcsUpdaterEnvironment();
    }

    /**
     * @param array<string, string> $manifest latest_version, package_url, package_sha256, minimum_php, minimum_whmcs
     * @return array{can_update: bool, backup_copy_on_server: bool, items: list<array{key: string, label: string, status: string, detail: string}>}
     */
    public function run(array $manifest, string $currentVersion): array
    {
        $items = [];
        $moduleDir = rtrim($this->env->moduleDir(), '/\\');
        $tempPath = $this->env->tempPath();

        $items[] = $this->checkPackage($manifest, $currentVersion);
        $items[] = $this->checkPhp($manifest);
        $items[] = $this->checkWhmcs($manifest);
        $items[] = $this->checkExtensions();
        $items[] = $this->checkInstalledFromPackage($moduleDir, $currentVersion);
        $items[] = $this->checkTempPath($tempPath);
        $tempOutsideWebRoot = $tempPath !== '' && is_dir($tempPath) && !WebRoot::contains($tempPath, $this->env->webRoots());
        $items[] = $this->checkTempLocation($tempPath, $tempOutsideWebRoot);
        $items[] = $this->checkAddonsDir(dirname($moduleDir));
        [$moduleItem, $moduleBytes] = $this->checkModuleTree($moduleDir);
        $items[] = $moduleItem;
        $items[] = $this->checkDiskSpace($tempPath, dirname($moduleDir), $moduleBytes);

        $canUpdate = true;
        foreach ($items as $item) {
            if ($item['status'] === self::FAIL) {
                $canUpdate = false;
                break;
            }
        }

        return [
            'can_update' => $canUpdate,
            'backup_copy_on_server' => $tempOutsideWebRoot,
            'items' => $items,
        ];
    }

    private function checkPackage(array $manifest, string $currentVersion): array
    {
        $latest = trim((string) ($manifest['latest_version'] ?? ''));
        $url = trim((string) ($manifest['package_url'] ?? ''));
        $sha = strtolower(trim((string) ($manifest['package_sha256'] ?? '')));
        $label = 'Pacote da nova versão';

        if ($latest === '' || !version_compare($latest, $currentVersion, '>')) {
            return $this->item('package', $label, self::FAIL, 'Não há versão mais nova que a instalada. Clique em "Verificar agora" para consultar de novo.');
        }
        if ($url === '' || !preg_match('/^[0-9a-f]{64}$/', $sha)) {
            return $this->item('package', $label, self::FAIL, sprintf('A versão %s não tem pacote para atualização automática. Atualize à mão pelo GitHub.', $latest));
        }

        return $this->item('package', $label, self::OK, sprintf('Versão %s, com SHA-256 publicado.', $latest));
    }

    private function checkPhp(array $manifest): array
    {
        $minimum = trim((string) ($manifest['minimum_php'] ?? ''));
        $label = 'Versão do PHP';
        if ($minimum !== '' && version_compare(PHP_VERSION, $minimum, '<')) {
            return $this->item('php', $label, self::FAIL, sprintf('PHP %s instalado; a nova versão pede %s ou mais.', PHP_VERSION, $minimum));
        }

        return $this->item('php', $label, self::OK, 'PHP ' . PHP_VERSION . '.');
    }

    private function checkWhmcs(array $manifest): array
    {
        $minimum = trim((string) ($manifest['minimum_whmcs'] ?? ''));
        $installed = self::numericVersion($this->env->whmcsVersion());
        $label = 'Versão do WHMCS';
        if ($minimum === '') {
            return $this->item('whmcs', $label, self::OK, $installed !== '' ? 'WHMCS ' . $installed . '.' : 'Sem versão mínima no manifesto.');
        }
        if ($installed === '') {
            return $this->item('whmcs', $label, self::WARN, sprintf('Não foi possível ler a versão do WHMCS. A nova versão pede %s ou mais.', $minimum));
        }
        if (version_compare($installed, self::numericVersion($minimum), '<')) {
            return $this->item('whmcs', $label, self::FAIL, sprintf('WHMCS %s instalado; a nova versão pede %s ou mais.', $installed, $minimum));
        }

        return $this->item('whmcs', $label, self::OK, 'WHMCS ' . $installed . '.');
    }

    private function checkExtensions(): array
    {
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $extension => $class) {
            if (!extension_loaded($extension) || ($class !== null && !class_exists($class))) {
                $missing[] = $extension;
            }
        }
        $label = 'Extensões do PHP';
        if ($missing !== []) {
            return $this->item('extensions', $label, self::FAIL, 'Faltam: ' . implode(', ', $missing) . '.');
        }

        return $this->item('extensions', $label, self::OK, implode(', ', array_keys(self::REQUIRED_EXTENSIONS)) . '.');
    }

    /**
     * Só atualiza instalação feita com o zip do release: ele traz o build-info.json da mesma versão.
     * Checkout git (inclusive com git-dir fora da pasta) e cópia do código-fonte não têm o marcador.
     */
    private function checkInstalledFromPackage(string $moduleDir, string $currentVersion): array
    {
        $label = 'Instalação a partir de pacote';
        $manual = 'Atualize do mesmo jeito que instalou (git ou cópia manual). Para usar a atualização automática, '
            . 'instale uma vez o arquivo OpenNfse-<versão>.zip anexado ao release.';
        if (file_exists($moduleDir . '/.git')) {
            return $this->item('package_install', $label, self::FAIL, 'A pasta do módulo é um checkout do git. ' . $manual);
        }
        $info = json_decode((string) @file_get_contents($moduleDir . '/' . self::BUILD_INFO_FILE), true);
        if (!is_array($info) || ($info['module'] ?? '') !== 'OpenNfse') {
            return $this->item('package_install', $label, self::FAIL, 'A instalação não veio do pacote do release. ' . $manual);
        }
        if ((string) ($info['version'] ?? '') !== $currentVersion) {
            return $this->item('package_install', $label, self::FAIL, sprintf(
                'O marcador do pacote é da versão %s, mas a instalada é %s. ' . $manual,
                (string) ($info['version'] ?? '?'),
                $currentVersion
            ));
        }

        return $this->item('package_install', $label, self::OK, 'Instalado com o pacote do release ' . $currentVersion . '.');
    }

    private function checkTempPath(string $tempPath): array
    {
        $label = 'Pasta temporária do WHMCS';
        if ($tempPath === '') {
            return $this->item('temp', $label, self::FAIL, 'Configure o Temporary Path em Utilities > Update WHMCS.');
        }
        if (!is_dir($tempPath)) {
            return $this->item('temp', $label, self::FAIL, sprintf('A pasta %s não existe.', $tempPath));
        }
        if (!$this->probeDir($tempPath)) {
            return $this->item('temp', $label, self::FAIL, sprintf('O PHP não consegue gravar em %s.', $tempPath));
        }

        return $this->item('temp', $label, self::OK, $tempPath);
    }

    private function checkTempLocation(string $tempPath, bool $outsideWebRoot): array
    {
        $label = 'Cópia do backup no servidor';
        if ($tempPath === '' || !is_dir($tempPath)) {
            return $this->item('temp_location', $label, self::WARN, 'Sem pasta temporária, o backup das tabelas não fica no servidor.');
        }
        if (!$outsideWebRoot) {
            return $this->item('temp_location', $label, self::WARN, 'A pasta temporária fica dentro do webroot, então o backup das tabelas não fica no servidor. Para voltar à versão anterior, será preciso enviar o arquivo baixado.');
        }

        return $this->item('temp_location', $label, self::OK, 'A pasta temporária fica fora do webroot. Uma cópia do backup das tabelas fica lá até você confirmar a atualização.');
    }

    private function checkAddonsDir(string $addonsDir): array
    {
        $label = 'Pasta modules/addons';
        if (!$this->probeDir($addonsDir, true)) {
            return $this->item('addons', $label, self::FAIL, 'O PHP não consegue criar, renomear e apagar pastas em modules/addons.');
        }

        return $this->item('addons', $label, self::OK, 'O PHP consegue criar, renomear e apagar pastas.');
    }

    /**
     * @return array{0: array, 1: int}
     */
    private function checkModuleTree(string $moduleDir): array
    {
        $label = 'Arquivos do módulo';
        $blocked = [];
        $bytes = 0;

        if (!is_dir($moduleDir) || !is_writable($moduleDir)) {
            $blocked[] = '.';
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($moduleDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $path => $info) {
                if ($info->isLink()) {
                    continue;
                }
                if ($info->isDir()) {
                    if (!$info->isWritable()) {
                        $blocked[] = substr((string) $path, strlen($moduleDir) + 1);
                    }
                } else {
                    $bytes += (int) $info->getSize();
                }
            }
        } catch (\Throwable $e) {
            return [$this->item('module', $label, self::FAIL, 'Não foi possível ler a pasta do módulo.'), 0];
        }

        if ($blocked !== []) {
            $detail = sprintf(
                '%d pasta(s) sem permissão de escrita para o PHP, por exemplo %s.',
                count($blocked),
                $blocked[0]
            );

            return [$this->item('module', $label, self::FAIL, $detail), $bytes];
        }

        return [$this->item('module', $label, self::OK, 'O PHP pode substituir e apagar os arquivos atuais.'), $bytes];
    }

    private function checkDiskSpace(string $tempPath, string $addonsDir, int $moduleBytes): array
    {
        $label = 'Espaço em disco';
        $needed = max(self::MIN_FREE_BYTES, $moduleBytes * 3);
        $problems = [];
        foreach (['pasta temporária' => $tempPath, 'modules/addons' => $addonsDir] as $name => $dir) {
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }
            $free = @disk_free_space($dir);
            if ($free !== false && $free < $needed) {
                $problems[] = sprintf('%s com %s livres', $name, self::formatBytes((int) $free));
            }
        }
        if ($problems !== []) {
            return $this->item('disk', $label, self::FAIL, sprintf('Precisa de %s livres: %s.', self::formatBytes($needed), implode('; ', $problems)));
        }

        return $this->item('disk', $label, self::OK, sprintf('Pelo menos %s livres.', self::formatBytes($needed)));
    }

    /**
     * Cria e apaga um arquivo (e, se pedido, uma pasta renomeada) para provar a escrita.
     */
    private function probeDir(string $dir, bool $withRename = false): bool
    {
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        $token = bin2hex(random_bytes(6));
        $file = $dir . '/.opennfse-probe-' . $token;
        if (@file_put_contents($file, 'ok') !== 2) {
            return false;
        }
        $ok = @file_get_contents($file) === 'ok';
        $ok = @unlink($file) && $ok;
        if (!$ok || !$withRename) {
            return $ok;
        }

        $probeDir = $dir . '/.opennfse-probe-dir-' . $token;
        $renamed = $probeDir . '-r';
        if (!@mkdir($probeDir, 0755)) {
            return false;
        }
        if (!@rename($probeDir, $renamed)) {
            @rmdir($probeDir);

            return false;
        }

        return @rmdir($renamed);
    }

    private function item(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }

    /**
     * "8.13.1-release.1" vira "8.13.1".
     */
    public static function numericVersion(string $version): string
    {
        return preg_match('/^\s*(\d+(?:\.\d+)*)/', $version, $m) === 1 ? $m[1] : '';
    }

    private static function formatBytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 0, ',', '.') . ' MB';
    }
}
