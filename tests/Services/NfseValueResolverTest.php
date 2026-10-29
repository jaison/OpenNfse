<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\DpsBuilderService;
use OpenNfse\Services\DpsLayoutService;
use OpenNfse\Services\NfseValueResolver as R;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class NfseValueResolverTest extends TestCase
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

    private function massPayItems(): array
    {
        return [
            ['type' => 'Invoice', 'relid' => 101, 'amount' => '60.00', 'description' => 'Fatura #101'],
            ['type' => 'Invoice', 'relid' => 102, 'amount' => '40.00', 'description' => 'Fatura #102'],
        ];
    }

    public function testCommonInvoiceKeepsValueAndItems(): void
    {
        $items = [['type' => 'Hosting', 'relid' => 5, 'amount' => '99.90', 'description' => 'Hospedagem']];
        $r = R::compute(['total' => '99.90'], $items, [101]);

        $this->assertSame(99.9, $r['value']);
        $this->assertSame($items, $r['items']);
        $this->assertSame([], $r['excluded_children']);
        $this->assertFalse($r['partial']);
    }

    public function testMassPayWithoutIssuedChildIsLikeBefore(): void
    {
        $r = R::compute(['total' => '100.00'], $this->massPayItems(), []);

        $this->assertSame(100.0, $r['value']);
        $this->assertSame($this->massPayItems(), $r['items']);
        $this->assertFalse($r['partial']);
    }

    public function testPartialExcludesIssuedChild(): void
    {
        $r = R::compute(['total' => '100.00'], $this->massPayItems(), [101]);

        $this->assertSame(40.0, $r['value']);
        $this->assertSame(100.0, $r['base']);
        $this->assertSame([101], $r['excluded_children']);
        $this->assertSame([$this->massPayItems()[1]], $r['items']);
        $this->assertTrue($r['partial']);
    }

    public function testAllChildrenIssuedGivesZero(): void
    {
        $r = R::compute(['total' => '100.00'], $this->massPayItems(), [101, 102]);

        $this->assertSame(0.0, $r['value']);
        $this->assertSame([], $r['items']);
        $this->assertFalse($r['partial']);
    }

    public function testCoverageFlagDistinguishesCreditZeroingFromAllCovered(): void
    {
        // total de M já vem líquido do crédito: 100 em itens, 40 de crédito, total 60.
        $creditZero = R::compute(['total' => '60.00', 'credit' => '40.00'], $this->massPayItems(), [101]);
        $this->assertSame(0.0, $creditZero['value']);
        $this->assertFalse($creditZero['all_children_covered']);
        $this->assertSame(
            \OpenNfse\Services\EmissionPolicyService::CODE_MASS_PAY_NO_REMAINING_VALUE,
            \OpenNfse\Services\EmissionPolicyService::massPayBlock($creditZero)[0]
        );

        $allCovered = R::compute(['total' => '100.00'], $this->massPayItems(), [101, 102]);
        $this->assertTrue($allCovered['all_children_covered']);
        $this->assertSame(
            \OpenNfse\Services\EmissionPolicyService::CODE_MASS_PAY_CHILD_ALREADY_ISSUED,
            \OpenNfse\Services\EmissionPolicyService::massPayBlock($allCovered)[0]
        );

        // Mesmo caminho da fila: resolve() lendo as notas.
        Capsule::$rows['mod_opennfse_notas'] = [(object) ['invoiceid' => 101, 'status' => 'PROCESSANDO']];
        $queuePath = (new R())->resolve(['id' => 500, 'total' => '60.00', 'credit' => '40.00'], $this->massPayItems());
        $this->assertSame(
            \OpenNfse\Services\EmissionPolicyService::CODE_MASS_PAY_NO_REMAINING_VALUE,
            \OpenNfse\Services\EmissionPolicyService::massPayBlock($queuePath)[0]
        );
    }

    public function testLateFeeStaysInValueAndDescription(): void
    {
        $items = array_merge($this->massPayItems(), [['type' => 'LateFee', 'relid' => 0, 'amount' => '5.00', 'description' => 'Multa']]);
        $r = R::compute(['total' => '105.00'], $items, [101]);

        $this->assertSame(45.0, $r['value']);
        $this->assertSame(['Fatura #102', 'Multa'], array_column($r['items'], 'description'));
        $this->assertTrue($r['partial']);
    }

    public function testCentsAndNeverNegative(): void
    {
        $items = [
            ['type' => 'Invoice', 'relid' => 1, 'amount' => '33.33'],
            ['type' => 'Invoice', 'relid' => 2, 'amount' => '33.33'],
            ['type' => 'Invoice', 'relid' => 3, 'amount' => '33.35'],
        ];
        $this->assertSame(33.35, R::compute(['total' => '100.01'], $items, [1, 2])['value']);
        $this->assertSame(0.01, R::compute(['total' => '0.10'], [
            ['type' => 'Invoice', 'relid' => 1, 'amount' => '0.07'],
            ['type' => 'Invoice', 'relid' => 2, 'amount' => '0.02'],
            ['type' => 'Invoice', 'relid' => 3, 'amount' => '0.01'],
        ], [1, 2])['value']);

        // Itens das filhas com nota somando mais que o total (ex.: crédito aplicado em M): zero, nunca negativo.
        $this->assertSame(0.0, R::compute(['total' => '50.00'], $this->massPayItems(), [101])['value']);
    }

    public function testResolveRecalculatesWhenChildGetsNotaLater(): void
    {
        $resolver = new R();
        $invoice = ['id' => 500, 'total' => '100.00'];

        Capsule::$rows['mod_opennfse_notas'] = [];
        $this->assertSame(100.0, $resolver->resolve($invoice, $this->massPayItems())['value'], 'No enfileiramento, nenhuma filha com nota.');

        Capsule::$rows['mod_opennfse_notas'] = [(object) ['invoiceid' => 101, 'status' => 'EMITIDA']];
        $this->assertSame(40.0, $resolver->resolve($invoice, $this->massPayItems())['value'], 'Na emissão, C1 já tem nota.');
    }

    private function builderXml(array $items, string $total): string
    {
        $config = [
            'environment' => 'homologacao', 'dps_leiaute' => DpsLayoutService::MODE_PRE_NT009,
            'cnpj_emissor' => '12345678000195', 'codigo_ibge' => '3550308', 'serie_dps' => '1',
            'inscricao_municipal' => '1234567', 'codigo_servico' => '010701', 'nbs_padrao' => '115031000',
            'aliquota_iss' => '2.55', 'prestador_informar_im' => '1', 'prestador_op_simp_nac' => '3',
            'prestador_reg_ap_trib_sn' => '1', 'prestador_reg_esp_trib' => '0',
        ];
        $client = ['firstname' => 'Maria', 'lastname' => 'Silva', 'email' => 'maria@example.com', 'country' => 'BR'];
        $dps = (new DpsBuilderService())->build($config, ['id' => 500, 'total' => $total], $items, $client, '12345678909', 7);

        return (string) preg_replace('#<dhEmi>[^<]*</dhEmi>#', '', (new \Nfse\Xml\DpsXmlBuilder())->build($dps));
    }

    public function testDpsOfPartialMassPayUsesRemainingValueAndChildren(): void
    {
        Capsule::$rows['mod_opennfse_notas'] = [(object) ['invoiceid' => 101, 'status' => 'EMITIDA']];
        $xml = $this->builderXml($this->massPayItems(), '100.00');

        $this->assertStringContainsString('<vServ>40.00</vServ>', $xml);
        $this->assertStringContainsString('Fatura #102', $xml);
        $this->assertStringNotContainsString('Fatura #101', $xml);
    }

    public function testDpsOfNormalMassPayIsLikeBefore(): void
    {
        Capsule::$rows['mod_opennfse_notas'] = [];
        $xml = $this->builderXml($this->massPayItems(), '100.00');

        $this->assertStringContainsString('<vServ>100.00</vServ>', $xml);
        $this->assertStringContainsString('Fatura #101 | Fatura #102', $xml);
    }
}
