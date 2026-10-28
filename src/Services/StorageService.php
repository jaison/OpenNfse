<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Helpers\NfseXmlExtractor;
use OpenNfse\Repositories\NotaRepository;

final class StorageService
{
    private const MAX_FALLBACK_XML_BYTES = 5 * 1024 * 1024;

    public function getWhmcsRootDir(): string
    {
        if (defined('ROOTDIR')) {
            return rtrim((string) ROOTDIR, '/');
        }

        $dir = (string) dirname(__DIR__, 7);
        $candidate = $dir;
        for ($i = 0; $i < 6; $i++) {
            if (is_file($candidate . '/init.php') && is_dir($candidate . '/attachments') && is_dir($candidate . '/modules')) {
                return rtrim($candidate, '/');
            }
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                break;
            }
            $candidate = $parent;
        }

        return rtrim($dir, '/');
    }

    public function getAttachmentsDir(): string
    {
        $whmcsRoot = $this->getWhmcsRootDir();

        $attachmentsDir = null;
        if (array_key_exists('attachments_dir', $GLOBALS)) {
            $attachmentsDir = $GLOBALS['attachments_dir'];
        } elseif (isset($GLOBALS['attachments_dir'])) {
            $attachmentsDir = $GLOBALS['attachments_dir'];
        }
        if (is_string($attachmentsDir)) {
            $attachmentsDir = trim($attachmentsDir);
            if ($attachmentsDir !== '') {
                return rtrim($attachmentsDir, '/');
            }
        }

        return $whmcsRoot . '/attachments';
    }

    private function getCandidateAttachmentsDirs(): array
    {
        $dirs = [];

        $attachmentsDir = $this->getAttachmentsDir();
        if ($attachmentsDir !== '' && is_dir($attachmentsDir)) {
            $dirs[] = rtrim($attachmentsDir, '/');
        }

        $rootAttachments = rtrim($this->getWhmcsRootDir(), '/') . '/attachments';
        if (is_dir($rootAttachments)) {
            $dirs[] = rtrim($rootAttachments, '/');
        }

        return array_values(array_unique($dirs));
    }

    /**
     * Localiza o XML de emissão da própria fatura quando o xml_path salvo não resolve.
     *
     * Só considera arquivos cujo nome contém o ID da fatura e só retorna um arquivo
     * cujo conteúdo (chave de acesso e/ou número da NFS-e) confere com a nota.
     * Sem dados da nota para comparar, retorna null.
     */
    public function findLatestXmlAbsolutePath(int $invoiceId, ?array $nota = null): ?string
    {
        if ($invoiceId <= 0) {
            return null;
        }

        if ($nota === null) {
            try {
                $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
            } catch (\Throwable $e) {
                $nota = null;
            }
        }
        if (!is_array($nota)) {
            return null;
        }
        if (array_key_exists('invoiceid', $nota) && (int) $nota['invoiceid'] !== $invoiceId) {
            return null;
        }

        $numeroNf = trim((string) ($nota['numero_nf'] ?? ''));
        $chaveAcesso = trim((string) ($nota['chave_acesso'] ?? ''));
        if ($numeroNf === '' && $chaveAcesso === '') {
            return null;
        }

        $id = preg_quote((string) $invoiceId, '/');
        $patterns = [];
        if ($numeroNf !== '') {
            $numero = preg_quote($this->sanitizeFilenamePart($numeroNf), '/');
            $patterns[] = '/^nfse_' . $numero . '_' . $id . '_[0-9]{6}\.xml$/';
            $patterns[] = '/^nfse_' . $numero . '_fatura' . $id . '_[0-9]{6}\.xml$/';
        }
        $patterns[] = '/^nfse_[A-Za-z0-9_-]+_' . $id . '_[0-9]{6}\.xml$/';
        $patterns[] = '/^nfse_[A-Za-z0-9_-]+_fatura' . $id . '_[0-9]{6}\.xml$/';
        $patterns[] = '/^nfse_invoice_' . $id . '_[A-Za-z0-9_-]*\.xml$/';

        foreach ($this->getCandidateAttachmentsDirs() as $attachmentsDir) {
            $baseDir = $attachmentsDir . '/nfse/xml';
            if (!is_dir($baseDir)) {
                continue;
            }

            $candidates = [];
            foreach ($this->iterateXmlFiles($baseDir) as $path => $file) {
                if (!$this->matchesAnyPattern($file, $patterns)) {
                    continue;
                }

                $mtime = filemtime($path);
                $candidates[$path] = $mtime !== false ? $mtime : 0;
            }
            arsort($candidates);

            foreach (array_keys($candidates) as $path) {
                if (!$this->xmlMatchesNota((string) $path, $numeroNf, $chaveAcesso)) {
                    continue;
                }

                $rp = realpath((string) $path);
                return $rp !== false ? $rp : (string) $path;
            }
        }

        return null;
    }

    private function xmlMatchesNota(string $path, string $numeroNf, string $chaveAcesso): bool
    {
        $size = @filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_FALLBACK_XML_BYTES) {
            return false;
        }

        $xml = @file_get_contents($path);
        if (!is_string($xml) || $xml === '') {
            return false;
        }

        $compared = false;

        if ($chaveAcesso !== '') {
            $xmlChave = NfseXmlExtractor::extractChaveAcesso($xml)
                ?? NfseXmlExtractor::extractChaveAcessoFromInfNfseId($xml);
            if ($xmlChave !== null) {
                if ($this->normalizeChave($xmlChave) !== $this->normalizeChave($chaveAcesso)) {
                    return false;
                }
                $compared = true;
            }
        }

        if ($numeroNf !== '') {
            $xmlNumero = NfseXmlExtractor::extractNumeroNfse($xml);
            if ($xmlNumero !== null) {
                if ($this->normalizeNumero($xmlNumero) !== $this->normalizeNumero($numeroNf)) {
                    return false;
                }
                $compared = true;
            }
        }

        return $compared;
    }

    private function normalizeChave(string $value): string
    {
        return (string) preg_replace('/^NFS/i', '', trim($value));
    }

    private function normalizeNumero(string $value): string
    {
        $value = ltrim(trim($value), '0');

        return $value === '' ? '0' : $value;
    }

    public function relativePathFromAbsolute(string $absPath): ?string
    {
        $absPath = trim($absPath);
        if ($absPath === '') {
            return null;
        }

        $attachmentsDir = rtrim($this->getAttachmentsDir(), '/');
        if ($attachmentsDir !== '' && strpos($absPath, $attachmentsDir . '/') === 0) {
            return ltrim(substr($absPath, strlen($attachmentsDir)), '/');
        }

        $whmcsRoot = rtrim($this->getWhmcsRootDir(), '/');
        $rootAttachments = $whmcsRoot . '/attachments';
        if (strpos($absPath, $rootAttachments . '/') === 0) {
            return 'attachments/' . ltrim(substr($absPath, strlen($rootAttachments)), '/');
        }

        return null;
    }

    public function saveXml(int $invoiceId, string $xml, ?string $numeroNf = null, ?string $emitidaEm = null, ?string $environment = null, ?string $serie = null): string
    {
        $relativeDir = $this->buildXmlRelativeDir($emitidaEm, $environment, $serie);
        $filename = $this->buildXmlFilename($invoiceId, $numeroNf, $emitidaEm);
        return $this->writeXmlFile($relativeDir, $filename, $xml);
    }

    public function saveCancelamentoXml(int $invoiceId, string $xml, ?string $numeroNf = null, ?string $canceladoEm = null, ?string $environment = null, ?string $serie = null): string
    {
        $relativeDir = $this->buildXmlRelativeDir($canceladoEm, $environment, $serie);
        $filename = $this->buildCancelamentoXmlFilename($invoiceId, $numeroNf, $canceladoEm);
        return $this->writeXmlFile($relativeDir, $filename, $xml);
    }

    public function listXmlFilesByMonth(string $month, ?string $environment = null, ?string $serie = null): array
    {
        $month = trim($month);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }

        $relativeDir = $this->buildXmlRelativeDir($month . '-01 00:00:00', $environment, $serie);
        $files = [];

        foreach ($this->getCandidateAttachmentsDirs() as $attachmentsDir) {
            $baseDir = $attachmentsDir . '/' . $relativeDir;
            if (!is_dir($baseDir)) {
                continue;
            }

            foreach ($this->iterateXmlFiles($baseDir) as $path => $filename) {
                $absolutePath = realpath($path);
                if ($absolutePath === false) {
                    $absolutePath = $path;
                }

                $relativePath = $this->relativePathFromAbsolute($absolutePath);
                if ($relativePath === null) {
                    $relativePath = ltrim($relativeDir . '/' . $filename, '/');
                }

                $normalizedRelativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
                if (strpos($normalizedRelativePath, 'attachments/') === 0) {
                    $normalizedRelativePath = ltrim(substr($normalizedRelativePath, strlen('attachments/')), '/');
                }

                $mtime = filemtime($absolutePath);
                $size = filesize($absolutePath);

                $files[$normalizedRelativePath] = [
                    'filename' => $filename,
                    'relative_path' => $normalizedRelativePath,
                    'absolute_path' => $absolutePath,
                    'modified_at' => $mtime !== false ? date('Y-m-d H:i:s', $mtime) : '',
                    'size' => $size !== false ? (int) $size : 0,
                ];
            }
        }

        ksort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'relative_dir' => $relativeDir,
            'files' => array_values($files),
        ];
    }

    public function savePdf(int $invoiceId, string $pdfBytes): string
    {
        $relativeDir = 'nfse/pdf';
        $dir = $this->getAttachmentsDir() . '/' . $relativeDir;

        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new NfseModuleException('Não foi possível criar diretório de PDF: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new NfseModuleException('Diretório de PDF sem permissão de escrita: ' . $dir);
        }

        $filename = 'danfse_invoice_' . $invoiceId . '_' . date('Ymd_His') . '.pdf';
        $path = $dir . '/' . $filename;
        $written = file_put_contents($path, $pdfBytes);
        if ($written === false || $written <= 0) {
            throw new NfseModuleException('Falha ao salvar PDF em: ' . $path);
        }

        return $relativeDir . '/' . $filename;
    }

    public function resolveAbsolutePath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '') {
            throw new NfseModuleException('Arquivo não encontrado.');
        }
        if (!$this->isAllowedRelativeStoragePath($relativePath)) {
            throw new NfseModuleException('Caminho inválido.');
        }

        $whmcsRoot = $this->getWhmcsRootDir();
        $attachmentsDir = $this->getAttachmentsDir();

        $candidates = [];
        if (strpos($relativePath, 'attachments/') === 0) {
            $candidates[] = $whmcsRoot . '/' . $relativePath;
            $candidates[] = $attachmentsDir . '/' . substr($relativePath, strlen('attachments/'));
        } else {
            $candidates[] = $attachmentsDir . '/' . $relativePath;
            $candidates[] = $whmcsRoot . '/attachments/' . $relativePath;
        }

        $real = false;
        foreach ($candidates as $path) {
            $rp = realpath($path);
            if ($rp !== false) {
                $real = $rp;
                break;
            }
        }
        if ($real === false) {
            throw new NfseModuleException('Arquivo não encontrado.');
        }

        $allowedBases = [];
        foreach ($this->getCandidateAttachmentsDirs() as $ad) {
            $ax = realpath($ad . '/nfse/xml');
            if ($ax !== false) {
                $allowedBases[] = $ax;
            }
            $ap = realpath($ad . '/nfse/pdf');
            if ($ap !== false) {
                $allowedBases[] = $ap;
            }
        }

        foreach ($allowedBases as $allowedBase) {
            if ($this->isPathInsideBase($real, $allowedBase)) {
                return $real;
            }
        }

        throw new NfseModuleException('Caminho inválido.');
    }

    private function buildXmlFilename(int $invoiceId, ?string $numeroNf, ?string $emitidaEm): string
    {
        $numeroNf = trim((string) $numeroNf);
        $emitidaEm = trim((string) $emitidaEm);
        $dataEmissao = $emitidaEm !== '' ? date('mY', strtotime($emitidaEm) ?: time()) : date('mY');

        if ($numeroNf !== '') {
            return sprintf(
                'nfse_%s_%s_%s.xml',
                $this->sanitizeFilenamePart($numeroNf),
                $invoiceId,
                $this->sanitizeFilenamePart($dataEmissao)
            );
        }

        return sprintf(
            'nfse_sem-numero_%s_%s.xml',
            $invoiceId,
            $this->sanitizeFilenamePart($dataEmissao)
        );
    }

    private function buildCancelamentoXmlFilename(int $invoiceId, ?string $numeroNf, ?string $canceladoEm): string
    {
        $numeroNf = trim((string) $numeroNf);
        $canceladoEm = trim((string) $canceladoEm);
        $dataCancelamento = $canceladoEm !== '' ? date('Ymd_His', strtotime($canceladoEm) ?: time()) : date('Ymd_His');

        return sprintf(
            'cancelamento_nfse_%s_%s_%s.xml',
            $this->sanitizeFilenamePart($numeroNf !== '' ? $numeroNf : 'sem-numero'),
            $invoiceId,
            $this->sanitizeFilenamePart($dataCancelamento)
        );
    }

    private function buildXmlRelativeDir(?string $emitidaEm, ?string $environment, ?string $serie): string
    {
        $timestamp = $emitidaEm !== null && trim($emitidaEm) !== '' ? (strtotime($emitidaEm) ?: time()) : time();
        $year = date('Y', $timestamp);
        $month = date('m', $timestamp);

        return sprintf(
            'nfse/xml/%s/%s/%s/%s',
            $this->normalizeEnvironmentSegment($environment),
            $this->normalizeSerieSegment($serie),
            $year,
            $month
        );
    }

    private function writeXmlFile(string $relativeDir, string $filename, string $xml): string
    {
        $dir = $this->getAttachmentsDir() . '/' . $relativeDir;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new NfseModuleException('Não foi possível criar diretório de XML: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new NfseModuleException('Diretório de XML sem permissão de escrita: ' . $dir);
        }

        $path = $dir . '/' . $filename;
        $ok = file_put_contents($path, $xml);
        if ($ok === false) {
            throw new NfseModuleException('Falha ao salvar XML em: ' . $path);
        }

        return $relativeDir . '/' . $filename;
    }

    private function sanitizeFilenamePart(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $value) ?: 'arquivo';
    }

    private function normalizeEnvironmentSegment(?string $environment): string
    {
        $environment = strtolower(trim((string) $environment));
        if ($environment === '' || $environment === '2' || $environment === 'homologacao' || $environment === 'homologação') {
            return 'homologacao';
        }

        if ($environment === '1' || $environment === 'producao' || $environment === 'produção' || $environment === 'production') {
            return 'producao';
        }

        return $this->sanitizeFilenamePart($environment);
    }

    private function normalizeSerieSegment(?string $serie): string
    {
        $serie = trim((string) $serie);
        if ($serie === '') {
            return 'sem-serie';
        }

        return $this->sanitizeFilenamePart($serie);
    }

    private function isAllowedRelativeStoragePath(string $relativePath): bool
    {
        $normalized = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if (strpos($normalized, 'attachments/') === 0) {
            $normalized = ltrim(substr($normalized, strlen('attachments/')), '/');
        }

        return strpos($normalized, 'nfse/xml/') === 0 || strpos($normalized, 'nfse/pdf/') === 0;
    }

    private function isPathInsideBase(string $path, string $baseDir): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
        if ($path === '' || $baseDir === '') {
            return false;
        }

        return $path === $baseDir || strpos($path, $baseDir . '/') === 0;
    }

    private function matchesAnyPattern(string $filename, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }

        return false;
    }

    private function iterateXmlFiles(string $baseDir): \Generator
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
            );
        } catch (\Throwable $e) {
            return;
        }

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            $filename = $fileInfo->getFilename();
            if (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== 'xml') {
                continue;
            }

            yield $path => $filename;
        }
    }
}
