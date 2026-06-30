<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Repositories\ConfigRepository;

final class UpdateCheckService
{
    private const MANIFEST_URL = 'https://raw.githubusercontent.com/jaison/OpenNfse/main/update.json';
    private const ALLOWED_HOSTS = ['raw.githubusercontent.com'];
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
        $updateAvailable = $latestVersion !== '' && version_compare($latestVersion, $currentVersion, '>');

        if ($status === '') {
            $status = 'never_checked';
        }

        $label = 'Aguardando checagem';
        $summary = 'Ainda não houve verificação remota de atualização.';

        if ($status === 'ok') {
            if ($updateAvailable) {
                $label = 'Atualização disponível';
                $summary = $message !== '' ? $message : 'Há uma nova versão disponível para o módulo.';
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
            'message' => $message,
            'error' => $error,
            'status' => $status,
            'label' => $label,
            'summary' => $summary,
            'update_available' => $updateAvailable,
            'is_stale' => $this->isCheckDue($config),
            'manifest_url' => self::MANIFEST_URL,
        ];
    }

    public function checkNow(): array
    {
        $manifest = $this->fetchManifest();
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
        static $version = null;
        if (is_string($version) && $version !== '') {
            return $version;
        }

        $moduleFile = dirname(__DIR__, 2) . '/OpenNfse.php';
        $contents = @file_get_contents($moduleFile);
        if (is_string($contents) && preg_match("/'version'\s*=>\s*'([^']+)'/", $contents, $matches)) {
            $version = trim((string) ($matches[1] ?? ''));
        }

        if (!is_string($version) || $version === '') {
            $version = '0.0.0';
        }

        return $version;
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
        if ($module !== 'OpenNfse' || $latestVersion === '' || !preg_match('/^\d+\.\d+\.\d+([-.][A-Za-z0-9]+)?$/', $latestVersion)) {
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

        return [
            'latest_version' => $latestVersion,
            'message' => trim((string) ($data['message'] ?? '')),
            'download_url' => $downloadUrl,
            'changelog_url' => $changelogUrl,
            'minimum_whmcs' => trim((string) ($data['minimum_whmcs'] ?? '')),
            'minimum_php' => trim((string) ($data['minimum_php'] ?? '')),
        ];
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
