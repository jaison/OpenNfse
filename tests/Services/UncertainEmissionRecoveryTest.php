<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\DuplicateDpsRecovery;
use OpenNfse\Services\QueueErrorClassifierService;
use OpenNfse\Services\UncertainEmissionRecovery;
use PHPUnit\Framework\TestCase;

final class UncertainEmissionRecoveryTest extends TestCase
{
    public function testDetectsE999AndHttp500(): void
    {
        $body = '{"erros":[{"Codigo":"E999","Descricao":"Erro não catalogado"}]}';

        $this->assertTrue(UncertainEmissionRecovery::isUncertain($body));
        $this->assertTrue(UncertainEmissionRecovery::isUncertain(null, $body));
        $this->assertTrue(UncertainEmissionRecovery::isUncertain('falha', null, 500));
        $this->assertTrue(UncertainEmissionRecovery::isUncertain(
            'Server error: resulted in a `500 Internal Server Error` response'
        ));
        $this->assertFalse(UncertainEmissionRecovery::isUncertain('{"erros":[{"Codigo":"E9999"}]}'));
        $this->assertFalse(UncertainEmissionRecovery::isUncertain('{"erros":[{"Codigo":"E0014"}]}'));
        $this->assertFalse(UncertainEmissionRecovery::isUncertain('valor 500'));
        $this->assertFalse(UncertainEmissionRecovery::isUncertain(null, null, 503));
        $this->assertFalse(UncertainEmissionRecovery::isUncertain(null));
    }

    public function testLinksWhenTheNfseWasFound(): void
    {
        $this->assertSame(
            UncertainEmissionRecovery::STATUS_EMITIDA,
            UncertainEmissionRecovery::statusFor(['chave' => 'ABC', 'nfseXml' => '<NFSe/>'])
        );
    }

    public function testRejectsWhenTheFoundNfseDoesNotMatch(): void
    {
        $this->assertSame(
            UncertainEmissionRecovery::STATUS_REJEITADA,
            UncertainEmissionRecovery::statusFor(['reason' => 'o tomador da NFS-e encontrada é outro'])
        );
        $this->assertTrue(DuplicateDpsRecovery::isBindingMismatch('a NFS-e encontrada é de outra DPS'));
        $this->assertTrue(DuplicateDpsRecovery::isBindingMismatch('o valor do serviço da NFS-e encontrada é outro'));
    }

    public function testKeepsProcessingWhenTheConsultDoesNotFindTheNfse(): void
    {
        $this->assertSame(
            UncertainEmissionRecovery::STATUS_PROCESSANDO,
            UncertainEmissionRecovery::statusFor([
                'reason' => 'a consulta da DPS na SEFIN não retornou a chave de acesso',
            ])
        );
        $this->assertSame(
            UncertainEmissionRecovery::STATUS_PROCESSANDO,
            UncertainEmissionRecovery::statusFor([])
        );
        $this->assertFalse((new QueueErrorClassifierService())->isTransientApiOutage(
            UncertainEmissionRecovery::PENDING_MESSAGE
        ));
    }
}
