<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Helpers;

use OpenNfse\Helpers\ClientErrorMessage;
use PHPUnit\Framework\TestCase;

final class ClientErrorMessageTest extends TestCase
{
    public function testEmptyErrorShowsNothing(): void
    {
        $this->assertSame('', ClientErrorMessage::forClient(''));
        $this->assertSame('', ClientErrorMessage::forClient('   '));
        $this->assertSame('', ClientErrorMessage::forClient(null));
    }

    public function testTechnicalErrorsBecomeGeneric(): void
    {
        $erros = [
            'SSL certificate not found: /home/conta/certs/empresa.pfx',
            'Certificado não encontrado: C:\\certs\\empresa.pfx',
            'cURL error 28: Operation timed out after 10001 milliseconds',
            "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x' in 'field list'",
            "RuntimeException: falha\n#0 /var/www/whmcs/modules/addons/OpenNfse/src/Services/NfseService.php(120)",
        ];

        foreach ($erros as $erro) {
            $msg = ClientErrorMessage::forClient($erro);
            $this->assertSame(ClientErrorMessage::GENERIC, $msg, $erro);
            $this->assertStringNotContainsString('pfx', $msg);
        }
    }

    public function testSefinRejectionKeepsOnlyCodeAndDescription(): void
    {
        $erro = 'Rejeição de leiaute da DPS (E1235): ajuste em Configurações. Detalhe: Erro na emissão: '
            . '[{"Codigo":"E1235","Descricao":"Falha Schema Xml",'
            . '"Complemento":"The element \'infDPS\' has invalid child element \'cLocEmi\'."}]';

        $msg = ClientErrorMessage::forClient($erro);

        $this->assertSame(
            'NFS-e rejeitada pela SEFIN: E1235 - Falha Schema Xml. Entre em contato com o suporte.',
            $msg
        );
        $this->assertStringNotContainsString('infDPS', $msg);
        $this->assertStringNotContainsString('Configurações', $msg);
    }

    public function testSefinRejectionInNestedObject(): void
    {
        $erro = 'Erro na requisição: {"erro":{"codigo":"E2404",'
            . '"descricao":"Não foi gerada uma NFS-e com o identificador de DPS informado"}}';

        $this->assertSame(
            'NFS-e rejeitada pela SEFIN: E2404 - Não foi gerada uma NFS-e com o identificador de DPS informado. '
            . 'Entre em contato com o suporte.',
            ClientErrorMessage::forClient($erro)
        );
    }

    public function testRejectionWithPathInDescriptionBecomesGeneric(): void
    {
        $erro = 'Erro na emissão: [{"Codigo":"E0001","Descricao":"Arquivo /etc/certs/empresa.pfx inválido"}]';

        $this->assertSame(ClientErrorMessage::GENERIC, ClientErrorMessage::forClient($erro));
    }

    public function testJsonWithoutSefinCodeBecomesGeneric(): void
    {
        $erro = 'Erro na requisição: {"message":"Internal Server Error","path":"/api/nfse"}';

        $this->assertSame(ClientErrorMessage::GENERIC, ClientErrorMessage::forClient($erro));
    }
}
