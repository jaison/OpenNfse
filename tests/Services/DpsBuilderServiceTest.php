<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\DpsBuilderService;
use OpenNfse\Services\DpsLayoutService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * Gera a DPS com a SDK real (vendor/ com os patches de patches/) e confere o XML.
 */
final class DpsBuilderServiceTest extends TestCase
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

    private function config(string $leiaute): array
    {
        return [
            'environment' => 'homologacao',
            'dps_leiaute' => $leiaute,
            'cnpj_emissor' => '12345678000195',
            'codigo_ibge' => '3550308',
            'serie_dps' => '1',
            'inscricao_municipal' => '1234567',
            'codigo_servico' => '010701',
            'nbs_padrao' => '115031000',
            'aliquota_iss' => '2.55',
            'prestador_informar_im' => '1',
            'prestador_op_simp_nac' => '3',
            'prestador_reg_ap_trib_sn' => '1',
            'prestador_reg_esp_trib' => '0',
        ];
    }

    private function buildXml(array $config, array $client, string $total, string $doc = '12345678909'): string
    {
        $dps = (new DpsBuilderService())->build(
            $config,
            ['id' => 10, 'total' => $total],
            [['type' => 'Other', 'relid' => 0, 'description' => 'Hospedagem']],
            $client,
            $doc,
            7
        );

        return (new \Nfse\Xml\DpsXmlBuilder())->build($dps);
    }

    private function clientePfSemEndereco(): array
    {
        return ['firstname' => 'Maria', 'lastname' => 'Silva', 'email' => 'maria@example.com', 'country' => 'BR'];
    }

    public function testNt009LayoutSendsFinNfseVersao101AndDescriptionAfterNbs(): void
    {
        $xml = $this->buildXml($this->config(DpsLayoutService::MODE_NT009), $this->clientePfSemEndereco(), '100.00');

        $this->assertStringContainsString('versao="1.01"', $xml);
        $this->assertMatchesRegularExpression('#<tpEmit>1</tpEmit><finNFSe>0</finNFSe><cLocEmi>#', $xml);
        $this->assertMatchesRegularExpression('#<cNBS>115031000</cNBS><xDescServ>#', $xml);
    }

    public function testPreNt009LayoutKeepsCurrentXml(): void
    {
        $config = $this->config(DpsLayoutService::MODE_PRE_NT009);
        $xml = $this->buildXml($config, $this->clientePfSemEndereco(), '100.00');

        $this->assertStringContainsString('versao="1.00"', $xml);
        $this->assertStringNotContainsString('finNFSe', $xml);
        $this->assertMatchesRegularExpression('#<tpEmit>1</tpEmit><cLocEmi>#', $xml);
        $this->assertMatchesRegularExpression('#</xDescServ><cNBS>115031000</cNBS>#', $xml);
    }

    public function testVServMoedaAlwaysHasTwoDecimals(): void
    {
        Capsule::$rows['tblcurrencies'] = [(object) ['code' => 'USD']];
        $cliente = [
            'companyname' => 'Acme Inc', 'email' => 'billing@acme.example', 'country' => 'US', 'currency' => 2,
            'address1' => '500 Market Street', 'city' => 'San Francisco', 'state' => 'CA', 'postcode' => '94105',
        ];

        foreach (['99.90' => '99.90', '250.00' => '250.00', '10.05' => '10.05'] as $total => $esperado) {
            $xml = $this->buildXml($this->config(DpsLayoutService::MODE_PRE_NT009), $cliente, $total, '');
            $this->assertStringContainsString('<vServMoeda>' . $esperado . '</vServMoeda>', $xml, "total $total");
        }
    }
}
