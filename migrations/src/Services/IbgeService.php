<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Helpers\NameNormalizer;
use OpenNfse\Helpers\UfNormalizer;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\IbgeMunicipioRepository;

final class IbgeService
{
    public const MUNICIPIOS_SOURCE_URL = 'https://raw.githubusercontent.com/kelvins/municipios-brasileiros/main/json/municipios.json';
    public const ESTADOS_SOURCE_URL = 'https://raw.githubusercontent.com/kelvins/municipios-brasileiros/main/json/estados.json';
    public const VIACEP_URL = 'https://viacep.com.br/ws/%s/json/';
    private const HTTP_USER_AGENT = 'OpenNfse-IbgeSync/0.1';
    private const MAX_REMOTE_BODY_BYTES = 8388608;
    private const MAX_VIACEP_BODY_BYTES = 262144;
    private const MIN_EXPECTED_MUNICIPIOS = 5000;
    private const MAX_EXPECTED_MUNICIPIOS = 7000;
    private const ALLOWED_PRIMARY_HOSTS = ['raw.githubusercontent.com'];
    private const ALLOWED_VIACEP_HOSTS = ['viacep.com.br', 'www.viacep.com.br'];
    private const ALLOWED_UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];
    private const HASH_APPROVAL_REQUIRED_MESSAGE = 'A fonte primária mudou desde o último hash aprovado. Aprove o novo hash antes de sincronizar.';

    public function ensureMunicipiosCatalogPopulated(): void
    {
        static $attempted = false;

        if ($attempted) {
            return;
        }

        $attempted = true;

        try {
            if ((new IbgeMunicipioRepository())->countAll() > 0) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        try {
            $this->syncMunicipiosCatalog();
        } catch (\Throwable $e) {
        }
    }

    public function getMunicipiosSourceUrl(): string
    {
        return self::MUNICIPIOS_SOURCE_URL;
    }

    public function getCatalogStatus(array $config = []): array
    {
        $repo = new IbgeMunicipioRepository();
        $localCount = 0;
        try {
            $localCount = $repo->countAll();
        } catch (\Throwable $e) {
            $localCount = 0;
        }

        $status = [
            'source_url' => $this->getMunicipiosSourceUrl(),
            'local_count' => $localCount,
            'remote_count' => null,
            'remote_hash' => null,
            'pinned_hash' => trim((string) ($config['ibge_sync_pinned_hash'] ?? '')),
            'pinned_count' => isset($config['ibge_sync_pinned_count']) ? (int) $config['ibge_sync_pinned_count'] : null,
            'pinned_at' => (string) ($config['ibge_sync_pinned_at'] ?? ''),
            'pending_hash' => trim((string) ($config['ibge_sync_pending_hash'] ?? '')),
            'pending_count' => isset($config['ibge_sync_pending_count']) ? (int) $config['ibge_sync_pending_count'] : null,
            'pending_checked_at' => (string) ($config['ibge_sync_pending_checked_at'] ?? ''),
            'has_pinned_hash' => trim((string) ($config['ibge_sync_pinned_hash'] ?? '')) !== '',
            'approval_required' => false,
            'is_available' => false,
            'is_consistent' => false,
            'status' => $localCount > 0 ? 'local_only' : 'empty',
            'label' => $localCount > 0 ? 'Aguardando checagem remota' : 'Base vazia',
            'message' => $localCount > 0
                ? 'A base local possui registros, mas a fonte remota ainda não foi comparada nesta visualização.'
                : 'A base local está vazia e precisa de sincronização.',
            'last_synced_at' => (string) ($config['ibge_sync_last_synced_at'] ?? ''),
            'last_checked_at' => (string) ($config['ibge_sync_last_checked_at'] ?? ''),
            'last_hash' => (string) ($config['ibge_sync_last_hash'] ?? ''),
            'last_sync_count' => isset($config['ibge_sync_last_count']) ? (int) $config['ibge_sync_last_count'] : null,
            'last_status' => (string) ($config['ibge_sync_last_status'] ?? ''),
            'last_error' => (string) ($config['ibge_sync_last_error'] ?? ''),
            'error' => '',
        ];

        try {
            $dataset = $this->fetchMunicipiosDataset();
            $status['remote_count'] = $dataset['count'];
            $status['remote_hash'] = $dataset['hash'];
            $status['is_available'] = true;
            $remoteMatchesPinned = $status['pinned_hash'] !== '' && $status['pinned_hash'] === (string) $dataset['hash'];
            $status['is_consistent'] = $localCount > 0
                && $localCount === (int) $dataset['count']
                && (
                    $status['last_hash'] === ''
                    || $status['last_hash'] === (string) $dataset['hash']
                )
                && ($status['pinned_hash'] === '' || $remoteMatchesPinned);

            if ($localCount === 0) {
                $status['status'] = 'empty';
                $status['label'] = 'Base vazia';
                $status['message'] = $status['has_pinned_hash']
                    ? 'A fonte remota está acessível, mas a base local ainda não foi populada com o hash aprovado.'
                    : 'A fonte remota está acessível, mas a base local ainda não foi populada.';
            } elseif ($status['pinned_hash'] === '') {
                $status['status'] = 'unpinned';
                $status['label'] = 'Sem hash aprovado';
                $status['message'] = 'A base local existe, mas ainda não há um hash confiável aprovado. A próxima sincronização aprovada fixa esse baseline.';
            } elseif (!$remoteMatchesPinned) {
                $status['status'] = 'approval_required';
                $status['label'] = 'Aprovação necessária';
                $status['message'] = 'A fonte primária mudou desde o último hash aprovado. Revise e aprove o novo hash antes de atualizar o banco.';
                $status['approval_required'] = true;
            } elseif ($status['is_consistent']) {
                $status['status'] = 'consistent';
                $status['label'] = 'Sincronizado';
                $status['message'] = 'A quantidade de municípios no banco bate com a fonte primária e o hash aprovado continua consistente.';
            } else {
                $status['status'] = 'divergent';
                $status['label'] = 'Divergente';
                $status['message'] = 'A fonte primária está acessível e o hash aprovado confere, mas a base local diverge do catálogo esperado.';
            }
        } catch (\Throwable $e) {
            $status['error'] = $e->getMessage();
            $status['status'] = $localCount > 0 ? 'local_only' : 'unavailable';
            $status['label'] = $localCount > 0 ? 'Usando base local' : 'Fonte indisponível';
            $status['message'] = $localCount > 0
                ? 'A fonte primária não respondeu nesta checagem, mas a base local continua disponível.'
                : 'A fonte primária não respondeu e a base local está vazia.';
        }

        return $status;
    }

    public function syncMunicipiosCatalog(): array
    {
        return $this->syncMunicipiosCatalogInternal(false);
    }

    public function approveAndSyncMunicipiosCatalog(): array
    {
        return $this->syncMunicipiosCatalogInternal(true);
    }

    public function getHashApprovalRequiredMessage(): string
    {
        return self::HASH_APPROVAL_REQUIRED_MESSAGE;
    }

    private function syncMunicipiosCatalogInternal(bool $approvedOverride): array
    {
        $dataset = $this->fetchMunicipiosDataset();
        $config = (new ConfigRepository())->get();
        $pinnedHash = trim((string) ($config['ibge_sync_pinned_hash'] ?? ''));
        $now = date('Y-m-d H:i:s');

        if (!$approvedOverride && $pinnedHash !== '' && $pinnedHash !== (string) $dataset['hash']) {
            $this->persistSyncMetadata([
                'ibge_sync_last_checked_at' => $now,
                'ibge_sync_last_status' => 'approval_required',
                'ibge_sync_last_error' => self::HASH_APPROVAL_REQUIRED_MESSAGE,
                'ibge_sync_pending_hash' => (string) $dataset['hash'],
                'ibge_sync_pending_count' => (int) $dataset['count'],
                'ibge_sync_pending_checked_at' => $now,
            ]);
            throw new \RuntimeException(self::HASH_APPROVAL_REQUIRED_MESSAGE);
        }

        $repo = new IbgeMunicipioRepository();
        $synced = $repo->replaceCatalog($dataset['rows']);

        if ($synced === 0) {
            throw new \RuntimeException('Nenhum município válido foi importado da fonte primária.');
        }

        $metadata = [
            'ibge_sync_last_hash' => (string) $dataset['hash'],
            'ibge_sync_last_count' => (int) $dataset['count'],
            'ibge_sync_last_checked_at' => $now,
            'ibge_sync_last_synced_at' => $now,
            'ibge_sync_last_status' => 'ok',
            'ibge_sync_last_error' => null,
            'ibge_sync_pending_hash' => null,
            'ibge_sync_pending_count' => null,
            'ibge_sync_pending_checked_at' => null,
        ];
        if ($pinnedHash === '' || $approvedOverride) {
            $metadata['ibge_sync_pinned_hash'] = (string) $dataset['hash'];
            $metadata['ibge_sync_pinned_count'] = (int) $dataset['count'];
            $metadata['ibge_sync_pinned_at'] = $now;
        }

        $this->persistSyncMetadata($metadata);

        return [
            'synced' => $synced,
            'hash' => (string) $dataset['hash'],
            'remote_count' => (int) $dataset['count'],
            'source_url' => (string) $dataset['source_url'],
        ];
    }

    public function getIbgeCode(string $municipio, string $uf, ?string $cep = null): ?string
    {
        $this->ensureMunicipiosCatalogPopulated();

        $uf = UfNormalizer::normalize($uf);
        $municipio = trim($municipio);
        if ($uf === '' || $municipio === '') {
            return null;
        }

        $nomeNormalizado = NameNormalizer::normalize($municipio);
        if ($nomeNormalizado !== '') {
            $local = (new IbgeMunicipioRepository())->findByUfAndNormalizedName($uf, $nomeNormalizado);
            if ($local !== null) {
                return $local;
            }
        }

        if ($cep !== null) {
            $cepDigits = preg_replace('/\D/', '', $cep);
            if ($cepDigits && strlen($cepDigits) === 8) {
                $viaCep = $this->lookupViaCep($cepDigits);
                if ($viaCep['ibge'] !== null) {
                    return $viaCep['ibge'];
                }
            }
        }

        return null;
    }

    public function getConfiguredMunicipioStatus(array $config): array
    {
        $ibgeCode = preg_replace('/\D/', '', (string) ($config['codigo_ibge'] ?? ''));
        if ($ibgeCode === '' || strlen($ibgeCode) !== 7) {
            return [
                'status' => 'missing',
                'label' => 'Código não configurado',
                'message' => 'O código IBGE do prestador ainda não foi informado na configuração.',
                'ibge_code' => $ibgeCode,
                'municipio' => '',
            ];
        }

        $this->ensureMunicipiosCatalogPopulated();
        $row = (new IbgeMunicipioRepository())->findByIbgeCode($ibgeCode);
        if ($row === null) {
            return [
                'status' => 'not_found',
                'label' => 'Código não encontrado',
                'message' => 'O código IBGE configurado não foi localizado na base local de municípios.',
                'ibge_code' => $ibgeCode,
                'municipio' => '',
            ];
        }

        $nome = trim((string) ($row['nome_original'] ?? $row['nome_normalizado'] ?? ''));
        $uf = trim((string) ($row['uf'] ?? ''));
        return [
            'status' => 'ok',
            'label' => 'Código válido',
            'message' => 'O código IBGE configurado corresponde a um município válido na base local.',
            'ibge_code' => $ibgeCode,
            'municipio' => $uf !== '' ? ($nome . ' - ' . $uf) : $nome,
        ];
    }

    public function getViaCepStatus(array $config): array
    {
        $cep = preg_replace('/\D/', '', (string) ($config['prestador_cep'] ?? ''));
        $expectedIbge = preg_replace('/\D/', '', (string) ($config['codigo_ibge'] ?? ''));
        if ($cep === '' || strlen($cep) !== 8) {
            return [
                'status' => 'missing',
                'label' => 'CEP do prestador ausente',
                'message' => 'Informe um CEP válido do prestador para validar o fallback do ViaCEP.',
                'cep' => $cep,
                'ibge' => null,
                'localidade' => '',
                'uf' => '',
                'matches_expected' => false,
            ];
        }

        $lookup = $this->lookupViaCep($cep);
        if (!$lookup['available']) {
            return [
                'status' => 'unavailable',
                'label' => 'ViaCEP indisponível',
                'message' => 'Não foi possível consultar o ViaCEP agora. O fallback permanece configurado, mas sem validação instantânea.',
                'cep' => $cep,
                'ibge' => null,
                'localidade' => '',
                'uf' => '',
                'matches_expected' => false,
            ];
        }

        $matches = $lookup['ibge'] !== null && $expectedIbge !== '' && $lookup['ibge'] === $expectedIbge;
        return [
            'status' => $matches ? 'ok' : 'warning',
            'label' => $matches ? 'ViaCEP validado' : 'ViaCEP respondeu com divergência',
            'message' => $matches
                ? 'O ViaCEP retornou o mesmo código IBGE configurado para o prestador.'
                : 'O ViaCEP respondeu, mas o código IBGE retornado difere do configurado.',
            'cep' => $cep,
            'ibge' => $lookup['ibge'],
            'localidade' => (string) $lookup['localidade'],
            'uf' => (string) $lookup['uf'],
            'matches_expected' => $matches,
        ];
    }

    private function lookupViaCep(string $cep): array
    {
        $url = sprintf(self::VIACEP_URL, rawurlencode($cep));
        $body = $this->httpGet($url, 5, self::ALLOWED_VIACEP_HOSTS, self::MAX_VIACEP_BODY_BYTES);
        if ($body === null) {
            return [
                'available' => false,
                'ibge' => null,
                'localidade' => '',
                'uf' => '',
            ];
        }

        $json = json_decode($body, true);
        if (!is_array($json) || !empty($json['erro'])) {
            return [
                'available' => false,
                'ibge' => null,
                'localidade' => '',
                'uf' => '',
            ];
        }

        $ibge = isset($json['ibge']) ? preg_replace('/\D/', '', (string) $json['ibge']) : '';
        return [
            'available' => true,
            'ibge' => ($ibge !== '' && strlen($ibge) === 7) ? $ibge : null,
            'localidade' => trim((string) ($json['localidade'] ?? '')),
            'uf' => UfNormalizer::normalize((string) ($json['uf'] ?? '')),
        ];
    }

    private function httpGet(string $url, int $timeoutSeconds, array $allowedHosts, int $maxBytes): ?string
    {
        if (!$this->isAllowedRemoteUrl($url, $allowedHosts)) {
            return null;
        }

        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeoutSeconds,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'header' => "Accept: application/json\r\nUser-Agent: " . self::HTTP_USER_AGENT . "\r\n",
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'User-Agent: ' . self::HTTP_USER_AGENT]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400 || $code < 200 || strlen((string) $resp) > $maxBytes) {
            return null;
        }

        return (string) $resp;
    }

    private function fetchMunicipiosDataset(): array
    {
        $municipiosBody = $this->httpGet($this->getMunicipiosSourceUrl(), 30, self::ALLOWED_PRIMARY_HOSTS, self::MAX_REMOTE_BODY_BYTES);
        if ($municipiosBody === null) {
            throw new \RuntimeException('Não foi possível consultar a fonte primária de municípios.');
        }

        $estadosBody = $this->httpGet(self::ESTADOS_SOURCE_URL, 30, self::ALLOWED_PRIMARY_HOSTS, self::MAX_REMOTE_BODY_BYTES);
        if ($estadosBody === null) {
            throw new \RuntimeException('Não foi possível consultar a lista de estados da fonte primária.');
        }

        $municipios = json_decode($this->stripUtf8Bom($municipiosBody), true, 512, JSON_BIGINT_AS_STRING);
        $estados = json_decode($this->stripUtf8Bom($estadosBody), true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($municipios) || $municipios === []) {
            throw new \RuntimeException('A fonte primária de municípios retornou JSON inválido.');
        }
        if (!is_array($estados) || $estados === []) {
            throw new \RuntimeException('A fonte primária de estados retornou JSON inválido.');
        }

        $codigoUfMap = [];
        foreach ($estados as $estado) {
            if (!is_array($estado)) {
                continue;
            }

            $codigoUf = isset($estado['codigo_uf']) ? (int) $estado['codigo_uf'] : 0;
            $uf = UfNormalizer::normalize((string) ($estado['uf'] ?? ''));
            if ($codigoUf > 0 && in_array($uf, self::ALLOWED_UFS, true)) {
                $codigoUfMap[$codigoUf] = $uf;
            }
        }

        $rows = [];
        $seenIbgeCodes = [];
        $seenUfNome = [];
        foreach ($municipios as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ibgeCode = isset($item['codigo_ibge']) ? preg_replace('/\D/', '', (string) $item['codigo_ibge']) : '';
            $nome = $this->sanitizeMunicipioNome((string) ($item['nome_municipio'] ?? $item['nome'] ?? ''));
            $uf = UfNormalizer::normalize((string) ($item['uf'] ?? ''));
            if ($uf === '' && isset($item['codigo_uf'])) {
                $uf = $codigoUfMap[(int) $item['codigo_uf']] ?? '';
            }
            if ($ibgeCode === '' || strlen($ibgeCode) !== 7 || $nome === '' || !in_array($uf, self::ALLOWED_UFS, true)) {
                continue;
            }

            $nomeNormalizado = NameNormalizer::normalize($nome);
            if ($nomeNormalizado === '') {
                continue;
            }
            if (isset($seenIbgeCodes[$ibgeCode])) {
                throw new \RuntimeException('A fonte primária retornou código IBGE duplicado: ' . $ibgeCode . '.');
            }

            $pairKey = $uf . '|' . $nomeNormalizado;
            if (isset($seenUfNome[$pairKey])) {
                throw new \RuntimeException('A fonte primária retornou município duplicado para a UF ' . $uf . '.');
            }

            $seenIbgeCodes[$ibgeCode] = true;
            $seenUfNome[$pairKey] = true;

            $rows[] = [
                'ibge_code' => $ibgeCode,
                'nome_original' => $nome,
                'nome_normalizado' => $nomeNormalizado,
                'uf' => $uf,
            ];
        }

        if ($rows === []) {
            throw new \RuntimeException('Nenhum município válido foi encontrado na fonte primária.');
        }
        if (count($rows) < self::MIN_EXPECTED_MUNICIPIOS || count($rows) > self::MAX_EXPECTED_MUNICIPIOS) {
            throw new \RuntimeException('A fonte primária retornou uma quantidade inesperada de municípios.');
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) ($left['ibge_code'] ?? ''), (string) ($right['ibge_code'] ?? ''));
        });

        $normalizedHash = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'source_url' => $this->getMunicipiosSourceUrl(),
            'count' => count($rows),
            'hash' => $normalizedHash,
            'rows' => $rows,
        ];
    }

    private function persistSyncMetadata(array $data): void
    {
        $configRepo = new ConfigRepository();
        $current = $configRepo->get();
        if ($current === []) {
            return;
        }

        $configRepo->save($data);
    }

    private function stripUtf8Bom(string $value): string
    {
        if (substr($value, 0, 3) === "\xEF\xBB\xBF") {
            return substr($value, 3);
        }

        return $value;
    }

    private function isAllowedRemoteUrl(string $url, array $allowedHosts): bool
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

        return in_array($host, $allowedHosts, true);
    }

    private function sanitizeMunicipioNome(string $nome): string
    {
        $nome = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $nome) ?? $nome;
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? $nome);
        if ($nome === '') {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($nome, 'UTF-8') : strlen($nome);
        if ($length > 120) {
            throw new \RuntimeException('A fonte primária retornou nome de município acima do limite esperado.');
        }

        return $nome;
    }
}
