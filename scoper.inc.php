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

    // Sempre regex ancorada (namespace exato ou sub-namespace). Um nome simples
    // aqui é comparado pelo php-scoper como SUBSTRING (str_contains, sem
    // diferenciar maiúsculas): 'Psr' também excluía GuzzleHttp\Psr7, que ficava
    // sem prefixo e era servido pela psr7 do WHMCS ou de outro módulo.
    // Ao mudar esta lista, ajuste também $globalNamespaces em
    // tools/build-scoped-vendor.php, que confere os namespaces do resultado.
    'exclude-namespaces' => [
        // Código do próprio módulo e do WHMCS (não estão no vendor-scoped, mas
        // podem ser referenciados por ele).
        '~^OpenNfse(\\\\|$)~',
        '~^WHMCS(\\\\|$)~',
        // SDK nfse-php: o src/ usa as classes pelo nome completo (\Nfse\...).
        '~^Nfse(\\\\|$)~',
        // Gerador de PDF: o src/PasetoOverrides/ redefine NfsePdf\NfsePdfGenerator.
        '~^NfsePdf(\\\\|$)~',
        // Interfaces PSR (http-message, http-client, http-factory): contratos
        // estáveis entre versões, compartilhados com o host como era antes.
        '~^Psr(\\\\|$)~',
        // Base dos DTOs da SDK (Spatie\DataTransferObject).
        '~^Spatie(\\\\|$)~',
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
