<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Updater;

use OpenNfse\Updater\BackupDatabase;
use OpenNfse\Updater\TableBackup;
use PHPUnit\Framework\TestCase;

final class TableBackupTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/opennfse-backup-test-' . bin2hex(random_bytes(4)) . '.sql.gz';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testDumpSkipsUpdaterTablesAndRestoresEveryStatement(): void
    {
        $db = $this->fakeDb();
        $info = (new TableBackup($db))->dump($this->file, '1.0.0');

        $this->assertSame(['mod_opennfse_config', 'mod_opennfse_logs'], $info['tables']);
        $this->assertSame(3, $info['rows']);
        $this->assertSame(hash_file('sha256', $this->file), $info['sha256']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->file)), -4));

        $count = (new TableBackup($db))->restore($this->file, $info['sha256']);

        $executed = $db->executed;
        // SET NAMES, FK=0, (DROP, CREATE, INSERT) x 2 tabelas, FK=1.
        $this->assertSame(9, $count);
        $this->assertSame('SET NAMES utf8mb4', $executed[0]);
        $this->assertSame('DROP TABLE IF EXISTS `mod_opennfse_config`', $executed[2]);
        $this->assertStringStartsWith('CREATE TABLE `mod_opennfse_config` ( `id` int', $executed[3]);
        $this->assertStringContainsString("'linha 1\\nlinha 2; com ponto e vírgula'", implode("\n", $executed));
        $this->assertStringContainsString('NULL', $executed[4]);
        foreach ($executed as $sql) {
            $this->assertStringNotContainsString('mod_opennfse_updater', $sql);
        }
    }

    public function testLargeTablesAreSplitIntoSeveralInserts(): void
    {
        $db = $this->fakeDb();
        $big = str_repeat('x', 400000);
        $db->rows['mod_opennfse_logs'] = [
            ['id' => 1, 'msg' => $big],
            ['id' => 2, 'msg' => $big],
            ['id' => 3, 'msg' => $big],
        ];
        $info = (new TableBackup($db))->dump($this->file, '1.0.0');
        (new TableBackup($db))->restore($this->file, $info['sha256']);

        $inserts = array_filter($db->executed, static fn (string $s): bool => str_starts_with($s, 'INSERT INTO `mod_opennfse_logs`'));
        $this->assertCount(2, $inserts);
    }

    public function testRestoreRejectsOtherOrTruncatedFile(): void
    {
        $db = $this->fakeDb();
        $info = (new TableBackup($db))->dump($this->file, '1.0.0');

        try {
            (new TableBackup($db))->restore($this->file, str_repeat('0', 64));
            $this->fail('Aceitou SHA-256 diferente.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('não é o que foi gerado', $e->getMessage());
        }

        // Backup cortado no meio: o hash bate (foi registrado assim), mas falta o fim.
        $lines = explode("\n", (string) gzdecode((string) file_get_contents($this->file)));
        file_put_contents($this->file, gzencode(implode("\n", array_slice($lines, 0, 4)) . "\n"));
        try {
            (new TableBackup($db))->restore($this->file, (string) hash_file('sha256', $this->file));
            $this->fail('Aceitou backup incompleto.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('incompleto', $e->getMessage());
        }
        $this->assertSame([], $db->executed);
        $this->assertNotSame('', $info['sha256']);
    }

    private function fakeDb(): BackupDatabase
    {
        return new class implements BackupDatabase {
            public array $executed = [];
            public array $rows = [
                'mod_opennfse_config' => [['id' => 1, 'nome' => "linha 1\nlinha 2; com ponto e vírgula", 'vazio' => null]],
                'mod_opennfse_logs' => [['id' => 1, 'msg' => "O'Brien"], ['id' => 2, 'msg' => 'ok']],
                'mod_opennfse_updater' => [['id' => 1, 'paused' => 1]],
                'mod_opennfse_updater_events' => [],
            ];

            public function tablesWithPrefix(string $prefix): array
            {
                $tables = array_keys($this->rows);
                sort($tables);

                return $tables;
            }

            public function createStatement(string $table): string
            {
                return "CREATE TABLE `$table` (\n  `id` int NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB";
            }

            public function rowBatches(string $table, int $batchSize): iterable
            {
                foreach (array_chunk($this->rows[$table], $batchSize) as $chunk) {
                    yield $chunk;
                }
            }

            public function quote(mixed $value): string
            {
                if ($value === null) {
                    return 'NULL';
                }
                if (is_int($value)) {
                    return (string) $value;
                }

                // Mesmo escape do MySQL (mysql_real_escape_string).
                return "'" . strtr((string) $value, ["\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "'" => "\\'", '"' => '\\"', "\x1a" => "\\Z"]) . "'";
            }

            public function exec(string $sql): void
            {
                $this->executed[] = $sql;
            }
        };
    }
}
