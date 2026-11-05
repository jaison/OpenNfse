<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Helpers;

use OpenNfse\Helpers\ClientErrorMessage;
use PHPUnit\Framework\TestCase;

final class ClientErrorMessageTest extends TestCase
{
    private const SUPORTE = '. Entre em contato com o suporte.';

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

    public function testDescriptionWithComparisonSignsIsKept(): void
    {
        $erro = 'Erro na emissão: [{"Codigo":"E0712","Descricao":"Valor do serviço deve ser > 0"}]';

        $this->assertSame(
            'Retorno da SEFIN: E0712 - Valor do serviço deve ser > 0' . self::SUPORTE,
            ClientErrorMessage::forClient($erro)
        );
    }

    public function testXpathInDescriptionIsKept(): void
    {
        $erro = 'Erro na emissão: [{"Codigo":"E0160","Descricao":"CNPJ inválido em /DPS/infDPS/toma/CNPJ"}]';

        $this->assertSame(
            'Retorno da SEFIN: E0160 - CNPJ inválido em /DPS/infDPS/toma/CNPJ' . self::SUPORTE,
            ClientErrorMessage::forClient($erro)
        );
    }

    public function testLineBreakInDescriptionBecomesSpace(): void
    {
        $erro = 'Erro na emissão: [{"Codigo":"E0300","Descricao":"Tomador não informado.\r\nVerifique o cadastro."}]';

        $this->assertSame(
            'Retorno da SEFIN: E0300 - Tomador não informado. Verifique o cadastro' . self::SUPORTE,
            ClientErrorMessage::forClient($erro)
        );
    }

    public function testSchemaRejectionWithNestedComplementKeepsOnlyCodeAndDescription(): void
    {
        $erro = 'Rejeição de leiaute da DPS (E1235): ajuste em Configurações. Detalhe: Erro na emissão: '
            . '[{"Codigo":"E1235","Descricao":"Falha Schema Xml","Complemento":"The element '
            . '\'{http://www.sped.fazenda.gov.br/nfse}cServ\' has invalid child element '
            . '\'{http://www.sped.fazenda.gov.br/nfse}xDescServ\'."}]';

        $msg = ClientErrorMessage::forClient($erro);

        $this->assertSame('Retorno da SEFIN: E1235 - Falha Schema Xml' . self::SUPORTE, $msg);
        $this->assertStringNotContainsString('xDescServ', $msg);
        $this->assertStringNotContainsString('Configurações', $msg);
    }

    public function testSefinRejectionInNestedObject(): void
    {
        $erro = 'Erro na requisição: {"erro":{"codigo":"E2404",'
            . '"descricao":"Não foi gerada uma NFS-e com o identificador de DPS informado"}}';

        $this->assertSame(
            'Retorno da SEFIN: E2404 - Não foi gerada uma NFS-e com o identificador de DPS informado' . self::SUPORTE,
            ClientErrorMessage::forClient($erro)
        );
    }

    public function testUnsafeItemIsDroppedAndSafeItemIsKept(): void
    {
        $erro = 'Erro na emissão: [{"Codigo":"E0001","Descricao":"Arquivo /etc/certs/empresa.pfx inválido"},'
            . '{"Codigo":"E0712","Descricao":"Valor do serviço deve ser > 0"}]';

        $msg = ClientErrorMessage::forClient($erro);

        $this->assertSame('Retorno da SEFIN: E0712 - Valor do serviço deve ser > 0' . self::SUPORTE, $msg);
        $this->assertStringNotContainsString('pfx', $msg);
    }

    public function testRejectionWithOnlyUnsafeItemsBecomesGeneric(): void
    {
        $erros = [
            'Erro na emissão: [{"Codigo":"E0001","Descricao":"Arquivo /etc/certs/empresa.pfx inválido"}]',
            'Erro na emissão: [{"Codigo":"E0002","Descricao":"Falha ao ler C:\\\\certs\\\\a"}]',
        ];

        foreach ($erros as $erro) {
            $this->assertSame(ClientErrorMessage::GENERIC, ClientErrorMessage::forClient($erro), $erro);
        }
    }

    public function testJsonWithoutSefinCodeBecomesGeneric(): void
    {
        $erro = 'Erro na requisição: {"message":"Internal Server Error","path":"/api/nfse"}';

        $this->assertSame(ClientErrorMessage::GENERIC, ClientErrorMessage::forClient($erro));
    }
}
