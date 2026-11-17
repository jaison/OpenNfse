<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\LocalNfsePdfGenerator;
use PHPUnit\Framework\TestCase;

/** DANFSe v2.0 (NT 008 v1.02) com dados fictícios. */
final class LocalNfsePdfGeneratorTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
        parent::tearDown();
    }

    private function xml(string $ibscbs = '', string $tomaEnd = ''): string
    {
        $tomaEnd = $tomaEnd !== '' ? $tomaEnd
            : '<end><endNac><cMun>4207502</cMun><CEP>89080000</CEP></endNac><xLgr>Rua Exemplo</xLgr><nro>10</nro><xBairro>Centro</xBairro></end>';

        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<NFSe versao="1.01" xmlns="http://www.sped.fazenda.gov.br/nfse">'
            . '<infNFSe Id="NFS42082032200000000000100000000000001261100000000001">'
            . '<xLocEmi>Itajaí</xLocEmi><xLocPrestacao>Itajaí</xLocPrestacao><nNFSe>1</nNFSe>'
            . '<cLocIncid>4208203</cLocIncid><xLocIncid>Itajaí</xLocIncid>'
            . '<xTribNac>Serviço de teste</xTribNac><ambGer>2</ambGer><tpEmis>1</tpEmis><cStat>100</cStat>'
            . '<dhProc>2026-11-16T10:00:00-03:00</dhProc>'
            . '<emit><CNPJ>00000000000191</CNPJ><IM>123</IM><xNome>PRESTADOR TESTE LTDA</xNome>'
            . '<enderNac><xLgr>Rua Um</xLgr><nro>1</nro><xCpl>SALA 2</xCpl><xBairro>Centro</xBairro>'
            . '<cMun>4208203</cMun><UF>SC</UF><CEP>88300000</CEP></enderNac></emit>'
            . '<valores><vLiq>10.00</vLiq></valores>'
            . '<DPS><infDPS Id="DPS1"><tpAmb>2</tpAmb><dhEmi>2026-11-16T09:59:00-03:00</dhEmi>'
            . '<serie>900</serie><nDPS>1</nDPS><dCompet>2026-11-16</dCompet><tpEmit>1</tpEmit><cLocEmi>4208203</cLocEmi>'
            . '<prest><CNPJ>00000000000191</CNPJ></prest>'
            . '<toma><CPF>00000000191</CPF><xNome>Tomador Teste</xNome>' . $tomaEnd . '</toma>'
            . '<serv><locPrest><cLocPrestacao>4208203</cLocPrestacao></locPrest>'
            . '<cServ><cTribNac>010301</cTribNac><xDescServ>Teste</xDescServ><cNBS>115061000</cNBS></cServ></serv>'
            . '<valores><vServPrest><vServ>10.00</vServ></vServPrest>'
            . '<trib><tribMun><tribISSQN>1</tribISSQN><tpRetISSQN>1</tpRetISSQN></tribMun>'
            . '<totTrib><vTotTrib><vTotTribFed>0.00</vTotTribFed><vTotTribEst>0.00</vTotTribEst><vTotTribMun>0.00</vTotTribMun></vTotTrib></totTrib></trib>'
            . '</valores>' . $ibscbs . '</infDPS></DPS></infNFSe></NFSe>';

        $file = tempnam(sys_get_temp_dir(), 'danfse');
        file_put_contents($file, $xml);
        $this->files[] = $file;

        return $file;
    }

    private function generator(): LocalNfsePdfGenerator
    {
        return new LocalNfsePdfGenerator(static fn (string $ibge): string => $ibge === '4207502' ? 'Indaial / SC' : $ibge);
    }

    public function testTomadorMunicipioComesFromItsOwnIbgeCodeNotFromIncidence(): void
    {
        $data = $this->generator()->parseXml($this->xml())->getData();

        self::assertSame('Indaial / SC', $data['tomador']['municipioUf']);
        self::assertSame('4207502 / 89.080-000', $data['tomador']['ibgeCep']);
    }

    public function testPrestadorKeepsMunicipalRegistrationAndAddressComplement(): void
    {
        $data = $this->generator()->parseXml($this->xml())->getData();

        self::assertSame('123', $data['prestador']['IM']);
        self::assertStringContainsString('SALA 2', $data['prestador']['endereco']);
    }

    public function testQrCodeUsesConsultaPublicaWithTpc(): void
    {
        self::assertSame(
            'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=123',
            LocalNfsePdfGenerator::consultaPublicaUrl('123')
        );
    }

    public function testNbsIsFormattedLikeTheOfficialGenerator(): void
    {
        self::assertSame('1.1506.10.00', LocalNfsePdfGenerator::formatNbs('115061000'));
        self::assertSame('', LocalNfsePdfGenerator::formatNbs(''));
        self::assertSame('123', LocalNfsePdfGenerator::formatNbs('123'));
    }

    public function testWithoutIbsCbsGroupDestinatarioIsNotIdentified(): void
    {
        $data = $this->generator()->parseXml($this->xml())->getData();

        self::assertFalse($data['destinatarioPresent']);
        self::assertFalse($data['ibscbsPresent']);
    }

    public function testDestinatarioEqualToTomadorIsDetected(): void
    {
        $ibscbs = '<IBSCBS><finNFSe>0</finNFSe><indFinal>0</indFinal><cIndOp>100301</cIndOp><indDest>1</indDest>'
            . '<dest><CPF>00000000191</CPF><xNome>Tomador Teste</xNome></dest></IBSCBS>';
        $data = $this->generator()->parseXml($this->xml($ibscbs))->getData();

        self::assertTrue($data['destinatarioPresent']);
        self::assertTrue($data['destinatarioEhTomador']);
    }

    private function call(LocalNfsePdfGenerator $g, string $method, ...$args)
    {
        $r = new \ReflectionMethod($g, $method);
        $r->setAccessible(true);

        return $r->invoke($g, ...$args);
    }

    public function testValuesFollowTheOfficialGeneratorFormatting(): void
    {
        $g = $this->generator();

        self::assertSame('R$ 1.234,50', $this->call($g, 'brl', 1234.5));
        self::assertSame('-', $this->call($g, 'moneyOrDash', null));
        self::assertSame('-', $this->call($g, 'moneyOrDash', 0.0));
        self::assertSame('R$ 10,00', $this->call($g, 'moneyOrDash', 10.0));
        self::assertSame('-', $this->call($g, 'pctOrDash', 0.0));
        self::assertSame('2,00%', $this->call($g, 'pctOrDash', 2.0));
        self::assertSame('2,00', $this->call($g, 'numPctOrDash', 2.0));
        self::assertSame('-', $this->call($g, 'joinValues', '-', '', '-'));
        self::assertSame('100301 / - / Itajaí', $this->call($g, 'joinValues', '100301', '', 'Itajaí'));
    }

    public function testDescriptionsMatchTheOfficialGenerator(): void
    {
        $g = $this->generator();

        self::assertSame('NFS-e Regular', $this->call($g, 'mapCStat', '100'));
        self::assertSame('-', $this->call($g, 'mapFinNFSe', ''));
        self::assertSame('3 - Optante - ME/EPP', $this->call($g, 'mapOpSimpNac', '3'));
        self::assertSame('Nenhum', $this->call($g, 'mapRegEspTrib', '0'));
        self::assertSame('Operação Tributável', $this->call($g, 'mapTribISSQN', '1'));
        self::assertSame('Sistema Nacional da NFS-e', $this->call($g, 'mapAmbGer', '2'));
    }

    public function testComplementaryInfoTotalsLineMatchesTheOfficialText(): void
    {
        $g = $this->generator()->parseXml($this->xml());

        self::assertSame(
            'Totais aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: R$ 0,00; Estaduais: R$ 0,00; Municipais: R$ 0,00;',
            $this->call($g, 'buildTotaisAproximadosLine')
        );
    }

    public function testWithoutIbsCbsFinalidadeIsDash(): void
    {
        $data = $this->generator()->parseXml($this->xml())->getData();

        self::assertSame('', $data['finNFSe']);
        self::assertFalse($data['ibsNfsePresent']);
    }

    public function testPdfIsGeneratedOnASinglePage(): void
    {
        $pdf = $this->generator()->parseXml($this->xml())->generate();

        self::assertSame(1, $pdf->getNumPages());
        self::assertStringStartsWith('%PDF-', (string) $pdf->Output('', 'S'));
    }
}
