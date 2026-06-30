<?php

namespace OpenNfseVendor;

use OpenNfseVendor\JetBrains\PhpStorm\Pure;
/**
 * @since 8.1
 */
class ReflectionIntersectionType extends \ReflectionType
{
    /** @return ReflectionType[] */
    #[Pure]
    public function getTypes(): array
    {
    }
}
/**
 * @since 8.1
 */
\class_alias('OpenNfseVendor\ReflectionIntersectionType', 'ReflectionIntersectionType', \false);
