<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Api;

use OpenNfse\Api\NfsePhpSdkAdapter;
use PHPUnit\Framework\TestCase;

final class NfsePhpSdkAdapterChaveTest extends TestCase
{
    public function testChaveVemDoIdDeInfNfseSemOPrefixoNfs(): void
    {
        $chave = str_repeat('3', 50);
        $nfse = (object) [
            'infNfse' => (object) ['id' => 'NFS' . $chave],
            'nfseXml' => '<NFSe><infNFSe Id="NFS' . str_repeat('4', 50) . '"/></NFSe>',
        ];

        $this->assertSame($chave, NfsePhpSdkAdapter::chaveAcessoDaEmissao($nfse));
    }

    public function testChaveCaiNoIdDoXmlQuandoODtoNaoTem50Digitos(): void
    {
        $chave = str_repeat('8', 50);
        $nfse = (object) [
            'infNfse' => (object) ['id' => 'NFS123'],
            'nfseXml' => '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe Id="NFS' . $chave . '"></infNFSe></NFSe>',
        ];

        $this->assertSame($chave, NfsePhpSdkAdapter::chaveAcessoDaEmissao($nfse));
    }

    public function testChaveNulaQuandoNenhumCaminhoTem50Digitos(): void
    {
        $nfse = (object) [
            'infNfse' => (object) ['id' => null],
            'nfseXml' => '<NFSe><infNFSe Id="NFS123"></infNFSe></NFSe>',
        ];

        $this->assertNull(NfsePhpSdkAdapter::chaveAcessoDaEmissao($nfse));
        $this->assertNull(NfsePhpSdkAdapter::chaveAcessoDaEmissao((object) []));
    }
}
