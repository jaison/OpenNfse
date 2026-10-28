<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

// Só os pacotes de produção entram no vendor-scoped/. A lista vem do
// vendor/composer/installed.json: tudo que não está em "dev-package-names".
// Não dá para usar um `composer install --no-dev` temporário porque o plugin
// que aplica patches/ na SDK (cweagans/composer-patches) é de desenvolvimento.
$vendorDir = __DIR__ . '/vendor';
$installed = json_decode((string) @file_get_contents($vendorDir . '/composer/installed.json'), true);
if (!is_array($installed) || !isset($installed['packages'], $installed['dev-package-names'])) {
    throw new RuntimeException(
        'vendor/composer/installed.json não encontrado ou incompleto. Rode composer install antes do scope.'
    );
}

$devPackages = array_flip($installed['dev-package-names']);
$runtimeDirs = [];
foreach ($installed['packages'] as $package) {
    if (!isset($devPackages[$package['name']])) {
        $runtimeDirs[] = $vendorDir . '/' . $package['name'];
    }
}
if ($runtimeDirs === []) {
    throw new RuntimeException('Nenhum pacote de produção encontrado em vendor/composer/installed.json.');
}

// Arquivos da SDK que não são usados em runtime: XSD/PDFs/XMLs de referência
// (com dados pessoais de exemplo), exemplos, scripts, tipos TypeScript, testes
// e scripts/configs de desenvolvimento da raiz do pacote.
$sdkDir = $vendorDir . '/nfse-nacional/nfse-php/';
$sdkExcluded = [
    'references/',
    'examples/',
    'scripts/',
    'types/',
    'tests/',
    'debug.php',
    'reproduce_parsing.php',
    'typescript-transformer.php',
    'codecov.yml',
    'phpstan.neon',
    'phpstan-baseline.neon',
];

return [
    'prefix' => 'OpenNfseVendor',

    'finders' => [
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/.*\.md$/')
            ->notName('/.*\.dist$/')
            ->exclude([
                'bin',
                'test',
                'tests',
                'Tests',
                'docs',
            ])
            ->filter(static function (\SplFileInfo $file) use ($sdkDir, $sdkExcluded): bool {
                $path = str_replace('\\', '/', $file->getPathname());
                if (!str_starts_with($path, $sdkDir)) {
                    return true;
                }
                $relative = substr($path, strlen($sdkDir));
                foreach ($sdkExcluded as $excluded) {
                    $isDir = str_ends_with($excluded, '/');
                    if ($relative === $excluded || ($isDir && str_starts_with($relative, $excluded))) {
                        return false;
                    }
                }

                return true;
            })
            ->in($runtimeDirs),
    ],

    'exclude-namespaces' => [
        // Keep our own code and WHMCS runtime untouched.
        'OpenNfse',
        'NfsePdf',
        'WHMCS',
        // The nfse-php SDK is referenced by FQCN throughout src/ — keep it
        // global so those references keep working without a mass rewrite.
        'Nfse',
        // PSR interfaces must stay global so Guzzle's prefixed classes still
        // satisfy the (unprefixed) type-hints used by the excluded SDKs.
        'Psr',
        // The nfse-php SDK extends Spatie\DataTransferObject\DataTransferObject
        // directly; keep it global so those references keep resolving.
        'Spatie',
    ],

    'exclude-classes' => [
        // PHP/SPL/ext classes are excluded automatically by php-scoper.
        // TCPDF and friends live in the global namespace and are referenced
        // by FQCN (e.g. \TCPDF) from src/ — keep them unprefixed.
        'TCPDF',
        'TCPDF_FONTS',
        'TCPDF_IMAGES',
        'TCPDF_STATIC',
        'TCPDF_COLORS',
        'TCPDF_FONT_DATA',
        'TCPDF2DBarcode',
        'TCPDFBarcode',
        'TCPDFFontDescriptor',
    ],

    'expose-global-constants' => true,
    'expose-global-classes' => false,
    'expose-global-functions' => true,
];
