<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\DpsBuilderService;
use OpenNfse\Services\DpsLayoutService;
use OpenNfse\Services\TomadorBairroService;
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

    private function clienteComEndereco(string $address1, string $address2 = '', array $extra = []): array
    {
        return $this->clientePfSemEndereco() + $extra + [
            'address1' => $address1, 'address2' => $address2,
            'city' => 'São Paulo', 'state' => 'SP', 'postcode' => '01310-100',
        ];
    }

    private function configComPadroes(string $origemBairro = ''): array
    {
        $config = $this->config(DpsLayoutService::MODE_NT009) + [
            'tomador_numero_padrao' => 'S/N',
            'tomador_bairro_padrao' => 'CENTRO',
            'tomador_codigo_ibge_padrao' => '3550308',
        ];
        if ($origemBairro !== '') {
            $config['tomador_bairro_origem'] = $origemBairro;
            $config['tomador_bairro_customfield_id'] = 9;
        }

        return $config;
    }

    private function enderecoNacional(string $address1, string $address2 = '', string $origemBairro = '', array $extra = []): string
    {
        $xml = $this->buildXml(
            $this->configComPadroes($origemBairro),
            $this->clienteComEndereco($address1, $address2, $extra),
            '100.00'
        );
        return $this->enderecoTomador($xml);
    }

    private function enderecoTomador(string $xml): string
    {
        $this->assertMatchesRegularExpression('#<toma>.*?<end>(.*?)</end>#s', $xml);
        preg_match('#<toma>.*?<end>(.*?)</end>#s', $xml, $m);
        return $m[1];
    }

    private function bairroCustomField(string $valor): array
    {
        return [TomadorBairroService::CLIENT_KEY => $valor];
    }

    public function testExemploDaIssueComEndereco2ComoBairro(): void
    {
        $end = $this->enderecoNacional('RUA ANTÔNIO MONTEIRO DE REZENDE, 541', 'limoeiro');

        $this->assertStringContainsString(
            '<xLgr>RUA ANTÔNIO MONTEIRO DE REZENDE</xLgr><nro>541</nro><xBairro>limoeiro</xBairro>',
            $end
        );
        $this->assertStringNotContainsString('<xCpl>', $end);
    }

    public function testOrigemPadraoEhEndereco2MesmoComValorDesconhecido(): void
    {
        $end = $this->enderecoNacional('RUA X, 10', 'Jardim América', 'qualquer');
        $this->assertStringContainsString('<xBairro>Jardim América</xBairro>', $end);
        $this->assertStringNotContainsString('<xCpl>', $end);
    }

    public function testEndereco2VazioUsaBairroPadrao(): void
    {
        $end = $this->enderecoNacional('RUA X, 10', '   ', TomadorBairroService::ORIGEM_ADDRESS2);
        $this->assertStringContainsString('<xBairro>CENTRO</xBairro>', $end);
    }

    public function testModoEndereco2RestoDaLinhaVaiSozinhoParaOComplemento(): void
    {
        $end = $this->enderecoNacional('AV. BRASIL, 1500, SALA 3', 'Bela Vista');
        $this->assertStringContainsString(
            '<xLgr>AV. BRASIL</xLgr><nro>1500</nro><xCpl>SALA 3</xCpl><xBairro>Bela Vista</xBairro>',
            $end
        );
    }

    public function testModoCustomFieldBairroDoCampoEEndereco2NoComplemento(): void
    {
        $end = $this->enderecoNacional(
            'AV. BRASIL, 1500, SALA 3',
            'Bloco B',
            TomadorBairroService::ORIGEM_CUSTOMFIELD,
            $this->bairroCustomField(' Bela Vista ')
        );
        $this->assertStringContainsString(
            '<xLgr>AV. BRASIL</xLgr><nro>1500</nro><xCpl>SALA 3 - Bloco B</xCpl><xBairro>Bela Vista</xBairro>',
            $end
        );

        $end = $this->enderecoNacional(
            'RUA ANTÔNIO MONTEIRO DE REZENDE, 541',
            'limoeiro',
            TomadorBairroService::ORIGEM_CUSTOMFIELD,
            $this->bairroCustomField('Vila Nova')
        );
        $this->assertStringContainsString('<nro>541</nro><xCpl>limoeiro</xCpl><xBairro>Vila Nova</xBairro>', $end);
    }

    public function testModoCustomFieldVazioUsaBairroPadrao(): void
    {
        $end = $this->enderecoNacional('RUA X, 10', 'Casa 2', TomadorBairroService::ORIGEM_CUSTOMFIELD, $this->bairroCustomField(''));
        $this->assertStringContainsString('<xCpl>Casa 2</xCpl><xBairro>CENTRO</xBairro>', $end);

        $end = $this->enderecoNacional('RUA X, 10', 'Casa 2', TomadorBairroService::ORIGEM_CUSTOMFIELD);
        $this->assertStringContainsString('<xCpl>Casa 2</xCpl><xBairro>CENTRO</xBairro>', $end);
    }

    public function testNumeroNoNomeDaRuaNaoViraNumeroDoImovel(): void
    {
        $end = $this->enderecoNacional('RUA 7 DE SETEMBRO, 100');
        $this->assertStringContainsString('<xLgr>RUA 7 DE SETEMBRO</xLgr><nro>100</nro>', $end);

        $end = $this->enderecoNacional('RUA 7 DE SETEMBRO');
        $this->assertStringContainsString('<xLgr>RUA 7 DE SETEMBRO</xLgr><nro>S/N</nro>', $end);
        $this->assertStringNotContainsString('<xCpl>', $end);
    }

    public function testBairroEComplementoRespeitamTamanhoMaximo(): void
    {
        $end = $this->enderecoNacional('AV. BRASIL, 1500, SALA 3', str_repeat('ç', 200));
        preg_match('#<xBairro>(.*?)</xBairro>#u', $end, $m);
        $this->assertSame(60, mb_strlen($m[1]));

        $end = $this->enderecoNacional(
            'AV. BRASIL, 1500, SALA 3',
            str_repeat('ç', 200),
            TomadorBairroService::ORIGEM_CUSTOMFIELD,
            $this->bairroCustomField('Centro')
        );
        preg_match('#<xCpl>(.*?)</xCpl>#u', $end, $m);
        $this->assertSame(156, mb_strlen($m[1]));
    }

    private function clienteExterior(array $extra = []): array
    {
        return $extra + [
            'companyname' => 'Acme Inc', 'email' => 'billing@acme.example', 'country' => 'US', 'currency' => 2,
            'address1' => 'Market Street, 500, Suite 12', 'address2' => 'SoMa',
            'city' => 'San Francisco', 'state' => 'CA', 'postcode' => '94105',
        ];
    }

    public function testTomadorExteriorRecebeOMesmoTratamento(): void
    {
        Capsule::$rows['tblcurrencies'] = [(object) ['code' => 'USD']];

        $end = $this->enderecoTomador($this->buildXml($this->configComPadroes(), $this->clienteExterior(), '100.00', ''));
        $this->assertStringContainsString(
            '<xLgr>Market Street</xLgr><nro>500</nro><xCpl>Suite 12</xCpl><xBairro>SoMa</xBairro>',
            $end
        );

        $config = $this->configComPadroes(TomadorBairroService::ORIGEM_CUSTOMFIELD);
        $end = $this->enderecoTomador($this->buildXml($config, $this->clienteExterior(['address2' => 'Floor 3']), '100.00', ''));
        $this->assertStringContainsString(
            '<xLgr>Market Street</xLgr><nro>500</nro><xCpl>Suite 12 - Floor 3</xCpl><xBairro>CENTRO</xBairro>',
            $end
        );
    }
}
