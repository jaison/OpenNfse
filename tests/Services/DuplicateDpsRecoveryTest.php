<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Helpers\NfseXmlExtractor;
use OpenNfse\Services\DuplicateDpsRecovery;
use PHPUnit\Framework\TestCase;

final class DuplicateDpsRecoveryTest extends TestCase
{
    private const ID_DPS = 'DPS420820324006645700011300900000000000002001';

    public function testDetectsE0014InApiMessage(): void
    {
        $msg = 'Resposta: {"idDPS":"' . self::ID_DPS . '","erros":[{"Codigo":"E0014","Descricao":"Conjunto de Série, Número ... já existe"}]}';
        $this->assertTrue(DuplicateDpsRecovery::isDuplicateDpsError($msg));
        $this->assertFalse(DuplicateDpsRecovery::isDuplicateDpsError('{"erros":[{"Codigo":"E00140"}]}'));
        $this->assertFalse(DuplicateDpsRecovery::isDuplicateDpsError('{"erros":[{"Codigo":"E2404"}]}'));
        $this->assertFalse(DuplicateDpsRecovery::isDuplicateDpsError(null));
    }

    public function testMatchesWhenSameDpsTomadorAndValue(): void
    {
        $this->assertNull(DuplicateDpsRecovery::mismatchReason(
            $this->nfse(self::ID_DPS, '<CNPJ>12345678000190</CNPJ>', '150.00'),
            self::ID_DPS,
            $this->dps(self::ID_DPS, '<CNPJ>12345678000190</CNPJ>', '150.00')
        ));
    }

    public function testMatchesWhenTomadorIsNotIdentifiedInBoth(): void
    {
        $this->assertNull(DuplicateDpsRecovery::mismatchReason(
            $this->nfse(self::ID_DPS, null, '99.9'),
            self::ID_DPS,
            $this->dps(self::ID_DPS, null, '99.90')
        ));
    }

    public function testRejectsOtherDps(): void
    {
        $other = 'DPS420820324006645700011300900000000000002002';
        $this->assertSame('a NFS-e encontrada é de outra DPS', DuplicateDpsRecovery::mismatchReason(
            $this->nfse($other, '<CNPJ>12345678000190</CNPJ>', '150.00'),
            self::ID_DPS,
            $this->dps(self::ID_DPS, '<CNPJ>12345678000190</CNPJ>', '150.00')
        ));
    }

    public function testRejectsOtherTomador(): void
    {
        $this->assertSame('o tomador da NFS-e encontrada é outro', DuplicateDpsRecovery::mismatchReason(
            $this->nfse(self::ID_DPS, '<CPF>12345678909</CPF>', '150.00'),
            self::ID_DPS,
            $this->dps(self::ID_DPS, '<CNPJ>12345678000190</CNPJ>', '150.00')
        ));
        $this->assertSame('o tomador da NFS-e encontrada é outro', DuplicateDpsRecovery::mismatchReason(
            $this->nfse(self::ID_DPS, null, '150.00'),
            self::ID_DPS,
            $this->dps(self::ID_DPS, '<CPF>12345678909</CPF>', '150.00')
        ));
    }

    public function testRejectsOtherValue(): void
    {
        $this->assertSame('o valor do serviço da NFS-e encontrada é outro', DuplicateDpsRecovery::mismatchReason(
            $this->nfse(self::ID_DPS, '<CNPJ>12345678000190</CNPJ>', '150.01'),
            self::ID_DPS,
            $this->dps(self::ID_DPS, '<CNPJ>12345678000190</CNPJ>', '150.00')
        ));
    }

    public function testPrestadorCnpjIsNotTakenAsTomador(): void
    {
        $this->assertNull(NfseXmlExtractor::extractTomadorDocumento($this->nfse(self::ID_DPS, null, '1.00')));
        $this->assertSame('CNPJ:12345678000190', NfseXmlExtractor::extractTomadorDocumento($this->dps(self::ID_DPS, '<CNPJ>12345678000190</CNPJ>', '1.00')));
    }

    public function testUnresolvedMessageNamesSerieAndNumber(): void
    {
        $msg = DuplicateDpsRecovery::unresolvedMessage(self::ID_DPS, 'o valor do serviço da NFS-e encontrada é outro', 'E0014');
        $this->assertStringContainsString('série 900, DPS nº 2001', $msg);
        $this->assertStringContainsString('Não reemita', $msg);
        $this->assertStringContainsString('Detalhe: E0014', $msg);
    }

    private function dps(string $idDps, ?string $tomador, string $valor): string
    {
        return '<DPS xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.00"><infDPS Id="' . $idDps . '">'
            . '<prest><CNPJ>40066457000113</CNPJ></prest>'
            . ($tomador !== null ? '<toma>' . $tomador . '<xNome>Cliente</xNome></toma>' : '')
            . '<valores><vServPrest><vServ>' . $valor . '</vServ></vServPrest></valores>'
            . '</infDPS></DPS>';
    }

    private function nfse(string $idDps, ?string $tomador, string $valor): string
    {
        return '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.00"><infNFSe Id="NFS4208203">'
            . '<nNFSe>123</nNFSe><emit><CNPJ>40066457000113</CNPJ></emit>'
            . $this->dps($idDps, $tomador, $valor)
            . '</infNFSe></NFSe>';
    }
}
