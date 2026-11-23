<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Exceptions\NfseValidationException;
use OpenNfse\Services\CompraGovernamental;
use OpenNfse\Services\DpsLayoutService;
use OpenNfse\Services\EmissionPreValidationService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class EmissionPreValidationServiceTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function regimesRemovidos(): array
    {
        return ['7' => ['7'], '8' => ['8'], '10' => ['10'], '10 com espaços' => [' 10 ']];
    }

    /**
     * @dataProvider regimesRemovidos
     */
    public function testBlocksRemovedSpecialRegimeWithOwnMessage(string $regime): void
    {
        $this->expectException(NfseValidationException::class);
        $this->expectExceptionMessage(
            'Regime especial de tributação ' . trim($regime) . ' não é mais aceito pela SEFIN; ajuste em Configurações'
        );

        (new EmissionPreValidationService())->validate(['prestador_reg_esp_trib' => $regime], [], [], []);
    }

    public function testAcceptedSpecialRegimeFallsThroughToOtherChecks(): void
    {
        foreach (['0', '6', '9', ''] as $regime) {
            try {
                (new EmissionPreValidationService())->validate(['prestador_reg_esp_trib' => $regime], [], [], []);
                $this->fail('Esperava a validação preventiva geral (config vazia).');
            } catch (NfseValidationException $e) {
                $this->assertStringStartsWith('Validação preventiva falhou:', $e->getMessage(), "regime '$regime'");
                $this->assertStringNotContainsString('Regime especial', $e->getMessage());
            }
        }
    }

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

    public function testBloqueiaIbscbsSemCnbsCitandoE0322(): void
    {
        $this->expectException(NfseValidationException::class);
        $this->expectExceptionMessage('E0322');

        (new EmissionPreValidationService())->validate(
            $this->configIbscbs(''),
            ['id' => 0],
            [],
            ['id' => 1]
        );
    }

    public function testIbscbsComCnbsNaoCitaE0322(): void
    {
        try {
            (new EmissionPreValidationService())->validate(
                $this->configIbscbs('115031000'),
                ['id' => 0],
                [],
                ['id' => 1]
            );
            $this->fail('A configuração vazia ainda deve falhar na validação geral.');
        } catch (NfseValidationException $e) {
            $this->assertStringStartsWith('Validação preventiva falhou:', $e->getMessage());
            $this->assertStringNotContainsString('E0322', $e->getMessage());
        }
    }

    public function testBloqueiaEntePublicoSemIbscbsOuForaDaNt009(): void
    {
        $cliente = ['id' => 1, CompraGovernamental::CLIENT_KEY => '4 - Município'];

        try {
            (new EmissionPreValidationService())->validate(
                ['ibscbs_enabled' => '0', 'environment' => 'homologacao', 'dps_leiaute' => DpsLayoutService::MODE_NT009],
                ['id' => 0],
                [],
                $cliente
            );
            $this->fail('Ente público com IBS/CBS desligado deveria bloquear.');
        } catch (NfseValidationException $e) {
            $this->assertStringContainsString('ente público', $e->getMessage());
            $this->assertStringContainsString('IBS/CBS desligado', $e->getMessage());
        }

        try {
            (new EmissionPreValidationService())->validate(
                array_merge($this->configIbscbs('115031000'), ['dps_leiaute' => DpsLayoutService::MODE_PRE_NT009]),
                ['id' => 0],
                [],
                $cliente
            );
            $this->fail('Ente público fora da NT 009 deveria bloquear.');
        } catch (NfseValidationException $e) {
            $this->assertStringContainsString('NT 009', $e->getMessage());
        }
    }

    public function testBloqueiaEntePublicoSemCnpj(): void
    {
        Capsule::$rows['tblcustomfieldsvalues'] = [(object) ['value' => '123.456.789-09']];
        $this->expectException(NfseValidationException::class);
        $this->expectExceptionMessage('14 dígitos');

        (new EmissionPreValidationService())->validate(
            $this->configIbscbs('115031000') + ['tomador_cpfcnpj_customfield_id' => 3],
            ['id' => 0],
            [],
            ['id' => 8, CompraGovernamental::CLIENT_KEY => '4']
        );
    }

    public function testEntePublicoComCnpjNaoBloqueiaPorDocumento(): void
    {
        Capsule::$rows['tblcustomfieldsvalues'] = [(object) ['value' => '12.345.678/0001-95']];

        try {
            (new EmissionPreValidationService())->validate(
                $this->configIbscbs('115031000') + ['tomador_cpfcnpj_customfield_id' => 3],
                ['id' => 0],
                [],
                ['id' => 8, CompraGovernamental::CLIENT_KEY => '4 - Município']
            );
            $this->fail('A configuração vazia ainda deve falhar na validação geral.');
        } catch (NfseValidationException $e) {
            $this->assertStringStartsWith('Validação preventiva falhou:', $e->getMessage());
            $this->assertStringNotContainsString('14 dígitos', $e->getMessage());
            $this->assertStringNotContainsString('E0322', $e->getMessage());
        }
    }

    private function configIbscbs(string $nbs): array
    {
        return [
            'ibscbs_enabled' => '1',
            'environment' => 'homologacao',
            'dps_leiaute' => DpsLayoutService::MODE_NT009,
            'codigo_servico' => '010701',
            'nbs_padrao' => $nbs,
        ];
    }
}
