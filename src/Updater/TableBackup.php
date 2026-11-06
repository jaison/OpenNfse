<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

/**
 * Etapa 3: backup das tabelas mod_opennfse_* em SQL compactado (gzip).
 *
 * O backup e a restauração passam pela conexão do WHMCS sem trocar o charset (sem SET NAMES):
 * os bytes voltam exatamente como saíram. Muitas instalações conversam com o banco em latin1;
 * trocar o charset no meio gravaria os textos acentuados com outra codificação.
 *
 * Um comando por linha: os textos vêm escapados pelo banco (quebras de linha viram \n),
 * então a restauração lê linha a linha. As tabelas do próprio atualizador ficam de fora:
 * restaurar o backup não pode apagar a pausa nem os eventos guardados durante ela.
 */
final class TableBackup
{
    public const PREFIX = 'mod_opennfse_';
    public const HEADER = '-- OpenNfse backup v1';
    private const EXCLUDED = ['mod_opennfse_updater', 'mod_opennfse_updater_events'];
    private const ROWS_PER_FETCH = 200;
    /** Tamanho máximo de um INSERT, bem abaixo do max_allowed_packet padrão. */
    private const MAX_INSERT_BYTES = 1048576;

    public function __construct(private BackupDatabase $db)
    {
    }

    /**
     * @return array{tables: list<string>, rows: int, bytes: int, sha256: string}
     */
    public function dump(string $path, string $moduleVersion): array
    {
        $tables = $this->tables();
        $gz = @gzopen($path, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Não foi possível criar o arquivo de backup.');
        }
        @chmod($path, 0600);

        $rows = 0;
        try {
            $this->write($gz, sprintf(
                '%s module=%s created=%s charset=%s tables=%s',
                self::HEADER,
                preg_replace('/[^0-9A-Za-z.+-]/', '', $moduleVersion),
                gmdate('Y-m-d\TH:i:s\Z'),
                self::cleanCharset($this->db->connectionCharset()),
                implode(',', $tables)
            ));
            $this->write($gz, 'SET FOREIGN_KEY_CHECKS=0;');
            foreach ($tables as $table) {
                $ident = PdoBackupDatabase::ident($table);
                $create = $this->db->createStatement($table);
                if ($create === '') {
                    throw new \RuntimeException('Não foi possível ler a estrutura da tabela ' . $table . '.');
                }
                $this->write($gz, 'DROP TABLE IF EXISTS ' . $ident . ';');
                $this->write($gz, self::oneLine($create) . ';');
                $rows += $this->dumpRows($gz, $table, $ident);
            }
            $this->write($gz, 'SET FOREIGN_KEY_CHECKS=1;');
            $this->write($gz, '-- fim');
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($path);
            throw $e;
        }
        gzclose($gz);

        return [
            'tables' => $tables,
            'rows' => $rows,
            'bytes' => (int) filesize($path),
            'sha256' => (string) hash_file('sha256', $path),
        ];
    }

    /**
     * Restaura um backup gerado por dump(). Só aceita o arquivo com o SHA-256 esperado.
     *
     * @return int comandos executados
     */
    public function restore(string $path, string $expectedSha256): int
    {
        if (!is_file($path) || !hash_equals(strtolower($expectedSha256), (string) hash_file('sha256', $path))) {
            throw new \RuntimeException('O arquivo de backup não é o que foi gerado nesta atualização.');
        }
        // Primeira passada: cabeçalho e fim. Só depois executa (segunda passada).
        $first = null;
        $last = '';
        foreach ($this->lines($path) as $line) {
            $first ??= $line;
            if ($line !== '') {
                $last = $line;
            }
        }
        if ($first === null || strpos($first, self::HEADER . ' ') !== 0 || $last !== '-- fim') {
            throw new \RuntimeException('O arquivo de backup está incompleto.');
        }
        $saved = preg_match('/ charset=(\S+)/', $first, $m) === 1 ? $m[1] : '';
        $current = self::cleanCharset($this->db->connectionCharset());
        if ($saved === '' || $saved !== $current) {
            throw new \RuntimeException(sprintf(
                'O backup foi gerado com a conexão em %s e a conexão atual está em %s. Restaurar assim estragaria os acentos.',
                $saved !== '' ? $saved : 'charset desconhecido',
                $current
            ));
        }

        $count = 0;
        foreach ($this->lines($path) as $line) {
            if ($line === '' || strpos($line, '--') === 0) {
                continue;
            }
            $this->db->exec(substr($line, -1) === ';' ? substr($line, 0, -1) : $line);
            $count++;
        }

        return $count;
    }

    private static function cleanCharset(string $charset): string
    {
        return (string) preg_replace('/[^0-9A-Za-z_]/', '', $charset);
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_values(array_filter(
            $this->db->tablesWithPrefix(self::PREFIX),
            static fn (string $t): bool => !in_array($t, self::EXCLUDED, true)
        ));
    }

    /**
     * Grava as linhas em INSERTs de até MAX_INSERT_BYTES (uma linha maior sai sozinha).
     *
     * @param resource $gz
     */
    private function dumpRows($gz, string $table, string $ident): int
    {
        $prefix = null;
        $values = [];
        $size = 0;
        $rows = 0;
        foreach ($this->db->rowBatches($table, self::ROWS_PER_FETCH) as $batch) {
            foreach ($batch as $row) {
                if ($prefix === null) {
                    $columns = array_map(static fn ($c): string => PdoBackupDatabase::ident((string) $c), array_keys($row));
                    $prefix = 'INSERT INTO ' . $ident . ' (' . implode(',', $columns) . ') VALUES ';
                }
                $tuple = '(' . implode(',', array_map(fn ($v): string => $this->db->quote($v), array_values($row))) . ')';
                if ($values !== [] && $size + strlen($tuple) > self::MAX_INSERT_BYTES) {
                    $this->write($gz, $prefix . implode(',', $values) . ';');
                    $values = [];
                    $size = 0;
                }
                $values[] = $tuple;
                $size += strlen($tuple) + 1;
                $rows++;
            }
        }
        if ($values !== []) {
            $this->write($gz, $prefix . implode(',', $values) . ';');
        }

        return $rows;
    }

    /**
     * CREATE TABLE em uma linha só (o SHOW CREATE TABLE vem quebrado em linhas).
     */
    private static function oneLine(string $sql): string
    {
        return trim((string) preg_replace('/\s*\R\s*/', ' ', $sql));
    }

    /**
     * @param resource $gz
     */
    private function write($gz, string $line): void
    {
        if (strpbrk($line, "\r\n") !== false) {
            throw new \RuntimeException('Comando com quebra de linha no backup.');
        }
        if (gzwrite($gz, $line . "\n") === false) {
            throw new \RuntimeException('Falha ao gravar o arquivo de backup.');
        }
    }

    /**
     * @return \Generator<int, string>
     */
    private function lines(string $path): \Generator
    {
        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('Não foi possível abrir o arquivo de backup.');
        }
        try {
            while (($line = gzgets($gz)) !== false) {
                yield rtrim($line, "\r\n");
            }
        } finally {
            gzclose($gz);
        }
    }
}
