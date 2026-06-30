<?php

namespace OpenNfseVendor\Safe;

use OpenNfseVendor\Safe\Exceptions\MysqliException;
/**
 * @return array
 * @throws MysqliException
 *
 */
function mysqli_get_client_stats(): array
{
    error_clear_last();
    $safeResult = \mysqli_get_client_stats();
    if ($safeResult === \false) {
        throw MysqliException::createFromPhpError();
    }
    return $safeResult;
}
