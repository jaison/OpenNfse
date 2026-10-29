<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\AutoEmailService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class AutoEmailServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Capsule::reset();
    }

    public function testOnNotaEmitidaNeverThrows(): void
    {
        // O stub do Capsule não tem orderBy(): a leitura da configuração falha e o erro precisa ser contido,
        // porque uma falha de e-mail não pode derrubar a emissão nem mudar o status da fila.
        Capsule::$rows['mod_opennfse_notas'] = [
            (object) ['id' => 1, 'invoiceid' => 10, 'status' => 'EMITIDA', 'chave_acesso' => 'CHAVE'],
        ];

        (new AutoEmailService())->onNotaEmitida(10, 'corr');
        $this->addToAssertionCount(1);
    }

    public function testProcessRetriesNeverThrows(): void
    {
        (new AutoEmailService())->processRetries();
        $this->addToAssertionCount(1);
    }
}
