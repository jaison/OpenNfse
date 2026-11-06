<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

/**
 * Etapa 4: confere e extrai o pacote, monta a pasta nova ao lado do módulo e troca as pastas.
 *
 * Tudo antes de swap() acontece fora da pasta do módulo. A troca são dois rename() no
 * mesmo disco: OpenNfse → OpenNfse.old e OpenNfse.new → OpenNfse. A pasta antiga fica
 * guardada até a confirmação, para a volta ao estado anterior.
 */
final class PackageInstaller
{
    public const OLD_SUFFIX = '.old';
    public const NEW_SUFFIX = '.new';
    private const ROOT = 'OpenNfse/';
    private const MAX_ENTRIES = 5000;
    private const MAX_UNPACKED_BYTES = 300 * 1024 * 1024;
    private const REQUIRED_FILES = ['OpenNfse.php', 'hooks.php', 'bootstrap.php', 'vendor-scoped/autoload.php', PreflightCheck::BUILD_INFO_FILE];

    /**
     * Confere o SHA-256, extrai em $workDir e valida o conteúdo.
     *
     * @return string pasta OpenNfse extraída
     */
    public function prepare(string $zipPath, string $sha256, string $toVersion, string $workDir): string
    {
        if (!hash_equals(strtolower($sha256), (string) hash_file('sha256', $zipPath))) {
            throw new \RuntimeException('O SHA-256 do pacote baixado não confere com o publicado.');
        }

        $target = rtrim($workDir, '/') . '/opennfse-extract-' . bin2hex(random_bytes(4));
        if (!@mkdir($target, 0755)) {
            throw new \RuntimeException('Não foi possível criar a pasta de extração.');
        }
        try {
            $this->extract($zipPath, $target);
            $moduleDir = $target . '/OpenNfse';
            $this->validate($moduleDir, $toVersion);
        } catch (\Throwable $e) {
            self::removeTree($target);
            throw $e;
        }

        return $moduleDir;
    }

    /**
     * Monta modules/addons/OpenNfse.new a partir da pasta extraída (rename, ou cópia se for outro disco).
     */
    public function stage(string $extractedDir, string $moduleDir): string
    {
        $newDir = $moduleDir . self::NEW_SUFFIX;
        self::removeTree($newDir);
        self::removeTree($moduleDir . self::OLD_SUFFIX);

        if (!@rename($extractedDir, $newDir)) {
            self::copyTree($extractedDir, $newDir);
        }
        if (!is_file($newDir . '/OpenNfse.php')) {
            throw new \RuntimeException('A pasta nova não ficou completa ao lado do módulo.');
        }

        return $newDir;
    }

    /**
     * Troca as pastas. Se a segunda troca falhar, desfaz a primeira.
     */
    public function swap(string $moduleDir): void
    {
        $oldDir = $moduleDir . self::OLD_SUFFIX;
        $newDir = $moduleDir . self::NEW_SUFFIX;
        if (!is_dir($newDir)) {
            throw new \RuntimeException('A pasta nova não está pronta.');
        }
        if (!@rename($moduleDir, $oldDir)) {
            throw new \RuntimeException('Não foi possível renomear a pasta atual do módulo.');
        }
        if (!@rename($newDir, $moduleDir)) {
            @rename($oldDir, $moduleDir);
            throw new \RuntimeException('Não foi possível pôr a pasta nova no lugar. A versão anterior foi mantida.');
        }
        self::resetOpcache();
    }

    /**
     * Volta a pasta anterior para o lugar. A pasta que falhou é apagada.
     */
    public function rollbackFiles(string $moduleDir): void
    {
        $oldDir = $moduleDir . self::OLD_SUFFIX;
        if (!is_dir($oldDir)) {
            throw new \RuntimeException('A pasta da versão anterior não existe mais.');
        }
        $failedDir = $moduleDir . '.failed-' . date('YmdHis');
        if (is_dir($moduleDir) && !@rename($moduleDir, $failedDir)) {
            throw new \RuntimeException('Não foi possível tirar a versão nova do lugar.');
        }
        if (!@rename($oldDir, $moduleDir)) {
            @rename($failedDir, $moduleDir);
            throw new \RuntimeException('Não foi possível pôr a versão anterior no lugar.');
        }
        self::removeTree($failedDir);
        self::resetOpcache();
    }

    /**
     * Depois da confirmação: apaga a versão anterior.
     */
    public function discardOld(string $moduleDir): void
    {
        self::removeTree($moduleDir . self::OLD_SUFFIX);
    }

    private function extract(string $zipPath, string $target): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('A extensão zip do PHP é necessária.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('O pacote baixado não é um zip válido.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new \RuntimeException('O pacote tem uma quantidade de arquivos fora do esperado.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                self::assertSafeEntry($name);
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX
                    && (($attr >> 16) & 0170000) === 0120000) {
                    throw new \RuntimeException('O pacote contém link simbólico: ' . $name);
                }
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_UNPACKED_BYTES) {
                    throw new \RuntimeException('O pacote extraído passa do tamanho máximo.');
                }
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $dest = $target . '/' . $name;
                if (substr($name, -1) === '/') {
                    if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
                        throw new \RuntimeException('Não foi possível criar a pasta ' . $name);
                    }
                    continue;
                }
                $dir = dirname($dest);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                    throw new \RuntimeException('Não foi possível criar a pasta de ' . $name);
                }
                $in = $zip->getStream($name);
                $out = @fopen($dest, 'wb');
                if ($in === false || $out === false) {
                    throw new \RuntimeException('Não foi possível extrair ' . $name);
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                @chmod($dest, 0644);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Só caminhos relativos dentro de OpenNfse/, sem "..", barra invertida ou byte nulo.
     */
    public static function assertSafeEntry(string $name): void
    {
        $unsafe = $name === ''
            || strpos($name, self::ROOT) !== 0
            || strpbrk($name, "\\\0:") !== false
            || preg_match('#(^|/)\.\.?(/|$)#', $name) === 1
            || strpos($name, '//') !== false;
        if ($unsafe) {
            throw new \RuntimeException('O pacote contém um caminho inválido: ' . substr($name, 0, 200));
        }
    }

    private function validate(string $moduleDir, string $toVersion): void
    {
        foreach (self::REQUIRED_FILES as $file) {
            if (!is_file($moduleDir . '/' . $file)) {
                throw new \RuntimeException('O pacote não tem o arquivo ' . $file . '.');
            }
        }
        $declared = self::declaredVersion($moduleDir);
        if ($declared !== $toVersion) {
            throw new \RuntimeException(sprintf('O pacote declara a versão %s, mas a anunciada é %s.', $declared ?? '?', $toVersion));
        }
        $info = json_decode((string) file_get_contents($moduleDir . '/' . PreflightCheck::BUILD_INFO_FILE), true);
        if (!is_array($info) || ($info['module'] ?? '') !== 'OpenNfse' || (string) ($info['version'] ?? '') !== $toVersion) {
            throw new \RuntimeException('O marcador build-info.json do pacote não confere com a versão anunciada.');
        }
        $errors = self::lintModuleCode($moduleDir);
        if ($errors !== []) {
            throw new \RuntimeException('O pacote tem erro de sintaxe PHP: ' . implode('; ', array_slice($errors, 0, 3)));
        }
    }

    /**
     * Versão declarada em OpenNfse.php (mesma leitura do AddonVersion).
     */
    public static function declaredVersion(string $moduleDir): ?string
    {
        $contents = @file_get_contents($moduleDir . '/OpenNfse.php');
        if (is_string($contents) && preg_match("/'version'\\s*=>\\s*'([^']+)'/", $contents, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Sintaxe do código do próprio módulo (raiz e src/), sem executar nada. O vendor vem do
     * mesmo build conferido pelo SHA-256 e tem arquivos grandes demais para tokenizar.
     *
     * @return list<string>
     */
    public static function lintModuleCode(string $moduleDir): array
    {
        $files = glob($moduleDir . '/*.php') ?: [];
        if (is_dir($moduleDir . '/src')) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($moduleDir . '/src', \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (substr((string) $file, -4) === '.php') {
                    $files[] = (string) $file;
                }
            }
        }
        $errors = [];
        foreach ($files as $file) {
            try {
                token_get_all((string) file_get_contents($file), TOKEN_PARSE);
            } catch (\ParseError $e) {
                $errors[] = substr($file, strlen($moduleDir) + 1) . ':' . $e->getLine() . ' ' . $e->getMessage();
            }
        }

        return $errors;
    }

    public static function removeTree(string $dir): void
    {
        if ($dir === '' || $dir === '/' || !file_exists($dir)) {
            return;
        }
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);

            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path => $info) {
            if ($info->isDir() && !$info->isLink()) {
                @rmdir((string) $path);
            } else {
                @unlink((string) $path);
            }
        }
        @rmdir($dir);
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!@mkdir($to, 0755, true) && !is_dir($to)) {
            throw new \RuntimeException('Não foi possível criar a pasta nova ao lado do módulo.');
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $path => $info) {
            $dest = $to . '/' . substr((string) $path, strlen($from) + 1);
            if ($info->isDir()) {
                if (!is_dir($dest) && !@mkdir($dest, 0755)) {
                    throw new \RuntimeException('Não foi possível copiar a pasta ' . $dest);
                }
            } elseif (!@copy((string) $path, $dest)) {
                throw new \RuntimeException('Não foi possível copiar o arquivo ' . $dest);
            }
        }
        self::removeTree($from);
    }

    private static function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        clearstatcache(true);
    }
}
