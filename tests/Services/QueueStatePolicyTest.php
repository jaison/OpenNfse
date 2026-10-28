<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\QueueStatePolicy;
use PHPUnit\Framework\TestCase;

final class QueueStatePolicyTest extends TestCase
{
    private const NOW = '2026-10-28 12:00:00';

    private function now(): int
    {
        return (int) strtotime(self::NOW);
    }

    public function testRunningIsNotStaleBeforeFifteenMinutes(): void
    {
        $this->assertFalse(QueueStatePolicy::isRunningStale('2026-10-28 11:50:00', $this->now()));
        $this->assertFalse(QueueStatePolicy::isRunningStale('2026-10-28 11:45:00', $this->now()));
    }

    public function testRunningIsStaleAfterFifteenMinutes(): void
    {
        $this->assertTrue(QueueStatePolicy::isRunningStale('2026-10-28 11:44:59', $this->now()));
        $this->assertTrue(QueueStatePolicy::isRunningStale('2026-10-27 12:00:00', $this->now()));
    }

    public function testRunningWithoutTimestampIsStale(): void
    {
        $this->assertTrue(QueueStatePolicy::isRunningStale(null, $this->now()));
        $this->assertTrue(QueueStatePolicy::isRunningStale('', $this->now()));
        $this->assertTrue(QueueStatePolicy::isRunningStale('data-ruim', $this->now()));
    }

    public function testRunningInTheFutureIsNotStale(): void
    {
        $this->assertFalse(QueueStatePolicy::isRunningStale('2026-10-28 12:05:00', $this->now()));
    }

    public function testRunningSinceUsesClaimTimestampFirst(): void
    {
        $this->assertSame('2026-10-28 11:00:00', QueueStatePolicy::runningSince([
            'ultima_tentativa' => '2026-10-28 11:00:00',
            'updated_at' => '2026-10-28 11:30:00',
            'created_at' => '2026-10-28 10:00:00',
        ]));
        $this->assertSame('2026-10-28 11:30:00', QueueStatePolicy::runningSince([
            'ultima_tentativa' => null,
            'updated_at' => '2026-10-28 11:30:00',
            'created_at' => '2026-10-28 10:00:00',
        ]));
        $this->assertNull(QueueStatePolicy::runningSince([]));
    }

    public function testManualRetryAllowedForErrorAndPending(): void
    {
        $this->assertNull(QueueStatePolicy::manualRetryBlockReason(['status' => 'ERROR'], $this->now()));
        $this->assertNull(QueueStatePolicy::manualRetryBlockReason(['status' => 'PENDING'], $this->now()));
    }

    public function testManualRetryBlockedForWaitStatusWithConsultGuidance(): void
    {
        $reason = QueueStatePolicy::manualRetryBlockReason(['status' => 'WAIT_STATUS'], $this->now());
        $this->assertNotNull($reason);
        $this->assertStringContainsString('Consultar agora', (string) $reason);
    }

    public function testManualRetryBlockedForRecentRunning(): void
    {
        $reason = QueueStatePolicy::manualRetryBlockReason(
            ['status' => 'RUNNING', 'ultima_tentativa' => '2026-10-28 11:55:00'],
            $this->now()
        );
        $this->assertNotNull($reason);
        $this->assertStringContainsString('15 minutos', (string) $reason);
    }

    public function testManualRetryAllowedForStaleRunning(): void
    {
        $this->assertNull(QueueStatePolicy::manualRetryBlockReason(
            ['status' => 'RUNNING', 'ultima_tentativa' => '2026-10-28 11:30:00'],
            $this->now()
        ));
    }

    /**
     * @dataProvider blockedTerminalStatusProvider
     */
    public function testManualRetryBlockedForTerminalAndUnknownStatus(string $status): void
    {
        $this->assertNotNull(QueueStatePolicy::manualRetryBlockReason(['status' => $status], $this->now()));
    }

    public static function blockedTerminalStatusProvider(): array
    {
        return [['DONE'], ['RESOLVIDO'], [''], ['OUTRO']];
    }

    public function testRecoveryGoesToWaitStatusWhenDpsWasSent(): void
    {
        $nota = ['status' => 'PROCESSANDO', 'id_dps' => 'DPS123', 'chave_acesso' => ''];
        $this->assertSame('WAIT_STATUS', QueueStatePolicy::recoveryTargetStatus($nota, 1));
        $this->assertSame('WAIT_STATUS', QueueStatePolicy::recoveryTargetStatus($nota, 6));
    }

    public function testRecoveryGoesToWaitStatusWhenEmitidaWithoutChave(): void
    {
        $nota = ['status' => 'EMITIDA', 'id_dps' => 'DPS123', 'chave_acesso' => ''];
        $this->assertSame('WAIT_STATUS', QueueStatePolicy::recoveryTargetStatus($nota, 1));
    }

    public function testRecoveryGoesToPendingWhenNoDpsInFlight(): void
    {
        $this->assertSame('PENDING', QueueStatePolicy::recoveryTargetStatus(null, 1));
        $semDps = ['status' => 'PROCESSANDO', 'id_dps' => ''];
        $this->assertSame('PENDING', QueueStatePolicy::recoveryTargetStatus($semDps, 1));
        $comErro = ['status' => 'ERRO', 'id_dps' => 'DPS123'];
        $this->assertSame('PENDING', QueueStatePolicy::recoveryTargetStatus($comErro, 2));
        $this->assertSame('PENDING', QueueStatePolicy::recoveryTargetStatus(['status' => 'REJEITADA'], 5));
    }

    public function testRecoveryGoesToPendingWhenNotaAlreadyEmitida(): void
    {
        $nota = ['status' => 'EMITIDA', 'id_dps' => 'DPS123', 'chave_acesso' => 'CHAVE'];
        $this->assertSame('PENDING', QueueStatePolicy::recoveryTargetStatus($nota, 6));
    }

    public function testRecoveryGoesToErrorWhenAttemptsExhausted(): void
    {
        $this->assertSame('ERROR', QueueStatePolicy::recoveryTargetStatus(null, QueueStatePolicy::TOTAL_TENTATIVAS));
        $this->assertSame('ERROR', QueueStatePolicy::recoveryTargetStatus(['status' => 'ERRO'], 9));
    }

    public function testAttemptsLabel(): void
    {
        $this->assertSame('Tentativa 0 de 6', QueueStatePolicy::attemptsLabel(0));
        $this->assertSame('Tentativa 3 de 6', QueueStatePolicy::attemptsLabel(3));
    }

    public function testRecoveryToWaitStatusAllowedBelowLimit(): void
    {
        $nota = ['status' => 'PROCESSANDO', 'id_dps' => 'DPS123', 'chave_acesso' => ''];
        $this->assertSame('WAIT_STATUS', QueueStatePolicy::recoveryTargetStatus($nota, 1, 0));
        $this->assertSame('WAIT_STATUS', QueueStatePolicy::recoveryTargetStatus($nota, 1, 2));
    }

    public function testRecoveryToWaitStatusGoesToErrorAfterLimit(): void
    {
        $nota = ['status' => 'PROCESSANDO', 'id_dps' => 'DPS123', 'chave_acesso' => ''];
        $limit = QueueStatePolicy::MAX_RUNNING_RECOVERIES;
        $this->assertSame(3, $limit);
        $this->assertSame('ERROR', QueueStatePolicy::recoveryTargetStatus($nota, 1, $limit));
        $this->assertSame('ERROR', QueueStatePolicy::recoveryTargetStatus($nota, 1, $limit + 5));
        $this->assertTrue(QueueStatePolicy::isRecoveryLimitReached($limit));
        $this->assertFalse(QueueStatePolicy::isRecoveryLimitReached($limit - 1));
    }

    public function testRecoveryLimitDoesNotAffectPendingPath(): void
    {
        $this->assertSame('PENDING', QueueStatePolicy::recoveryTargetStatus(null, 1, 10));
        $emitida = ['status' => 'EMITIDA', 'id_dps' => 'DPS123', 'chave_acesso' => 'CHAVE'];
        $this->assertSame('PENDING', QueueStatePolicy::recoveryTargetStatus($emitida, 1, 10));
    }
}
