<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
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

    public function testAutomaticEmailFailureIsLoggedAndNotWrittenToInvoiceNotes(): void
    {
        // Sem nota no stub, o envio falha com "NFS-e não encontrada para esta fatura.".
        Capsule::$rows['tblinvoices'] = [(object) ['id' => 10, 'notes' => '']];

        $service = new AutoEmailService();
        $send = new \ReflectionMethod($service, 'send');
        $send->setAccessible(true);
        $send->invoke($service, 10, 1, 'CHAVE', 1, new NotaRepository(), new LogRepository(), 'corr');

        $this->assertArrayNotHasKey('tblinvoices', Capsule::$updates);
        $logs = Capsule::$inserts['mod_opennfse_logs'] ?? [];
        $this->assertSame(['EMAIL_NFSE_AUTO_ERROR'], array_column($logs, 'tipo'));
        $this->assertSame('NFS-e não encontrada para esta fatura.', $logs[0]['response']);
        $request = json_decode((string) $logs[0]['request'], true);
        $this->assertSame(1, $request['tentativa']);
    }
}
