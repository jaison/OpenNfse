<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\CompraGovernamental;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class CompraGovernamentalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Capsule::reset();
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        parent::tearDown();
    }

    public function testParseDoCampoPersonalizado(): void
    {
        $this->assertNull(CompraGovernamental::parseEnte(null));
        $this->assertNull(CompraGovernamental::parseEnte(''));
        $this->assertNull(CompraGovernamental::parseEnte('   '));
        $this->assertNull(CompraGovernamental::parseEnte('0'));
        $this->assertNull(CompraGovernamental::parseEnte('5'));
        $this->assertNull(CompraGovernamental::parseEnte('14'));
        $this->assertNull(CompraGovernamental::parseEnte('Município'));
        $this->assertSame(1, CompraGovernamental::parseEnte('1'));
        $this->assertSame(2, CompraGovernamental::parseEnte('2'));
        $this->assertSame(3, CompraGovernamental::parseEnte('3'));
        $this->assertSame(4, CompraGovernamental::parseEnte('4'));
        $this->assertSame(4, CompraGovernamental::parseEnte(' 4 - Município '));
        $this->assertSame(1, CompraGovernamental::parseEnte('1-União'));
    }

    public function testTpOperPadraoEValidos(): void
    {
        $this->assertSame(5, CompraGovernamental::tpOper([]));
        $this->assertSame(1, CompraGovernamental::tpOper(['compra_gov_tpoper' => '1']));
        $this->assertSame(4, CompraGovernamental::tpOper(['compra_gov_tpoper' => '4']));
        $this->assertSame(5, CompraGovernamental::tpOper(['compra_gov_tpoper' => '2']));
        $this->assertSame(5, CompraGovernamental::tpOper(['compra_gov_tpoper' => '3']));
        $this->assertSame([], CompraGovernamental::validateConfig('5', ''));
        $this->assertSame([], CompraGovernamental::validateConfig('1', '12'));
        $this->assertNotEmpty(CompraGovernamental::validateConfig('2', '12'));
        $this->assertNotEmpty(CompraGovernamental::validateConfig('5', 'abc'));
    }

    public function testLeOValorDoCustomFieldQuandoOClienteNaoTrazAChave(): void
    {
        Capsule::$rows['tblcustomfieldsvalues'] = [(object) ['value' => '2 - Estado']];
        $ente = CompraGovernamental::ente(
            ['compra_gov_customfield_id' => 9],
            ['id' => 4]
        );
        $this->assertSame(2, $ente);

        $anexado = (new CompraGovernamental())->withValor(
            ['compra_gov_customfield_id' => 9],
            ['id' => 4],
            new \OpenNfse\Repositories\WhmcsCustomerRepository()
        );
        $this->assertSame('2 - Estado', $anexado[CompraGovernamental::CLIENT_KEY]);
    }

    public function testMigration(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/055_alter_config_compra_gov.sql');
        $this->assertStringContainsString('compra_gov_customfield_id INT NULL', $sql);
        $this->assertStringContainsString("compra_gov_tpoper VARCHAR(1) NOT NULL DEFAULT '5'", $sql);
    }
}
