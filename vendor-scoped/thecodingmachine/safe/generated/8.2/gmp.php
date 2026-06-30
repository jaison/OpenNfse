<?php

namespace OpenNfseVendor\Safe;

use OpenNfseVendor\Safe\Exceptions\GmpException;
/**
 * @param \GMP|int|string $seed
 *
 */
function gmp_random_seed($seed): void
{
    error_clear_last();
    $safeResult = \gmp_random_seed($seed);
}
