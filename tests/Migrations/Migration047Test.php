<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Migrations;

use OpenNfse\Migrations\Migrator;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class Migration047Test extends TestCase
{
    private const FILE = '/migrations/047_fix_cancelamento_herdado_reemissao.sql';

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::reset();
        Migrator::resetForTests();
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        Migrator::resetForTests();
        parent::tearDown();
    }

    /**
     * @return array<int, string>
     */
    private function statements(): array
    {
        $sql = trim((string) file_get_contents(dirname(__DIR__, 2) . self::FILE));

        return array_values(array_filter(array_map(static fn (string $s): string => rtrim(trim($s), ';'), preg_split('/;\s*[\r\n]+/', $sql) ?: [])));
    }

    public function testOnlyUpdatesWithoutDestructiveCommands(): void
    {
        $statements = $this->statements();
        $this->assertCount(4, $statements);
        foreach ($statements as $stmt) {
            $this->assertStringStartsWith('UPDATE ', $stmt);
            foreach (['DROP ', 'DELETE ', 'ALTER ', 'INSERT ', 'TRUNCATE '] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, strtoupper($stmt));
            }
        }
    }

    public function testNeverTouchesCancelledRowsOrCancellationRecords(): void
    {
        [$notaFull, $notaXml, $historyFull, $historyXml] = $this->statements();

        foreach ([$notaFull, $notaXml] as $stmt) {
            $this->assertStringStartsWith('UPDATE mod_opennfse_notas n SET', $stmt);
            $this->assertStringContainsString("n.status <> 'CANCELADA'", $stmt);
            // Só limpa se o cancelamento estiver no histórico com outra chave.
            $this->assertStringContainsString("h.tipo_registro = 'CANCELAMENTO'", $stmt);
            $this->assertStringContainsString("h.chave_acesso <> COALESCE(n.chave_acesso, '')", $stmt);
        }

        foreach ([$historyFull, $historyXml] as $stmt) {
            $this->assertStringStartsWith('UPDATE mod_opennfse_notas_history e INNER JOIN mod_opennfse_notas_history c', $stmt);
            $this->assertStringContainsString("WHERE e.tipo_registro = 'EMISSAO'", $stmt);
            $this->assertStringContainsString("c.tipo_registro = 'CANCELAMENTO'", $stmt);
            $this->assertStringContainsString("c.chave_acesso <> COALESCE(e.chave_acesso, '')", $stmt);
            $this->assertStringNotContainsString('SET c.', $stmt);
        }
    }

    public function testIsIdempotentBecauseEachStatementClearsTheFieldItMatchesOn(): void
    {
        [$notaFull, $notaXml, $historyFull, $historyXml] = $this->statements();

        // Depois de rodar, o campo usado no casamento fica NULL e a linha não casa de novo.
        $this->assertStringContainsString('n.cancelado_em IS NOT NULL', $notaFull);
        $this->assertStringContainsString('h.cancelado_em = n.cancelado_em', $notaFull);
        $this->assertStringContainsString('SET n.cancelado_em = NULL', $notaFull);

        $this->assertStringContainsString('h.cancel_xml_path = n.cancel_xml_path', $notaXml);
        $this->assertStringContainsString('SET n.cancel_xml_path = NULL WHERE', $notaXml);
        // Falha de cancelamento da nota nova (cancelado_em NULL) mantém motivo e erro.
        $this->assertStringNotContainsString('cancel_erro', $notaXml);

        $this->assertStringContainsString('c.cancelado_em = e.cancelado_em', $historyFull);
        $this->assertStringContainsString('SET e.cancelado_em = NULL', $historyFull);

        $this->assertStringContainsString('c.cancel_xml_path = e.cancel_xml_path', $historyXml);
        $this->assertStringContainsString('SET e.cancel_xml_path = NULL WHERE', $historyXml);
        $this->assertStringNotContainsString('cancel_erro', $historyXml);
    }

    public function testMigratorRunsEachStatementSeparately(): void
    {
        (new Migrator())->up();

        $ran = array_values(array_filter(
            Capsule::$statements,
            static fn (string $sql): bool => str_contains($sql, 'cancel_xml_path = NULL')
        ));
        $this->assertCount(4, $ran);
        $this->assertSame($this->statements(), array_map(static fn (string $s): string => rtrim($s, ';'), $ran));
    }
}
