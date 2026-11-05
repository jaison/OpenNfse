<?php

declare(strict_types=1);

namespace OpenNfse\Services;

/**
 * Monta os documentos fiscais dos relatórios a partir do histórico fiscal e da tabela atual.
 * A tabela de notas tem uma linha por fatura; numa reemissão, a nota anterior só segue no histórico.
 * Um documento fiscal é uma chave de acesso.
 */
final class FiscalDocumentReportService
{
    public const DIR_EMITIDAS = 'Emitidas';
    public const DIR_CANCELADAS = 'Canceladas';
    public const DIR_EXTERIOR = 'Emitidas para Estrangeiros';

    /**
     * @return array<int, string>
     */
    public static function competenciaZipDirectories(): array
    {
        return [self::DIR_EMITIDAS, self::DIR_CANCELADAS, self::DIR_EXTERIOR];
    }

    /**
     * Documentos do ZIP por competência. Chave com CANCELAMENTO no histórico vai para Canceladas, com o XML
     * da nota e o de cancelamento; sem cancelamento, vai para Emitidas (ou Estrangeiros) só com o XML da nota.
     * Só conta como cancelamento o registro com cancelado_em: o backfill grava CANCELAMENTO sem data
     * quando a linha tinha apenas uma falha de cancelamento.
     * Linhas da tabela atual cuja chave não está no histórico em data nenhuma (e as sem chave) completam a lista.
     *
     * @param array<int, array<string, mixed>> $historyRows registros EMISSAO/CANCELAMENTO, do mais novo ao mais antigo
     * @param array<int, array<string, mixed>> $notaRows linhas de mod_opennfse_notas já filtradas
     * @param array<int, string> $statuses status aceitos (vazio = todos)
     * @param array<int, string> $historyChaves chaves das linhas que existem no histórico, em qualquer data
     * @return array<int, array{directory: string, paths: array<int, string>, row: array<string, mixed>}>
     */
    public function buildCompetenciaZipDocuments(
        array $historyRows,
        array $notaRows,
        array $statuses = [],
        array $historyChaves = []
    ): array {
        $statuses = array_values(array_filter(array_map(
            static fn ($s): string => strtoupper(trim((string) $s)),
            $statuses
        ), static fn (string $s): bool => $s !== ''));

        $notaByChave = [];
        foreach ($notaRows as $row) {
            $chave = $this->text($row['chave_acesso'] ?? null);
            if ($chave !== '' && !isset($notaByChave[$chave])) {
                $notaByChave[$chave] = $row;
            }
        }

        $byChave = [];
        foreach ($historyRows as $row) {
            $chave = $this->text($row['chave_acesso'] ?? null);
            if ($chave === '') {
                continue;
            }
            $tipo = strtoupper($this->text($row['tipo_registro'] ?? null));
            $slot = $tipo === 'CANCELAMENTO' ? 'cancelamento' : ($tipo === 'EMISSAO' ? 'emissao' : '');
            if ($slot === '' || ($slot === 'cancelamento' && $this->text($row['cancelado_em'] ?? null) === '')) {
                continue;
            }
            $pathField = $slot === 'cancelamento' ? 'cancel_xml_path' : 'xml_path';
            $current = $byChave[$chave][$slot] ?? null;
            // Mais de um registro por chave (consulta, backfill): fica o mais novo que tenha o XML.
            if ($current === null || ($this->text($current[$pathField] ?? null) === '' && $this->text($row[$pathField] ?? null) !== '')) {
                $byChave[$chave][$slot] = $row;
            }
        }

        $documents = [];
        $seenChaves = [];
        foreach ($historyChaves as $chave) {
            $chave = $this->text($chave);
            if ($chave !== '') {
                $seenChaves[$chave] = true;
            }
        }
        $seenPaths = [];

        foreach ($byChave as $chave => $records) {
            $seenChaves[$chave] = true;
            $emissao = $records['emissao'] ?? null;
            $cancelamento = $records['cancelamento'] ?? null;
            $nota = $notaByChave[$chave] ?? null;
            // Linha atual CANCELADA com esta chave também vale (histórico incompleto).
            $notaCancelada = $nota !== null && strtoupper($this->text($nota['status'] ?? null)) === 'CANCELADA';
            $cancelled = $cancelamento !== null || $notaCancelada;
            $status = $cancelled ? 'CANCELADA' : 'EMITIDA';
            if ($statuses !== [] && !in_array($status, $statuses, true)) {
                continue;
            }

            $base = $emissao ?? $cancelamento ?? $nota;
            $xmlPath = $this->text($emissao['xml_path'] ?? null);
            if ($xmlPath === '') {
                $xmlPath = $this->text($cancelamento['xml_path'] ?? null);
            }
            if ($xmlPath === '' && $nota !== null) {
                $xmlPath = $this->text($nota['xml_path'] ?? null);
            }

            // O XML de cancelamento vem do registro CANCELAMENTO da mesma chave (ou da linha CANCELADA dela),
            // nunca do registro EMISSAO.
            $paths = [$xmlPath];
            if ($cancelled) {
                $cancelPath = $this->text($cancelamento['cancel_xml_path'] ?? null);
                if ($cancelPath === '' && $notaCancelada) {
                    $cancelPath = $this->text($nota['cancel_xml_path'] ?? null);
                }
                $paths[] = $cancelPath;
            }

            $row = $base;
            $row['status'] = $status;
            if ($this->text($row['country'] ?? null) === '' && $nota !== null) {
                $row['country'] = $nota['country'] ?? null;
            }

            $this->appendDocument($documents, $seenPaths, $row, $paths, $this->resolveDirectory($row));
        }

        foreach ($notaRows as $row) {
            $chave = $this->text($row['chave_acesso'] ?? null);
            if ($chave !== '' && isset($seenChaves[$chave])) {
                continue;
            }

            $paths = [$this->text($row['xml_path'] ?? null)];
            // Fora de CANCELADA, o cancel_xml_path da linha não é desta nota.
            if (strtoupper($this->text($row['status'] ?? null)) === 'CANCELADA') {
                $paths[] = $this->text($row['cancel_xml_path'] ?? null);
            }

            $this->appendDocument($documents, $seenPaths, $row, $paths, $this->resolveDirectory($row));
        }

        return $documents;
    }

    /**
     * Cancelamentos do período: registros CANCELAMENTO do histórico mais as linhas atuais com cancelado_em,
     * sem repetir a mesma chave (nem a mesma fatura com o mesmo horário de cancelamento).
     *
     * @param array<int, array<string, mixed>> $historyRows
     * @param array<int, array<string, mixed>> $notaRows
     * @return array<int, array<string, mixed>>
     */
    public function mergeCancelamentos(array $historyRows, array $notaRows, int $limit): array
    {
        $out = [];
        $seen = [];
        foreach ([$historyRows, $notaRows] as $rows) {
            foreach ($rows as $row) {
                $canceladoEm = $this->text($row['cancelado_em'] ?? null);
                if ($canceladoEm === '') {
                    continue;
                }

                $keys = ['evento:' . (int) ($row['invoiceid'] ?? 0) . '|' . $canceladoEm];
                $chave = $this->text($row['chave_acesso'] ?? null);
                if ($chave !== '') {
                    $keys[] = 'chave:' . $chave;
                }

                $duplicate = false;
                foreach ($keys as $key) {
                    if (isset($seen[$key])) {
                        $duplicate = true;
                        break;
                    }
                }
                if ($duplicate) {
                    continue;
                }

                foreach ($keys as $key) {
                    $seen[$key] = true;
                }
                $out[] = $row;
            }
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp((string) ($b['cancelado_em'] ?? ''), (string) ($a['cancelado_em'] ?? ''));
        });

        return array_slice($out, 0, max(1, $limit));
    }

    public function resolveDirectory(array $row): string
    {
        if (strtoupper($this->text($row['status'] ?? null)) === 'CANCELADA') {
            return self::DIR_CANCELADAS;
        }

        if (strtoupper($this->text($row['country'] ?? null)) !== 'BR') {
            return self::DIR_EXTERIOR;
        }

        return self::DIR_EMITIDAS;
    }

    /**
     * @param array<int, array{directory: string, paths: array<int, string>, row: array<string, mixed>}> $documents
     * @param array<string, bool> $seenPaths
     * @param array<int, string> $paths
     */
    private function appendDocument(array &$documents, array &$seenPaths, array $row, array $paths, string $directory): void
    {
        $unique = [];
        foreach ($paths as $path) {
            if ($path === '' || isset($seenPaths[$path])) {
                continue;
            }
            $seenPaths[$path] = true;
            $unique[] = $path;
        }

        if ($unique === []) {
            return;
        }

        $documents[] = [
            'directory' => $directory,
            'paths' => $unique,
            'row' => $row,
        ];
    }

    private function text($value): string
    {
        return trim((string) $value);
    }
}
