<?php

namespace OpenNfseVendor\Safe;

use OpenNfseVendor\Safe\Exceptions\RpminfoException;
/**
 * @param int $tag
 * @throws RpminfoException
 *
 */
function rpmaddtag(int $tag): void
{
    error_clear_last();
    $safeResult = \rpmaddtag($tag);
    if ($safeResult === \false) {
        throw RpminfoException::createFromPhpError();
    }
}
