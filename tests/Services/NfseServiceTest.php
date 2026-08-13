<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\NfseService;
use PHPUnit\Framework\TestCase;

final class NfseServiceTest extends TestCase
{
    public function testCanAttemptE2404SameDpsReemitWhenUnderLimit(): void
    {
        $service = new NfseService();
        $method = new \ReflectionMethod($service, 'canAttemptE2404SameDpsReemit');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            [
                'id_dps' => 'DPS420820324006645700011300900000000000000703',
                'e2404_reemit_attempts' => 1,
            ],
            'Erro na requisição: {"erro":{"codigo":"E2404","descricao":"Não foi gerada uma NFS-e com o identificador de DPS informado"}}'
        );

        $this->assertTrue($result);
    }

    public function testCanAttemptE2404SameDpsReemitStopsAtLimit(): void
    {
        $service = new NfseService();
        $method = new \ReflectionMethod($service, 'canAttemptE2404SameDpsReemit');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            [
                'id_dps' => 'DPS420820324006645700011300900000000000000703',
                'e2404_reemit_attempts' => 2,
            ],
            'Erro na requisição: {"erro":{"codigo":"E2404","descricao":"Não foi gerada uma NFS-e com o identificador de DPS informado"}}'
        );

        $this->assertFalse($result);
    }

    public function testExtractDpsSequenceNumberFromIdDps(): void
    {
        $service = new NfseService();
        $method = new \ReflectionMethod($service, 'extractDpsSequenceNumber');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'DPS420820324006645700011300900000000000000703');

        $this->assertSame(703, $result);
    }
}
