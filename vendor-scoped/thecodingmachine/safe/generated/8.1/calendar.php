<?php

namespace OpenNfseVendor\Safe;

use OpenNfseVendor\Safe\Exceptions\CalendarException;
/**
 * @param int|null $timestamp
 * @return int
 * @throws CalendarException
 *
 */
function unixtojd(?int $timestamp = null): int
{
    error_clear_last();
    if ($timestamp !== null) {
        $safeResult = \unixtojd($timestamp);
    } else {
        $safeResult = \unixtojd();
    }
    if ($safeResult === \false) {
        throw CalendarException::createFromPhpError();
    }
    return $safeResult;
}
