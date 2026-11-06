<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Updater;

use OpenNfse\Updater\HookGate;
use OpenNfse\Updater\MaintenanceMode;
use OpenNfse\Updater\UpdaterStore;
use PHPUnit\Framework\TestCase;

final class MaintenanceModeTest extends TestCase
{
    private InMemoryUpdaterStore $store;

    /** @var list<array{0: string, 1: array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->store = new InMemoryUpdaterStore();
        MaintenanceMode::useStore($this->store);
        $this->calls = [];
        HookGate::useHandlers([
            'invoice_paid_emit' => function (array $vars): void {
                $this->calls[] = ['invoice_paid_emit', $vars];
            },
            'invoice_cancelled' => function (array $vars): void {
                throw new \RuntimeException('falhou');
            },
        ]);
    }

    protected function tearDown(): void
    {
        MaintenanceMode::useStore(null);
        HookGate::useHandlers(null);
    }

    public function testHooksRunNormallyWhenNotPaused(): void
    {
        HookGate::run('invoice_paid_emit', ['invoiceid' => 10]);

        $this->assertSame([['invoice_paid_emit', ['invoiceid' => 10]]], $this->calls);
        $this->assertSame([], $this->store->events);
    }

    public function testPausedHooksAreDeferredAndReplayedInOrderAfterResume(): void
    {
        MaintenanceMode::pause(1, '1.0.0', '1.0.1');
        HookGate::run('invoice_paid_emit', ['invoiceid' => 10, 'obj' => new \stdClass(), 'nested' => ['a' => 1]]);
        HookGate::run('invoice_paid_emit', ['invoiceid' => 11]);

        $this->assertSame([], $this->calls);
        $this->assertSame(2, $this->store->countPendingEvents());
        $this->assertSame(['invoiceid' => 10, 'nested' => ['a' => 1]], $this->store->events[0]['vars']);

        // Com a pausa ativa, nada é reprocessado.
        $this->assertSame(0, HookGate::replayPending()['replayed']);

        MaintenanceMode::resume();
        $result = HookGate::replayPending();

        $this->assertSame(['replayed' => 2, 'failed' => 0, 'remaining' => 0], $result);
        $this->assertSame([10, 11], array_map(static fn (array $c): int => $c[1]['invoiceid'], $this->calls));
    }

    public function testReplayRecordsFailuresAndContinues(): void
    {
        MaintenanceMode::pause(1, '1.0.0', '1.0.1');
        HookGate::run('invoice_cancelled', ['invoiceid' => 5]);
        HookGate::run('invoice_paid_emit', ['invoiceid' => 6]);
        MaintenanceMode::resume();

        $result = HookGate::replayPending();

        $this->assertSame(['replayed' => 2, 'failed' => 1, 'remaining' => 0], $result);
        $this->assertSame('falhou', $this->store->events[0]['error']);
        $this->assertCount(1, $this->calls);
    }

    public function testStoreFailureWhilePausedFallsBackToRunningTheHook(): void
    {
        MaintenanceMode::useStore(new class implements UpdaterStore {
            public function state(): array
            {
                return ['paused' => 1];
            }
            public function saveState(array $fields): void
            {
            }
            public function addEvent(string $hook, array $vars): void
            {
                throw new \RuntimeException('sem banco');
            }
            public function pendingEvents(int $limit): array
            {
                return [];
            }
            public function countPendingEvents(): int
            {
                return 0;
            }
            public function markEventReplayed(int $id, ?string $error): void
            {
            }
        });

        HookGate::run('invoice_paid_emit', ['invoiceid' => 7]);

        $this->assertCount(1, $this->calls);
    }

    public function testUnreadableStateCountsAsNotPaused(): void
    {
        MaintenanceMode::useStore(new class implements UpdaterStore {
            public function state(): array
            {
                throw new \RuntimeException('tabela não existe');
            }
            public function saveState(array $fields): void
            {
            }
            public function addEvent(string $hook, array $vars): void
            {
            }
            public function pendingEvents(int $limit): array
            {
                return [];
            }
            public function countPendingEvents(): int
            {
                return 0;
            }
            public function markEventReplayed(int $id, ?string $error): void
            {
            }
        });

        $this->assertFalse(MaintenanceMode::isPaused());
    }

    public function testSettledNeedsGraceTimeAndIdleCron(): void
    {
        MaintenanceMode::pause(1, '1.0.0', '1.0.1');
        $pausedAt = strtotime((string) $this->store->state['paused_at']);

        $this->assertFalse(MaintenanceMode::isSettled(static fn (): bool => true, $pausedAt + 10));
        $this->assertFalse(MaintenanceMode::isSettled(static fn (): bool => false, $pausedAt + 120));
        $this->assertTrue(MaintenanceMode::isSettled(static fn (): bool => true, $pausedAt + 120));
    }

    public function testUnknownHookIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HookGate::dispatch('nao_existe', []);
    }
}
