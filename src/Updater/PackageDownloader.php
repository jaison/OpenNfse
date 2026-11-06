<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

/**
 * Baixa o zip do release para um arquivo. Só HTTPS, só hosts do GitHub, com limite de
 * tamanho; os redirecionamentos (github.com → servidor de arquivos) são seguidos um a um
 * e cada destino é conferido antes da próxima requisição.
 */
final class PackageDownloader
{
    public const ALLOWED_HOSTS = [
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];
    public const MAX_BYTES = 60 * 1024 * 1024;
    private const MAX_REDIRECTS = 5;
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 120;

    public function download(string $url, string $target): void
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('A extensão curl do PHP é necessária para baixar o pacote.');
        }

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!self::isAllowedUrl($url)) {
                throw new \RuntimeException('Endereço de download fora do GitHub: ' . self::safeHost($url));
            }
            [$code, $location] = $this->request($url, $target);
            if ($code >= 300 && $code < 400) {
                if ($location === '') {
                    throw new \RuntimeException('Redirecionamento sem destino ao baixar o pacote.');
                }
                $url = self::resolveLocation($url, $location);
                continue;
            }
            if ($code !== 200) {
                @unlink($target);
                throw new \RuntimeException('O GitHub respondeu HTTP ' . $code . ' ao baixar o pacote.');
            }

            return;
        }

        @unlink($target);
        throw new \RuntimeException('Redirecionamentos demais ao baixar o pacote.');
    }

    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower((string) ($parts['host'] ?? '')), self::ALLOWED_HOSTS, true);
    }

    public static function resolveLocation(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($current);
        $base = 'https://' . ($parts['host'] ?? '');
        if (strpos($location, '/') === 0) {
            return $base . $location;
        }
        $path = (string) ($parts['path'] ?? '/');

        return $base . rtrim(dirname($path), '/') . '/' . $location;
    }

    /**
     * @return array{0: int, 1: string} código HTTP e Location
     */
    private function request(string $url, string $target): array
    {
        $fh = @fopen($target, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Não foi possível gravar o pacote na pasta temporária.');
        }
        $location = '';
        $bytes = 0;
        $tooBig = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream', 'User-Agent: OpenNfse-Updater/1.0'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($fh, &$bytes, &$tooBig): int {
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_BYTES) {
                    $tooBig = true;

                    return 0;
                }

                return (int) fwrite($fh, $chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($tooBig) {
            @unlink($target);
            throw new \RuntimeException('O pacote passou do tamanho máximo permitido.');
        }
        if ($ok === false) {
            @unlink($target);
            throw new \RuntimeException('Falha ao baixar o pacote: ' . $error);
        }

        return [$code, $location];
    }

    private static function safeHost(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?? '?');
    }
}
