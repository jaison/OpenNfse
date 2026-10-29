<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\GenerationCheckService as S;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class GenerationCheckServiceTest extends TestCase
{
    private const NOW = 1793188800; // 2026-10-28 12:00:00 UTC

    private const CONFIG = ['queue_enabled' => '1', 'auto_emit_trigger' => 'gerada'];

    private InMemoryGenerationCheckRepository $repo;

    /** @var array<int, string|null> */
    private array $statuses = [];

    /** @var array<int, true> */
    private array $handled = [];

    /** @var array<int, int> */
    private array $handlerCalls = [];

    private bool $handlerResult = true;

    private ?\Throwable $handlerError = null;

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::reset();
        $this->repo = new InMemoryGenerationCheckRepository();
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        parent::tearDown();
    }

    private function service(?callable $ensureSchema = null): S
    {
        return new S(
            $this->repo,
            fn (int $id): ?string => $this->statuses[$id] ?? null,
            fn (int $id): bool => isset($this->handled[$id]),
            function (int $id, array $config): bool {
                $this->handlerCalls[] = $id;
                if ($this->handlerError !== null) {
                    throw $this->handlerError;
                }
                return $this->handlerResult;
            },
            $ensureSchema ?? static function (): void {
            }
        );
    }

    /** Registra a fatura $daysAgo dias antes de NOW (vencida, salvo $due). */
    private function register(int $invoiceId, int $daysAgo = 0, ?int $due = null): void
    {
        $at = self::NOW - ($daysAgo * 86400) - 600;
        $this->repo->register($invoiceId, date('Y-m-d H:i:s', $at), date('Y-m-d H:i:s', $due ?? ($at + S::INTERVAL_SECONDS)));
    }

    private function logTypes(): array
    {
        return array_column(Capsule::$inserts['mod_opennfse_logs'] ?? [], 'tipo');
    }

    public function testNewCheckIsDueOnlyAfterTheInterval(): void
    {
        $this->statuses[10] = 'Unpaid';
        $this->service()->register(10, self::NOW);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW + 300), $this->repo->rows[10]['proxima_em']);

        $this->assertSame(0, $this->service()->processPending(self::CONFIG, self::NOW + 299), 'Ainda não venceu.');
        $this->assertSame([], $this->handlerCalls);

        // "Publish" sem e-mail: tratado no primeiro ciclo depois do vencimento.
        $this->assertSame(1, $this->service()->processPending(self::CONFIG, self::NOW + 300));
        $this->assertSame([10], $this->handlerCalls);
        $this->assertSame([], $this->repo->rows);
    }

    public function testRecentDraftIsRescheduledAndOldDraftIsDropped(): void
    {
        $this->register(11, 2);
        $this->register(12, 31);
        $this->statuses[11] = 'Draft';
        $this->statuses[12] = 'Draft';

        $this->service()->processPending(self::CONFIG, self::NOW);
        $this->assertSame([], $this->handlerCalls);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW + 300), $this->repo->rows[11]['proxima_em']);
        $this->assertArrayNotHasKey(12, $this->repo->rows);

        // Antes de vencer de novo, o rascunho não custa consulta.
        $this->statuses = [];
        $this->service()->processPending(self::CONFIG, self::NOW + 60);
        $this->assertArrayHasKey(11, $this->repo->rows);
    }

    public function testOtherStatusesAndMissingInvoiceAreDroppedWithoutAction(): void
    {
        foreach ([20 => 'Paid', 21 => 'Cancelled', 22 => 'Refunded', 23 => null] as $id => $status) {
            $this->register($id);
            $this->statuses[$id] = $status;
        }

        $this->service()->processPending(self::CONFIG, self::NOW);
        $this->assertSame([], $this->handlerCalls);
        $this->assertSame([], $this->repo->rows);
    }

    public function testAlreadyHandledIsDroppedSilently(): void
    {
        $this->register(30);
        $this->statuses[30] = 'Unpaid';
        $this->handled[30] = true;

        $this->service()->processPending(self::CONFIG, self::NOW);
        $this->assertSame([], $this->handlerCalls);
        $this->assertSame([], $this->repo->rows);
        $this->assertSame([], $this->logTypes());
    }

    public function testInvoiceStillDraftForHandlerIsRescheduled(): void
    {
        $this->register(40);
        $this->statuses[40] = 'Unpaid';
        $this->handlerResult = false;

        $this->service()->processPending(self::CONFIG, self::NOW);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW + 300), $this->repo->rows[40]['proxima_em']);
    }

    public function testBackoffGrows(): void
    {
        $this->assertSame([300, 900, 3600, 21600, 86400, 86400], array_map([S::class, 'backoffSeconds'], [1, 2, 3, 4, 5, 9]));

        $this->register(41);
        $this->statuses[41] = 'Unpaid';
        $this->handlerError = new \RuntimeException('falha persistente');

        $now = self::NOW;
        $expectedGaps = [300, 900, 3600, 21600, 86400, 86400];
        foreach ($expectedGaps as $i => $gap) {
            $this->service()->processPending(self::CONFIG, $now);
            $this->assertSame($i + 1, $this->repo->rows[41]['tentativas']);
            $this->assertSame(date('Y-m-d H:i:s', $now + $gap), $this->repo->rows[41]['proxima_em'], 'Falha ' . ($i + 1));
            $now += $gap;
        }
    }

    public function testRepeatedFailureLogsOnFirstAndThenAtMostOncePerDay(): void
    {
        $this->register(42);
        $this->statuses[42] = 'Unpaid';
        $this->handlerError = new \RuntimeException('lock ocupado');

        // 1ª falha: loga. Seguintes no mesmo dia (5 min, 15 min, 1 h, 6 h depois): não logam.
        $now = self::NOW;
        foreach ([300, 900, 3600, 21600] as $gap) {
            $this->service()->processPending(self::CONFIG, $now);
            $now += $gap;
        }
        $this->assertSame(['QUEUE_AUTO_CREATED_ERROR'], $this->logTypes());

        // Mais de um dia depois do primeiro log: loga de novo.
        $this->service()->processPending(self::CONFIG, self::NOW + 86400 + 60);
        $this->assertSame(['QUEUE_AUTO_CREATED_ERROR', 'QUEUE_AUTO_CREATED_ERROR'], $this->logTypes());
    }

    public function testFailureStopsAfterMaxDays(): void
    {
        $this->register(43, 31);
        $this->statuses[43] = 'Unpaid';
        $this->handlerError = new \RuntimeException('falha');

        $this->service()->processPending(self::CONFIG, self::NOW);
        $this->assertSame([], $this->repo->rows);
    }

    public function testNothingRunsWhenGenerationCannotApply(): void
    {
        $this->register(50);
        $this->statuses[50] = 'Unpaid';

        $this->assertSame(0, $this->service()->processPending(['queue_enabled' => '0', 'auto_emit_trigger' => 'gerada'], self::NOW));
        $this->assertSame(0, $this->service()->processPending(['queue_enabled' => '1', 'auto_emit_trigger' => 'paga'], self::NOW));
        $this->assertSame([], $this->handlerCalls);
        $this->assertArrayHasKey(50, $this->repo->rows);
    }

    public function testRegisterIsIdempotentAndKeepsFirstDate(): void
    {
        $service = $this->service();
        $service->register(60, self::NOW - 3600);
        $service->register(60, self::NOW);

        $this->assertCount(1, $this->repo->rows);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW - 3600), $this->repo->rows[60]['registrado_em']);
        $this->assertTrue($service->isPending(60));
        $this->assertFalse($service->isPending(61));
    }

    public function testRegisterCreatesTableWhenMissing(): void
    {
        $this->repo->tableMissing = true;
        $ensured = false;
        $service = $this->service(function () use (&$ensured): void {
            $ensured = true;
            $this->repo->tableMissing = false;
        });

        $service->register(70, self::NOW);
        $this->assertTrue($ensured);
        $this->assertArrayHasKey(70, $this->repo->rows);
    }

    public function testMigrationIsASingleIdempotentCreateWithAllColumns(): void
    {
        $sql = trim((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/046_create_geracao_checks.sql'));
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS mod_opennfse_geracao_checks', $sql);
        foreach (['registrado_em', 'verificado_em', 'proxima_em', 'tentativas', 'erro_logado_em', 'PRIMARY KEY (invoiceid)', 'KEY idx_nfse_geracao_check_proxima (proxima_em)'] as $part) {
            $this->assertStringContainsString($part, $sql);
        }
        // Um único comando: o Migrator roda todo .sql a cada requisição.
        $this->assertSame(1, substr_count($sql, ';'));
        $this->assertStringNotContainsString('ALTER TABLE', $sql);
        $this->assertStringNotContainsString('UPDATE ', $sql);
    }
}
