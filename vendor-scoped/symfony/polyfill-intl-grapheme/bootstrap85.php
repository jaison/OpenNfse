<?php

namespace OpenNfseVendor;

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
// The $locale argument added to grapheme_*() in PHP 8.5 / ICU 74 is accepted
// for native-signature compatibility but ignored by the polyfill, which only
// performs UTF-8 case folding.
use OpenNfseVendor\Symfony\Polyfill\Intl\Grapheme as p;
if (!\function_exists('grapheme_stripos') && !\function_exists('OpenNfseVendor\grapheme_stripos')) {
    function grapheme_stripos(?string $haystack, ?string $needle, ?int $offset = 0, ?string $locale = ''): int|false
    {
        return p\Grapheme::grapheme_stripos((string) $haystack, (string) $needle, (int) $offset);
    }
}
if (!\function_exists('grapheme_stristr') && !\function_exists('OpenNfseVendor\grapheme_stristr')) {
    function grapheme_stristr(?string $haystack, ?string $needle, ?bool $beforeNeedle = \false, ?string $locale = ''): string|false
    {
        return p\Grapheme::grapheme_stristr((string) $haystack, (string) $needle, (bool) $beforeNeedle);
    }
}
if (!\function_exists('grapheme_strlen') && !\function_exists('OpenNfseVendor\grapheme_strlen')) {
    function grapheme_strlen(?string $string): int|false|null
    {
        return p\Grapheme::grapheme_strlen((string) $string);
    }
}
if (!\function_exists('grapheme_strpos') && !\function_exists('OpenNfseVendor\grapheme_strpos')) {
    function grapheme_strpos(?string $haystack, ?string $needle, ?int $offset = 0, ?string $locale = ''): int|false
    {
        return p\Grapheme::grapheme_strpos((string) $haystack, (string) $needle, (int) $offset);
    }
}
if (!\function_exists('grapheme_strripos') && !\function_exists('OpenNfseVendor\grapheme_strripos')) {
    function grapheme_strripos(?string $haystack, ?string $needle, ?int $offset = 0, ?string $locale = ''): int|false
    {
        return p\Grapheme::grapheme_strripos((string) $haystack, (string) $needle, (int) $offset);
    }
}
if (!\function_exists('grapheme_strrpos') && !\function_exists('OpenNfseVendor\grapheme_strrpos')) {
    function grapheme_strrpos(?string $haystack, ?string $needle, ?int $offset = 0, ?string $locale = ''): int|false
    {
        return p\Grapheme::grapheme_strrpos((string) $haystack, (string) $needle, (int) $offset);
    }
}
if (!\function_exists('grapheme_strstr') && !\function_exists('OpenNfseVendor\grapheme_strstr')) {
    function grapheme_strstr(?string $haystack, ?string $needle, ?bool $beforeNeedle = \false, ?string $locale = ''): string|false
    {
        return p\Grapheme::grapheme_strstr((string) $haystack, (string) $needle, (bool) $beforeNeedle);
    }
}
if (!\function_exists('grapheme_substr') && !\function_exists('OpenNfseVendor\grapheme_substr')) {
    function grapheme_substr(?string $string, ?int $offset, ?int $length = null, ?string $locale = ''): string|false
    {
        return p\Grapheme::grapheme_substr((string) $string, (int) $offset, $length);
    }
}
