<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\QueueService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

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

    public function testAutomaticEmailForDoneItemNeverThrows(): void
    {
        // O stub do Capsule não tem orderBy(): a leitura da configuração falha e o erro precisa ser contido,
        // porque o item já foi marcado DONE pelo "Consultar agora".
        Capsule::$rows['mod_opennfse_notas'] = [
            (object) ['id' => 1, 'invoiceid' => 10, 'status' => 'EMITIDA', 'chave_acesso' => 'CHAVE'],
        ];

        try {
            (new QueueService())->sendAutomaticEmailForDoneItem(10, 'corr');
            $this->addToAssertionCount(1);
        } finally {
            Capsule::reset();
        }
    }
}
