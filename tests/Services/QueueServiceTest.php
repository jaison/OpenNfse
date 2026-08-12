<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\QueueService;
use PHPUnit\Framework\TestCase;

final class QueueServiceTest extends TestCase
{
    public function testShouldKeepWaitingForStatusWhenNotaIsProcessando(): void
    {
        $service = new QueueService();
        $method = new \ReflectionMethod($service, 'shouldKeepWaitingForStatus');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'status' => 'PROCESSANDO',
            'id_dps' => 'DPS123',
            'chave_acesso' => '',
        ]);

        $this->assertTrue($result);
    }

    public function testShouldNotKeepWaitingForStatusWhenNotaIsErroWithoutChave(): void
    {
        $service = new QueueService();
        $method = new \ReflectionMethod($service, 'shouldKeepWaitingForStatus');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'status' => 'ERRO',
            'id_dps' => 'DPS420820324006645700011300900000000000000672',
            'chave_acesso' => '',
        ]);

        $this->assertFalse($result);
    }

    public function testShouldKeepWaitingForStatusWhenNotaEmitidaWithoutChave(): void
    {
        $service = new QueueService();
        $method = new \ReflectionMethod($service, 'shouldKeepWaitingForStatus');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'status' => 'EMITIDA',
            'id_dps' => 'DPS123',
            'chave_acesso' => '',
        ]);

        $this->assertTrue($result);
    }
}
