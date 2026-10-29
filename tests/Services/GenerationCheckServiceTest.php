<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\GenerationCheckService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class GenerationCheckServiceTest extends TestCase
{
    private const NOW = 1793188800; // 2026-10-28 12:00:00 UTC

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

    private function service(?callable $ensureSchema = null): GenerationCheckService
    {
        return new GenerationCheckService(
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

    private function register(int $invoiceId, int $daysAgo = 0): void
    {
        $this->repo->register($invoiceId, date('Y-m-d H:i:s', self::NOW - ($daysAgo * 86400) - 60));
    }

    private function queueOn(): array
    {
        return ['queue_enabled' => '1'];
    }

    public function testUnpaidIsHandledAndRemoved(): void
    {
        $this->register(10);
        $this->statuses[10] = 'Unpaid';

        $this->assertSame(1, $this->service()->processPending($this->queueOn(), self::NOW));
        $this->assertSame([10], $this->handlerCalls);
        $this->assertSame([], $this->repo->rows);
    }

    public function testRecentDraftStaysPendingAndOldDraftIsDropped(): void
    {
        $this->register(11, 2);
        $this->register(12, 31);
        $this->statuses[11] = 'Draft';
        $this->statuses[12] = 'Draft';

        $this->assertSame(0, $this->service()->processPending($this->queueOn(), self::NOW));
        $this->assertSame([], $this->handlerCalls);
        $this->assertArrayHasKey(11, $this->repo->rows);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW), $this->repo->rows[11]['verificado_em'], 'Rascunho vai para o fim da fila.');
        $this->assertArrayNotHasKey(12, $this->repo->rows);
    }

    public function testOtherStatusesAndMissingInvoiceAreDroppedWithoutAction(): void
    {
        foreach ([20 => 'Paid', 21 => 'Cancelled', 22 => 'Refunded', 23 => null] as $id => $status) {
            $this->register($id);
            $this->statuses[$id] = $status;
        }

        $this->service()->processPending($this->queueOn(), self::NOW);
        $this->assertSame([], $this->handlerCalls);
        $this->assertSame([], $this->repo->rows);
    }

    public function testAlreadyHandledIsDroppedSilently(): void
    {
        $this->register(30);
        $this->statuses[30] = 'Unpaid';
        $this->handled[30] = true;

        $this->service()->processPending($this->queueOn(), self::NOW);
        $this->assertSame([], $this->handlerCalls);
        $this->assertSame([], $this->repo->rows);
        $this->assertSame([], Capsule::$inserts['mod_opennfse_logs'] ?? [], 'Sem log.');
    }

    public function testInvoiceStillDraftForHandlerStaysPending(): void
    {
        $this->register(40);
        $this->statuses[40] = 'Unpaid';
        $this->handlerResult = false;

        $this->service()->processPending($this->queueOn(), self::NOW);
        $this->assertArrayHasKey(40, $this->repo->rows);
    }

    public function testHandlerFailureIsLoggedAndRetriedLater(): void
    {
        $this->register(41);
        $this->statuses[41] = 'Unpaid';
        $this->handlerError = new \RuntimeException('lock ocupado');

        $this->service()->processPending($this->queueOn(), self::NOW);
        $this->assertArrayHasKey(41, $this->repo->rows);
        $logs = Capsule::$inserts['mod_opennfse_logs'] ?? [];
        $this->assertCount(1, $logs);
        $this->assertSame('QUEUE_AUTO_CREATED_ERROR', $logs[0]['tipo']);
    }

    public function testQueueDisabledDoesNothing(): void
    {
        $this->register(50);
        $this->statuses[50] = 'Unpaid';

        $this->assertSame(0, $this->service()->processPending(['queue_enabled' => '0'], self::NOW));
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

    public function testMigrationIsIdempotent(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/046_create_geracao_checks.sql');
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS mod_opennfse_geracao_checks', $sql);
        $this->assertStringContainsString('PRIMARY KEY (invoiceid)', $sql);
    }
}
