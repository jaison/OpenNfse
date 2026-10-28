<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\InvoiceLockService;
use OpenNfse\Services\NfseService;
use OpenNfse\Services\QueueErrorClassifierService;
use PHPUnit\Framework\TestCase;

final class NfseServiceEmissionLockTest extends TestCase
{
    private const ID_DPS = 'DPS420820324006645700011300900000000000000703';

    private function blockReason(?array $nota, string $reuseIdDps = ''): ?string
    {
        $service = new NfseService();
        $method = new \ReflectionMethod($service, 'emissionBlockReason');
        $method->setAccessible(true);

        return $method->invoke($service, $nota, $reuseIdDps);
    }

    public function testAllowsEmissionWithoutNota(): void
    {
        $this->assertNull($this->blockReason(null));
    }

    /**
     * @dataProvider reemittableStatusProvider
     */
    public function testAllowsNewEmissionForReemittableStatus(string $status): void
    {
        $this->assertNull($this->blockReason(['status' => $status, 'id_dps' => self::ID_DPS]));
    }

    public static function reemittableStatusProvider(): array
    {
        return [['REJEITADA'], ['ERRO'], ['CANCELADA']];
    }

    public function testBlocksWhenNotaAlreadyEmitida(): void
    {
        $this->assertNotNull($this->blockReason(['status' => 'EMITIDA', 'chave_acesso' => 'X']));
        $this->assertNotNull($this->blockReason(['status' => 'EMITIDA', 'chave_acesso' => ''], self::ID_DPS));
    }

    public function testBlocksNewDpsWhileAnotherIsProcessando(): void
    {
        $this->assertNotNull($this->blockReason(['status' => 'PROCESSANDO', 'id_dps' => self::ID_DPS]));
    }

    public function testAllowsResendOfSameDpsWhileProcessando(): void
    {
        $this->assertNull($this->blockReason(['status' => 'PROCESSANDO', 'id_dps' => self::ID_DPS], self::ID_DPS));
    }

    public function testBlocksResendWhenDpsChanged(): void
    {
        $this->assertNotNull($this->blockReason(['status' => 'PROCESSANDO', 'id_dps' => 'DPS_OUTRA'], self::ID_DPS));
        $this->assertNotNull($this->blockReason(['status' => 'ERRO', 'id_dps' => self::ID_DPS], self::ID_DPS));
    }

    public function testBlockMessagesAreRetryableForTheQueue(): void
    {
        $classifier = new QueueErrorClassifierService();
        $messages = [
            InvoiceLockService::BUSY_MESSAGE,
            $this->blockReason(['status' => 'EMITIDA']),
            $this->blockReason(['status' => 'PROCESSANDO', 'id_dps' => self::ID_DPS]),
            $this->blockReason(['status' => 'PROCESSANDO', 'id_dps' => 'DPS_OUTRA'], self::ID_DPS),
        ];

        foreach ($messages as $message) {
            $this->assertTrue($classifier->isRetryable((string) $message), (string) $message);
        }
    }
}
