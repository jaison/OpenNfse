<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Repositories\WhmcsCustomerRepository;
use OpenNfse\Services\TomadorBairroService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class TomadorBairroServiceTest extends TestCase
{
    private const MIGRATION = '/migrations/048_alter_config_tomador_bairro_origem.sql';

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

    public function testOrigemPadraoEhEndereco2(): void
    {
        $this->assertSame('address2', TomadorBairroService::origem([]));
        $this->assertSame('address2', TomadorBairroService::origem(['tomador_bairro_origem' => null]));
        $this->assertSame('address2', TomadorBairroService::origem(['tomador_bairro_origem' => 'outro']));
        $this->assertSame('customfield', TomadorBairroService::origem(['tomador_bairro_origem' => ' CustomField ']));
    }

    public function testValidaConfiguracao(): void
    {
        $this->assertSame([], TomadorBairroService::validateConfig('address2', '', []));
        $this->assertSame([], TomadorBairroService::validateConfig('address2', '7', []), 'Fora do modo custom field o ID não é exigido.');
        $this->assertSame([], TomadorBairroService::validateConfig('customfield', '7', [3, 7]));

        $this->assertSame(['Origem do bairro do tomador inválida.'], TomadorBairroService::validateConfig('cep', '', []));
        $this->assertSame(['Custom field do bairro do tomador inválido.'], TomadorBairroService::validateConfig('customfield', 'abc', [3]));
        $this->assertSame(['Selecione o custom field do bairro do tomador.'], TomadorBairroService::validateConfig('customfield', '', [3]));
        $this->assertSame(['Selecione o custom field do bairro do tomador.'], TomadorBairroService::validateConfig('customfield', '0', [3]));
        $this->assertSame(
            ['O custom field selecionado para o bairro do tomador não existe.'],
            TomadorBairroService::validateConfig('customfield', '8', [3, 7])
        );
    }

    public function testAnexaBairroDoCustomFieldSoNoModoCustomField(): void
    {
        Capsule::$rows['tblcustomfieldsvalues'] = [(object) ['id' => 1, 'relid' => 5, 'fieldid' => 9, 'value' => '  Vila Nova ']];
        $client = ['id' => 5, 'address2' => 'Casa 2'];
        $service = new TomadorBairroService();
        $repo = new WhmcsCustomerRepository();

        $this->assertSame($client, $service->withCustomFieldBairro(['tomador_bairro_origem' => 'address2'], $client, $repo));

        $config = ['tomador_bairro_origem' => 'customfield', 'tomador_bairro_customfield_id' => 9];
        $result = $service->withCustomFieldBairro($config, $client, $repo);
        $this->assertSame('Vila Nova', $result[TomadorBairroService::CLIENT_KEY]);
        $this->assertSame('Casa 2', $result['address2']);
    }

    public function testCustomFieldSemValorFicaVazio(): void
    {
        $config = ['tomador_bairro_origem' => 'customfield', 'tomador_bairro_customfield_id' => 9];
        $result = (new TomadorBairroService())->withCustomFieldBairro($config, ['id' => 5], new WhmcsCustomerRepository());
        $this->assertSame('', $result[TomadorBairroService::CLIENT_KEY]);

        $config['tomador_bairro_customfield_id'] = null;
        $result = (new TomadorBairroService())->withCustomFieldBairro($config, ['id' => 5], new WhmcsCustomerRepository());
        $this->assertSame('', $result[TomadorBairroService::CLIENT_KEY]);
    }

    public function testMigrationCriaColunasComEndereco2ComoPadrao(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . self::MIGRATION);
        $statements = array_values(array_filter(array_map(
            static fn (string $s): string => rtrim(trim($s), ';'),
            preg_split('/;\s*[\r\n]+/', trim($sql)) ?: []
        )));

        $this->assertSame([
            "ALTER TABLE mod_opennfse_config ADD COLUMN tomador_bairro_origem VARCHAR(20) NOT NULL DEFAULT 'address2'",
            'ALTER TABLE mod_opennfse_config ADD COLUMN tomador_bairro_customfield_id INT NULL',
        ], $statements);
    }
}
