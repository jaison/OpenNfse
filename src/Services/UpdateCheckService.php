<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Helpers\AddonVersion;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Updater\PackageDownloader;

final class UpdateCheckService
{
    private const MANIFEST_URL = 'https://raw.githubusercontent.com/jaison/OpenNfse/main/update.json';
    /** Pacotes só são aceitos deste endereço: .../download/v{versão}/OpenNfse-{versão}.zip */
    public const PACKAGE_URL_PREFIX = 'https://github.com/jaison/OpenNfse/releases/download/';
    private const ALLOWED_HOSTS = ['raw.githubusercontent.com'];
    /** Canal de testes: lê os releases do GitHub, inclusive pré-releases, em vez do update.json. */
    private const RELEASES_URL = 'https://api.github.com/repos/jaison/OpenNfse/releases?per_page=15';
    private const RELEASES_HOSTS = ['api.github.com'];
    private const MAX_RELEASES_BYTES = 1048576;
    public const CHANNEL_STABLE = 'stable';
    public const CHANNEL_BETA = 'beta';
    public const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$/';
    private const CACHE_TTL_SECONDS = 86400;
    private const REQUEST_TIMEOUT_SECONDS = 5;
    private const MAX_MANIFEST_BYTES = 65536;

    public function getStatus(array $config = []): array
    {
        $currentVersion = $this->getCurrentVersion();
        $latestVersion = trim((string) ($config['update_latest_version'] ?? ''));
        $status = trim((string) ($config['update_last_status'] ?? ''));
        $lastCheckedAt = trim((string) ($config['update_last_checked_at'] ?? ''));
        $downloadUrl = trim((string) ($config['update_download_url'] ?? ''));
        $changelogUrl = trim((string) ($config['update_changelog_url'] ?? ''));
        $message = trim((string) ($config['update_message'] ?? ''));
        $error = trim((string) ($config['update_error'] ?? ''));
        $minimumWhmcs = trim((string) ($config['update_minimum_whmcs'] ?? ''));
        $minimumPhp = trim((string) ($config['update_minimum_php'] ?? ''));
        $packageUrl = trim((string) ($config['update_package_url'] ?? ''));
        $packageSha256 = trim((string) ($config['update_package_sha256'] ?? ''));
        $updateAvailable = $latestVersion !== '' && version_compare($latestVersion, $currentVersion, '>');

        if ($status === '') {
            $status = 'never_checked';
        }

        $label = 'Aguardando checagem';
        $summary = 'Ainda não houve verificação remota de atualização.';

        if ($status === 'ok') {
            if ($updateAvailable) {
                $label = 'Atualização disponível';
                $summary = sprintf(
                    'Há uma nova versão disponível para o módulo. Atualize da versão %s para %s.',
                    $currentVersion !== '' ? $currentVersion : 'atual',
                    $latestVersion !== '' ? $latestVersion : 'mais recente'
                );
            } else {
                $label = 'Atualizado';
                $summary = $message !== '' ? $message : 'A instalação local já está na versão mais recente conhecida.';
            }
        } elseif ($status === 'error') {
            $label = 'Falha na checagem';
            $summary = $error !== '' ? $error : 'Não foi possível consultar o manifesto remoto de atualização.';
        }

        return [
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion !== '' ? $latestVersion : $currentVersion,
            'last_checked_at' => $lastCheckedAt,
            'download_url' => $downloadUrl !== '' ? $downloadUrl : 'https://github.com/jaison/OpenNfse/releases',
            'changelog_url' => $changelogUrl !== '' ? $changelogUrl : 'https://github.com/jaison/OpenNfse/releases',
            'minimum_whmcs' => $minimumWhmcs,
            'minimum_php' => $minimumPhp,
            'package_url' => $packageUrl,
            'package_sha256' => $packageSha256,
            'message' => $message,
            'error' => $error,
            'status' => $status,
            'label' => $label,
            'summary' => $summary,
            'update_available' => $updateAvailable,
            'is_stale' => $this->isCheckDue($config),
            'manifest_url' => self::channel($config) === self::CHANNEL_BETA ? self::RELEASES_URL : self::MANIFEST_URL,
            'channel' => self::channel($config),
        ];
    }

    public function checkNow(): array
    {
        $channel = self::channel((new ConfigRepository())->get());
        $manifest = $channel === self::CHANNEL_BETA ? $this->fetchFromReleases() : $this->fetchManifest();
        $payload = $this->buildConfigPayloadFromManifest($manifest);
        (new ConfigRepository())->save($payload);

        return $this->getStatus(array_merge($payload, [
            'update_last_checked_at' => $payload['update_last_checked_at'] ?? date('Y-m-d H:i:s'),
        ]));
    }

    public function autoCheckIfDue(array $config = []): void
    {
        if (!$this->isCheckDue($config)) {
            return;
        }

        try {
            $this->checkNow();
        } catch (\Throwable $e) {
            (new ConfigRepository())->save([
                'update_last_checked_at' => date('Y-m-d H:i:s'),
                'update_last_status' => 'error',
                'update_error' => $e->getMessage(),
            ]);
        }
    }

    public function isCheckDue(array $config = []): bool
    {
        $lastCheckedAt = trim((string) ($config['update_last_checked_at'] ?? ''));
        if ($lastCheckedAt === '') {
            return true;
        }

        $timestamp = strtotime($lastCheckedAt);
        if ($timestamp === false) {
            return true;
        }

        return (time() - $timestamp) >= self::CACHE_TTL_SECONDS;
    }

    public function getCurrentVersion(): string
    {
        return AddonVersion::current() ?? '0.0.0';
    }

    private function fetchManifest(): array
    {
        $body = $this->httpGet(self::MANIFEST_URL, self::REQUEST_TIMEOUT_SECONDS, self::ALLOWED_HOSTS, self::MAX_MANIFEST_BYTES);
        if ($body === null) {
            throw new \RuntimeException('Não foi possível consultar o manifesto remoto de atualização.');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('O manifesto remoto de atualização retornou JSON inválido.');
        }

        $module = trim((string) ($data['module'] ?? ''));
        $latestVersion = trim((string) ($data['latest_version'] ?? ''));
        if ($module !== 'OpenNfse' || $latestVersion === '' || !preg_match(self::VERSION_PATTERN, $latestVersion)) {
            throw new \RuntimeException('O manifesto remoto de atualização está incompleto ou inválido.');
        }

        $downloadUrl = trim((string) ($data['download_url'] ?? ''));
        $changelogUrl = trim((string) ($data['changelog_url'] ?? ''));
        if ($downloadUrl !== '' && !$this->isAllowedPublicUrl($downloadUrl)) {
            throw new \RuntimeException('O manifesto remoto retornou uma URL de download inválida.');
        }
        if ($changelogUrl !== '' && !$this->isAllowedPublicUrl($changelogUrl)) {
            throw new \RuntimeException('O manifesto remoto retornou uma URL de changelog inválida.');
        }

        [$packageUrl, $packageSha256] = $this->parsePackage($data, $latestVersion);

        return [
            'latest_version' => $latestVersion,
            'package_url' => $packageUrl,
            'package_sha256' => $packageSha256,
            'message' => trim((string) ($data['message'] ?? '')),
            'download_url' => $downloadUrl,
            'changelog_url' => $changelogUrl,
            'minimum_whmcs' => trim((string) ($data['minimum_whmcs'] ?? '')),
            'minimum_php' => trim((string) ($data['minimum_php'] ?? '')),
        ];
    }

    public static function channel(array $config): string
    {
        return ($config['update_channel'] ?? '') === self::CHANNEL_BETA ? self::CHANNEL_BETA : self::CHANNEL_STABLE;
    }

    /**
     * Canal de testes: a maior versão entre os releases publicados (pré-releases incluídos)
     * que tenham o zip e o SHA-256 anexados pelo workflow.
     *
     * @param list<array<string, mixed>> $releases resposta da API de releases do GitHub
     * @return array{version: string, zip_url: string, sha_url: string, html_url: string, name: string}|null
     */
    public static function pickRelease(array $releases): ?array
    {
        $best = null;
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft'])) {
                continue;
            }
            $tag = (string) ($release['tag_name'] ?? '');
            $version = strpos($tag, 'v') === 0 ? substr($tag, 1) : '';
            if ($version === '' || preg_match(self::VERSION_PATTERN, $version) !== 1) {
                continue;
            }
            $zipName = 'OpenNfse-' . $version . '.zip';
            $zipUrl = '';
            $shaUrl = '';
            foreach ((array) ($release['assets'] ?? []) as $asset) {
                $name = (string) ($asset['name'] ?? '');
                if ($name === $zipName) {
                    $zipUrl = (string) ($asset['browser_download_url'] ?? '');
                } elseif ($name === $zipName . '.sha256') {
                    $shaUrl = (string) ($asset['browser_download_url'] ?? '');
                }
            }
            if ($zipUrl === '' || $shaUrl === '') {
                continue;
            }
            if ($best === null || version_compare($version, $best['version'], '>')) {
                $best = [
                    'version' => $version,
                    'zip_url' => $zipUrl,
                    'sha_url' => $shaUrl,
                    'html_url' => (string) ($release['html_url'] ?? ''),
                    'name' => trim((string) ($release['name'] ?? '')),
                ];
            }
        }

        return $best;
    }

    private function fetchFromReleases(): array
    {
        $body = $this->httpGet(self::RELEASES_URL, self::REQUEST_TIMEOUT_SECONDS, self::RELEASES_HOSTS, self::MAX_RELEASES_BYTES);
        $releases = $body !== null ? json_decode($body, true) : null;
        if (!is_array($releases)) {
            throw new \RuntimeException('Não foi possível consultar os releases do GitHub.');
        }
        $release = self::pickRelease($releases);
        if ($release === null) {
            throw new \RuntimeException('Nenhum release com pacote de atualização foi encontrado no GitHub.');
        }

        $tmp = (string) tempnam(sys_get_temp_dir(), 'opennfse-sha');
        try {
            (new PackageDownloader())->download($release['sha_url'], $tmp);
            $line = (string) file_get_contents($tmp, false, null, 0, 1024);
        } finally {
            @unlink($tmp);
        }
        if (preg_match('/^([0-9a-fA-F]{64})\s+\*?(\S+)/', trim($line), $m) !== 1 || $m[2] !== 'OpenNfse-' . $release['version'] . '.zip') {
            throw new \RuntimeException('O arquivo SHA-256 do release está em formato inesperado.');
        }
        [$packageUrl, $packageSha256] = self::parsePackage(
            ['package_url' => $release['zip_url'], 'package_sha256' => $m[1]],
            $release['version']
        );
        $page = $release['html_url'] !== '' && $this->isAllowedPublicUrl($release['html_url']) ? $release['html_url'] : '';

        return [
            'latest_version' => $release['version'],
            'package_url' => $packageUrl,
            'package_sha256' => $packageSha256,
            'message' => $release['name'] !== '' ? 'Canal de testes: ' . $release['name'] : 'Canal de testes.',
            'download_url' => $page,
            'changelog_url' => $page,
            'minimum_whmcs' => '',
            'minimum_php' => '',
        ];
    }

    /**
     * Pacote para a atualização embutida. Opcional: sem ele, a versão só pode ser instalada à mão.
     * A URL precisa ser exatamente a do release da versão anunciada.
     *
     * @return array{0: string, 1: string}
     */
    public static function parsePackage(array $data, string $latestVersion): array
    {
        $url = trim((string) ($data['package_url'] ?? ''));
        $sha = strtolower(trim((string) ($data['package_sha256'] ?? '')));
        if ($url === '' && $sha === '') {
            return ['', ''];
        }

        $expectedUrl = self::PACKAGE_URL_PREFIX . 'v' . $latestVersion . '/OpenNfse-' . $latestVersion . '.zip';
        if ($url !== $expectedUrl) {
            throw new \RuntimeException('O manifesto remoto retornou uma URL de pacote inválida.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            throw new \RuntimeException('O manifesto remoto retornou um SHA-256 de pacote inválido.');
        }

        return [$url, $sha];
    }

    private function buildConfigPayloadFromManifest(array $manifest): array
    {
        return [
            'update_last_checked_at' => date('Y-m-d H:i:s'),
            'update_last_status' => 'ok',
            'update_latest_version' => (string) ($manifest['latest_version'] ?? ''),
            'update_message' => (string) ($manifest['message'] ?? ''),
            'update_download_url' => (string) ($manifest['download_url'] ?? ''),
            'update_changelog_url' => (string) ($manifest['changelog_url'] ?? ''),
            'update_minimum_whmcs' => (string) ($manifest['minimum_whmcs'] ?? ''),
            'update_minimum_php' => (string) ($manifest['minimum_php'] ?? ''),
            'update_package_url' => (string) ($manifest['package_url'] ?? '') ?: null,
            'update_package_sha256' => (string) ($manifest['package_sha256'] ?? '') ?: null,
            'update_error' => null,
        ];
    }

    private function httpGet(string $url, int $timeoutSeconds, array $allowedHosts, int $maxBytes): ?string
    {
        if (!$this->isAllowedPublicUrl($url, $allowedHosts)) {
            return null;
        }

        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeoutSeconds,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'header' => "Accept: application/json\r\nUser-Agent: OpenNfse-UpdateCheck/0.1\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ]);
            $data = @file_get_contents($url, false, $ctx);
            if ($data === false || strlen($data) > $maxBytes) {
                return null;
            }

            return $data;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'User-Agent: OpenNfse-UpdateCheck/0.1']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code < 200 || $code >= 400 || strlen((string) $resp) > $maxBytes) {
            return null;
        }

        return (string) $resp;
    }

    private function isAllowedPublicUrl(string $url, ?array $allowedHosts = null): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        $allowedHosts = $allowedHosts ?? ['github.com', 'raw.githubusercontent.com'];

        return in_array($host, $allowedHosts, true);
    }
}
