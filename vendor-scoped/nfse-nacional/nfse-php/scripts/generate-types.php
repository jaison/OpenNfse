<?php

namespace OpenNfseVendor;

require_once __DIR__ . '/../vendor/autoload.php';
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
$config = require __DIR__ . '/../typescript-transformer.php';
$transformerConfig = TypeScriptTransformerConfig::create()->autoDiscoverTypes(...$config['searching_paths'])->collectors($config['collectors'])->transformers($config['transformers'])->outputFile($config['output_file']);
$transformer = new TypeScriptTransformer($transformerConfig);
$transformer->transform();
echo 'TypeScript types generated successfully in ' . $config['output_file'] . \PHP_EOL;
