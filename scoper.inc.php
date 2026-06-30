<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

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
                'phpunit',
                'squizlabs',
                'humbug',
                'sebastian',
                'phar-io',
                'theseer',
                'myclabs',
                'fidry',
                'symfony/finder',
                'symfony/console',
                'symfony/filesystem',
            ])
            ->in('vendor'),
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
