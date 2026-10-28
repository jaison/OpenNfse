<?php

declare(strict_types=1);

/**
 * Builds vendor-scoped/ from build/scoped/ (the output of `php-scoper add-prefix`).
 *
 * php-scoper rewrites namespaces inside vendor source files and inside each
 * package's composer.json "autoload" block, but it does not regenerate the
 * composer-generated autoload_*.php maps. This script reads the scoped
 * composer.json of every package and emits a self-contained autoload.php
 * that maps the prefixed namespaces to the scoped source directories.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$root = dirname(__DIR__);
$scopedDir = $root . '/build/scoped';
$outDir = $root . '/vendor-scoped';

if (!is_dir($scopedDir)) {
    fwrite(STDERR, "build/scoped not found. Run: vendor/bin/php-scoper add-prefix --output-dir=build/scoped --force\n");
    exit(1);
}

// Pacotes de produção (fora de "dev-package-names"); o scoper.inc.php usa a
// mesma regra. Se o build/scoped tiver qualquer outro pacote, ou faltar um,
// o build para antes de tocar no vendor-scoped/.
$installed = json_decode((string) @file_get_contents($root . '/vendor/composer/installed.json'), true);
if (!is_array($installed) || !isset($installed['packages'], $installed['dev-package-names'])) {
    fwrite(STDERR, "vendor/composer/installed.json não encontrado ou incompleto.\n");
    fwrite(STDERR, "Rode composer install antes do scope.\n");
    exit(1);
}
$devPackages = array_flip($installed['dev-package-names']);
$runtimePackages = [];
foreach ($installed['packages'] as $package) {
    if (!isset($devPackages[$package['name']])) {
        $runtimePackages[] = $package['name'];
    }
}
sort($runtimePackages);

$scopedPackages = [];
foreach (glob($scopedDir . '/*/*', GLOB_ONLYDIR) ?: [] as $pkgDir) {
    $scopedPackages[] = substr($pkgDir, strlen($scopedDir) + 1);
}
sort($scopedPackages);

if ($scopedPackages !== $runtimePackages) {
    fwrite(STDERR, "build/scoped não corresponde aos pacotes de produção.\n");
    fwrite(STDERR, "Sobrando: " . implode(', ', array_diff($scopedPackages, $runtimePackages)) . "\n");
    fwrite(STDERR, "Faltando: " . implode(', ', array_diff($runtimePackages, $scopedPackages)) . "\n");
    exit(1);
}

// Todo namespace declarado no resultado precisa ter o prefixo, exceto os que
// ficam globais de propósito (os mesmos de exclude-namespaces no scoper.inc.php):
// - Nfse\: SDK, usada pelo src/ com o nome completo;
// - NfsePdf\: gerador de PDF, cuja classe principal o src/PasetoOverrides/ redefine;
// - Psr\: interfaces PSR, compartilhadas com o host;
// - Spatie\: base dos DTOs da SDK.
// Um namespace de terceiro sem prefixo (como o GuzzleHttp\Psr7 que o
// php-scoper deixava passar) seria servido pela cópia do WHMCS ou de outro
// módulo, porque o nosso autoload é registrado por último.
$globalNamespaces = ['Nfse', 'NfsePdf', 'Psr', 'Spatie'];
$namespaceViolations = [];
$scopedFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scopedDir, FilesystemIterator::SKIP_DOTS));
foreach ($scopedFiles as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents((string) $file);
    if (!preg_match_all('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*[;{]/m', $source, $matches)) {
        continue;
    }
    foreach ($matches[1] as $namespace) {
        $namespaceRoot = explode('\\', $namespace)[0];
        if ($namespaceRoot !== 'OpenNfseVendor' && !in_array($namespaceRoot, $globalNamespaces, true)) {
            $namespaceViolations[] = substr((string) $file, strlen($scopedDir) + 1) . ': namespace ' . $namespace;
        }
    }
}
if ($namespaceViolations !== []) {
    fwrite(STDERR, "Namespaces sem o prefixo OpenNfseVendor\\ em build/scoped:\n");
    fwrite(STDERR, '  ' . implode("\n  ", array_slice($namespaceViolations, 0, 20)) . "\n");
    fwrite(STDERR, 'Total: ' . count($namespaceViolations) . ". Revise exclude-namespaces no scoper.inc.php.\n");
    exit(1);
}

function extractClassNames(string $source): array
{
    $classes = [];
    if (preg_match_all('/^\s*(?:class|interface|trait)\s+([A-Za-z0-9_]+)/m', $source, $matches)) {
        foreach ($matches[1] as $name) {
            $classes[] = $name;
        }
    }

    return $classes;
}

$psr4 = [];
$files = [];
$classmap = [];

$composerJsonFiles = glob($scopedDir . '/*/*/composer.json') ?: [];
foreach ($composerJsonFiles as $composerJsonFile) {
    $pkgDir = dirname($composerJsonFile);
    $data = json_decode((string) file_get_contents($composerJsonFile), true);
    $autoload = $data['autoload'] ?? [];

    foreach (($autoload['psr-4'] ?? []) as $prefix => $paths) {
        foreach ((array) $paths as $path) {
            $dir = $path === '' ? $pkgDir : $pkgDir . '/' . rtrim($path, '/');
            $psr4[$prefix][] = $dir;
        }
    }

    foreach (($autoload['files'] ?? []) as $file) {
        $files[] = $pkgDir . '/' . $file;
    }

    foreach (($autoload['classmap'] ?? []) as $path) {
        $target = $pkgDir . '/' . rtrim($path, '/');
        if (is_dir($target)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() === 'php') {
                    foreach (extractClassNames((string) file_get_contents((string) $file)) as $class) {
                        $classmap[$class] = (string) $file;
                    }
                }
            }
        } elseif (is_file($target)) {
            foreach (extractClassNames((string) file_get_contents($target)) as $class) {
                $classmap[$class] = $target;
            }
        }
    }
}

// Our own code isn't part of build/scoped (only vendor/ packages are
// scoped); add it explicitly so vendor-scoped/autoload.php is a complete
// drop-in replacement for vendor/autoload.php.
$psr4['OpenNfse\\'] = [$root . '/src'];
$psr4['NfsePdf\\'][] = $root . '/src/PasetoOverrides';

if (is_dir($outDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($outDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
    }
} else {
    mkdir($outDir, 0777, true);
}

// Move the scoped package directories into vendor-scoped/ as-is.
foreach (glob($scopedDir . '/*', GLOB_ONLYDIR) ?: [] as $vendorDir) {
    rename($vendorDir, $outDir . '/' . basename($vendorDir));
}

$export = static function (array $map, string $base) use ($root): string {
    $lines = [];
    foreach ($map as $prefix => $dirs) {
        $dirExports = [];
        foreach ($dirs as $dir) {
            if (str_starts_with($dir, $base . '/')) {
                $rel = substr($dir, strlen($base) + 1);
                $dirExports[] = "__DIR__ . '/" . $rel . "'";
            } elseif (str_starts_with($dir, $root . '/')) {
                $rel = substr($dir, strlen($root) + 1);
                $dirExports[] = "__DIR__ . '/../" . $rel . "'";
            } else {
                $dirExports[] = var_export($dir, true);
            }
        }
        $lines[] = '    ' . var_export($prefix, true) . ' => [' . implode(', ', $dirExports) . '],';
    }

    return implode("\n", $lines);
};

$psr4Code = $export($psr4, $scopedDir);

$classmapLines = [];
foreach ($classmap as $class => $path) {
    if (str_starts_with($path, $scopedDir . '/')) {
        $rel = substr($path, strlen($scopedDir) + 1);
        $pathExport = "__DIR__ . '/" . $rel . "'";
    } elseif (str_starts_with($path, $root . '/')) {
        $rel = substr($path, strlen($root) + 1);
        $pathExport = "__DIR__ . '/../" . $rel . "'";
    } else {
        $pathExport = var_export($path, true);
    }
    $classmapLines[] = '    ' . var_export($class, true) . ' => ' . $pathExport . ',';
}
$classmapCode = implode("\n", $classmapLines);

$filesCode = '';
foreach ($files as $file) {
    $rel = str_replace($scopedDir . '/', '', $file);
    $filesCode .= "require_once __DIR__ . '/" . $rel . "';\n";
}

$autoloadPhp = <<<PHP
<?php

declare(strict_types=1);

// Generated by tools/build-scoped-vendor.php — do not edit by hand.

require_once __DIR__ . '/composer-classloader/ClassLoader.php';

\$loader = new \\OpenNfseScopedClassLoader();

\$psr4 = [
{$psr4Code}
];

foreach (\$psr4 as \$prefix => \$dirs) {
    \$loader->setPsr4(\$prefix, \$dirs);
}

\$classmap = [
{$classmapCode}
];

\$loader->addClassMap(\$classmap);

// Register as a fallback (append), not prepend: our Psr\* interfaces must
// never shadow the ones other WHMCS modules bundle, or their own
// (unprefixed) Guzzle/PSR-7 classes can become incompatible at runtime.
\$loader->register(false);

{$filesCode}
return \$loader;

PHP;

file_put_contents($outDir . '/autoload.php', $autoloadPhp);

// Bundle a copy of composer's ClassLoader under a private class name so it
// never collides with the host application's own composer ClassLoader.
$classLoaderSrc = $root . '/vendor/composer/ClassLoader.php';
$classLoaderCode = (string) file_get_contents($classLoaderSrc);
$classLoaderCode = preg_replace(
    '/class\s+ClassLoader/',
    'class OpenNfseScopedClassLoader',
    $classLoaderCode,
    1
);
$classLoaderCode = preg_replace('/^namespace\s+Composer\\\\Autoload;\s*$/m', '', (string) $classLoaderCode, 1);

mkdir($outDir . '/composer-classloader', 0777, true);
file_put_contents($outDir . '/composer-classloader/ClassLoader.php', (string) $classLoaderCode);

// Os composer.json/composer.lock dos pacotes só servem para gerar o autoload
// acima (lidos em build/scoped). No resultado final eles saem: não são usados
// em runtime e fazem o Dependabot tratar o vendor-scoped/ como manifests
// (com os require-dev de cada pacote).
$manifests = array_merge(
    glob($outDir . '/*/*/composer.json') ?: [],
    glob($outDir . '/*/*/composer.lock') ?: []
);
foreach ($manifests as $manifest) {
    unlink($manifest);
}

fwrite(STDOUT, "vendor-scoped/ generated with " . count($psr4) . " psr-4 prefixes and " . count($files) . " files autoloads.\n");
