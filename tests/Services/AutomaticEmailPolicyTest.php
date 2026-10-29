<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\AutomaticEmailPolicy;
use PHPUnit\Framework\TestCase;

final class AutomaticEmailPolicyTest extends TestCase
{
    private const NOW = '2026-10-28 12:00:00';

    private function now(): int
    {
        return (int) strtotime(self::NOW);
    }

    private function nota(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'invoiceid' => 10,
            'status' => 'EMITIDA',
            'chave_acesso' => 'CHAVE-NOVA',
            'email_automatico_chave' => null,
        ];
    }

    public function testClaimAllowedWhenNoEmailWasSentYet(): void
    {
        $this->assertNull(AutomaticEmailPolicy::skipReason(true, $this->nota()));
    }

    public function testClaimAllowedAfterReemissionWithNewKey(): void
    {
        $nota = $this->nota(['email_automatico_chave' => 'CHAVE-ANTIGA']);

        $this->assertFalse(AutomaticEmailPolicy::alreadyClaimedForKey($nota, 'CHAVE-NOVA'));
        $this->assertNull(AutomaticEmailPolicy::skipReason(true, $nota));
    }

    public function testClaimAllowedForLegacyRowWithoutKeyBackfill(): void
    {
        // A migration 043 grava '' quando a nota antiga não tinha chave: a primeira chave real libera o e-mail.
        $this->assertNull(AutomaticEmailPolicy::skipReason(true, $this->nota(['email_automatico_chave' => ''])));
    }

    public function testClaimBlockedForSameKey(): void
    {
        $nota = $this->nota(['email_automatico_chave' => 'CHAVE-NOVA']);

        $this->assertTrue(AutomaticEmailPolicy::alreadyClaimedForKey($nota, 'CHAVE-NOVA'));
        $this->assertSame(AutomaticEmailPolicy::SKIP_ALREADY_SENT, AutomaticEmailPolicy::skipReason(true, $nota));
    }

    public function testSameKeyWithSurroundingSpacesIsStillTheSameKey(): void
    {
        $nota = $this->nota(['chave_acesso' => ' CHAVE-NOVA ', 'email_automatico_chave' => 'CHAVE-NOVA']);

        $this->assertSame(AutomaticEmailPolicy::SKIP_ALREADY_SENT, AutomaticEmailPolicy::skipReason(true, $nota));
    }

    public function testSkipWhenConfigDisabledComesFirst(): void
    {
        $disabled = AutomaticEmailPolicy::SKIP_CONFIG_DISABLED;

        $this->assertSame($disabled, AutomaticEmailPolicy::skipReason(false, $this->nota()));
        $this->assertSame($disabled, AutomaticEmailPolicy::skipReason(false, null));
    }

    public function testSkipWhenNotaIsNotEmitida(): void
    {
        $this->assertSame(AutomaticEmailPolicy::SKIP_NOT_EMITIDA, AutomaticEmailPolicy::skipReason(true, null));
        foreach (['PROCESSANDO', 'CANCELADA', 'ERRO', 'REJEITADA', ''] as $status) {
            $this->assertSame(
                AutomaticEmailPolicy::SKIP_NOT_EMITIDA,
                AutomaticEmailPolicy::skipReason(true, $this->nota(['status' => $status])),
                $status
            );
        }
    }

    public function testSkipWhenNotaHasNoKey(): void
    {
        foreach ([null, '', '  '] as $chave) {
            $this->assertSame(
                AutomaticEmailPolicy::SKIP_NO_KEY,
                AutomaticEmailPolicy::skipReason(true, $this->nota(['chave_acesso' => $chave]))
            );
        }
    }

    public function testTransitionFromProcessandoToEmitida(): void
    {
        $before = $this->nota(['status' => 'PROCESSANDO', 'chave_acesso' => null]);

        $this->assertTrue(AutomaticEmailPolicy::isEmissionTransition($before, $this->nota()));
        $this->assertTrue(AutomaticEmailPolicy::isEmissionTransition(null, $this->nota()));
    }

    public function testTransitionWhenEmitidaRecoversItsKey(): void
    {
        $before = $this->nota(['chave_acesso' => '']);

        $this->assertTrue(AutomaticEmailPolicy::isEmissionTransition($before, $this->nota()));
    }

    public function testTransitionOnReemissionAfterCancellation(): void
    {
        $before = $this->nota(['status' => 'CANCELADA', 'chave_acesso' => 'CHAVE-ANTIGA']);

        $this->assertTrue(AutomaticEmailPolicy::isEmissionTransition($before, $this->nota()));
    }

    public function testNoTransitionWhenConsultingAnAlreadyEmittedNota(): void
    {
        $this->assertFalse(AutomaticEmailPolicy::isEmissionTransition($this->nota(), $this->nota()));
    }

    public function testNoTransitionWhenNotaIsNotEmitidaWithKeyAfterwards(): void
    {
        $before = $this->nota(['status' => 'PROCESSANDO', 'chave_acesso' => null]);

        $processando = $this->nota(['status' => 'PROCESSANDO']);
        $semChave = $this->nota(['chave_acesso' => '']);
        $cancelada = $this->nota(['status' => 'CANCELADA']);

        $this->assertFalse(AutomaticEmailPolicy::isEmissionTransition($before, $processando));
        $this->assertFalse(AutomaticEmailPolicy::isEmissionTransition($before, $semChave));
        $this->assertFalse(AutomaticEmailPolicy::isEmissionTransition($this->nota(), $cancelada));
        $this->assertFalse(AutomaticEmailPolicy::isEmissionTransition($before, null));
    }

    public function testRetryScheduleGrowsAfterEachFailure(): void
    {
        $this->assertSame('2026-10-28 12:15:00', AutomaticEmailPolicy::nextRetryAt(1, $this->now()));
        $this->assertSame('2026-10-28 13:00:00', AutomaticEmailPolicy::nextRetryAt(2, $this->now()));
        $this->assertSame('2026-10-28 18:00:00', AutomaticEmailPolicy::nextRetryAt(3, $this->now()));
    }

    public function testRetryStopsAfterLastAttempt(): void
    {
        $this->assertSame(4, AutomaticEmailPolicy::TOTAL_ATTEMPTS);
        $this->assertNull(AutomaticEmailPolicy::nextRetryAt(AutomaticEmailPolicy::TOTAL_ATTEMPTS, $this->now()));
        $this->assertNull(AutomaticEmailPolicy::nextRetryAt(10, $this->now()));
        $this->assertNull(AutomaticEmailPolicy::nextRetryAt(0, $this->now()));
    }

    public function testRetryDelaysMatchRetryCount(): void
    {
        $this->assertCount(AutomaticEmailPolicy::MAX_RETRIES, AutomaticEmailPolicy::RETRY_DELAYS_SECONDS);
    }
}
