<?php

namespace OpenNfseVendor;

return [
    /*
     * The paths where typescript-transformer will look for PHP classes
     * to transform, this will be the `src` directory.
     */
    'searching_paths' => [__DIR__ . '/src/Dto'],
    /*
     * The collectors that will look for classes to transform.
     */
    'collectors' => [\Spatie\TypeScriptTransformer\Collectors\DefaultCollector::class, \Spatie\TypeScriptTransformer\Collectors\EnumCollector::class],
    /*
     * The transformers that will transform the classes.
     */
    'transformers' => [\Spatie\TypeScriptTransformer\Transformers\DtoTransformer::class, \Spatie\TypeScriptTransformer\Transformers\SpatieEnumTransformer::class],
    /*
     * The path where the generated TypeScript types will be written.
     */
    'output_file' => __DIR__ . '/types/generated.d.ts',
    /*
     * This formatter will be used to format the generated TypeScript types.
     */
    'formatter' => \Spatie\TypeScriptTransformer\Formatters\PrettierFormatter::class,
];
