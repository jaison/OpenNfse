<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

final class NfseXmlExtractor
{
    public static function extractNumeroNfse(string $xml): ?string
    {
        return self::withDocument($xml, static function (\DOMXPath $xp): ?string {
            $nodes = $xp->query('//*[local-name()="nNFSe"]');
            if ($nodes && $nodes->length > 0) {
                $value = trim((string) $nodes->item(0)?->textContent);
                if ($value !== '') {
                    return $value;
                }
            }

            $nodes = $xp->query('//*[local-name()="nNFSeMun"]');
            if ($nodes && $nodes->length > 0) {
                $value = trim((string) $nodes->item(0)?->textContent);
                if ($value !== '') {
                    return $value;
                }
            }

            return null;
        });
    }

    public static function extractCompetencia(string $xml): ?string
    {
        return self::withDocument($xml, static function (\DOMXPath $xp): ?string {
            $nodes = $xp->query('//*[local-name()="dCompet"]');
            if (!$nodes || $nodes->length <= 0) {
                return null;
            }

            $value = trim((string) $nodes->item(0)?->textContent);
            if ($value === '') {
                return null;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return $value;
            }

            if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
                return $value . '-01';
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1) {
                $date = substr($value, 0, 10);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                    return $date;
                }
            }

            return null;
        });
    }

    public static function extractEmitidaEm(string $xml): ?string
    {
        return self::withDocument($xml, static function (\DOMXPath $xp): ?string {
            $nodes = $xp->query('//*[local-name()="dhEmi"]');
            if ($nodes && $nodes->length > 0) {
                $raw = trim((string) $nodes->item(0)?->textContent);
                if ($raw !== '') {
                    try {
                        $dt = new \DateTimeImmutable($raw);
                        return $dt->format('Y-m-d H:i:s');
                    } catch (\Throwable $e) {
                    }
                }
            }

            $nodes = $xp->query('//*[local-name()="dEmi"]');
            if ($nodes && $nodes->length > 0) {
                $raw = trim((string) $nodes->item(0)?->textContent);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
                    return $raw . ' 00:00:00';
                }
            }

            return null;
        });
    }

    /**
     * @param callable(\DOMXPath): ?string $callback
     */
    private static function withDocument(string $xml, callable $callback): ?string
    {
        $xml = trim($xml);
        if ($xml === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $ok = $doc->loadXML($xml, LIBXML_NONET);
            if (!$ok) {
                return null;
            }

            return $callback(new \DOMXPath($doc));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }
}
