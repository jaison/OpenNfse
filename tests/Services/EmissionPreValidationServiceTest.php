<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Exceptions\NfseValidationException;
use OpenNfse\Services\EmissionPreValidationService;
use PHPUnit\Framework\TestCase;

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
}
