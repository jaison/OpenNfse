<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Helpers;

use OpenNfse\Helpers\NfseXmlExtractor;
use PHPUnit\Framework\TestCase;

final class NfseXmlExtractorTest extends TestCase
{
    private const XML_NFSE = <<<'XML'
<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse">
    <infNFSe Id="NFS123">
        <nNFSe>00000123</nNFSe>
        <DPS>
            <infDPS>
                <dCompet>2024-05-01</dCompet>
            </infDPS>
        </DPS>
        <dhEmi>2024-05-10T14:30:00-03:00</dhEmi>
    </infNFSe>
</NFSe>
XML;

    public function testExtractNumeroNfseFromNNFSe(): void
    {
        $this->assertSame('00000123', NfseXmlExtractor::extractNumeroNfse(self::XML_NFSE));
    }

    public function testExtractNumeroNfseFallsBackToNNFSeMun(): void
    {
        $xml = <<<'XML'
<NFSe>
    <infNFSe>
        <nNFSeMun>987</nNFSeMun>
    </infNFSe>
</NFSe>
XML;
        $this->assertSame('987', NfseXmlExtractor::extractNumeroNfse($xml));
    }

    public function testExtractNumeroNfseReturnsNullWhenAbsent(): void
    {
        $xml = '<NFSe><infNFSe></infNFSe></NFSe>';
        $this->assertNull(NfseXmlExtractor::extractNumeroNfse($xml));
    }

    public function testExtractCompetenciaWithFullDate(): void
    {
        $this->assertSame('2024-05-01', NfseXmlExtractor::extractCompetencia(self::XML_NFSE));
    }

    public function testExtractCompetenciaWithYearMonthOnly(): void
    {
        $xml = '<DPS><infDPS><dCompet>2024-05</dCompet></infDPS></DPS>';
        $this->assertSame('2024-05-01', NfseXmlExtractor::extractCompetencia($xml));
    }

    public function testExtractCompetenciaWithDateTime(): void
    {
        $xml = '<DPS><infDPS><dCompet>2024-05-01T10:00:00-03:00</dCompet></infDPS></DPS>';
        $this->assertSame('2024-05-01', NfseXmlExtractor::extractCompetencia($xml));
    }

    public function testExtractCompetenciaReturnsNullForInvalidFormat(): void
    {
        $xml = '<DPS><infDPS><dCompet>maio/2024</dCompet></infDPS></DPS>';
        $this->assertNull(NfseXmlExtractor::extractCompetencia($xml));
    }

    public function testExtractEmitidaEmFromDhEmi(): void
    {
        $this->assertSame('2024-05-10 14:30:00', NfseXmlExtractor::extractEmitidaEm(self::XML_NFSE));
    }

    public function testExtractEmitidaEmFallsBackToDEmi(): void
    {
        $xml = '<NFSe><infNFSe><dEmi>2024-05-10</dEmi></infNFSe></NFSe>';
        $this->assertSame('2024-05-10 00:00:00', NfseXmlExtractor::extractEmitidaEm($xml));
    }

    public function testExtractEmitidaEmReturnsNullWhenAbsent(): void
    {
        $xml = '<NFSe><infNFSe></infNFSe></NFSe>';
        $this->assertNull(NfseXmlExtractor::extractEmitidaEm($xml));
    }

    public function testReturnsNullForEmptyXml(): void
    {
        $this->assertNull(NfseXmlExtractor::extractNumeroNfse(''));
        $this->assertNull(NfseXmlExtractor::extractCompetencia('   '));
        $this->assertNull(NfseXmlExtractor::extractEmitidaEm(''));
    }

    public function testReturnsNullForInvalidXml(): void
    {
        $this->assertNull(NfseXmlExtractor::extractNumeroNfse('<not><valid'));
    }
}
