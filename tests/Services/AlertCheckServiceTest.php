<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\AlertCheckService;
use PHPUnit\Framework\TestCase;

final class AlertCheckServiceTest extends TestCase
{
    private InMemoryAlertCheckRepository $repo;

    /** @var int[] */
    private array $checked = [];

    private int $schemaRuns = 0;

    private ?int $failFor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new InMemoryAlertCheckRepository();
        $this->checked = [];
        $this->schemaRuns = 0;
        $this->failFor = null;
    }

    private function service(): AlertCheckService
    {
        return new AlertCheckService(
            $this->repo,
            function (int $invoiceId): void {
                if ($invoiceId === $this->failFor) {
                    throw new \RuntimeException('falha na conferência');
                }
                $this->checked[] = $invoiceId;
            },
            function (): void {
                $this->schemaRuns++;
                $this->repo->tableMissing = false;
            }
        );
    }

    private function ts(string $value): int
    {
        return (int) strtotime($value);
    }

    public function testRequestIsIdempotentPerInvoice(): void
    {
        $service = $this->service();
        $service->request(10, $this->ts('2026-10-28 10:00:00'));
        $service->request(10, $this->ts('2026-10-28 10:05:00'));
        $service->request(0, $this->ts('2026-10-28 10:05:00'));

        $this->assertSame([10 => '2026-10-28 10:05:00'], $this->repo->rows);
    }

    public function testRequestCreatesTableWhenMissing(): void
    {
        $this->repo->tableMissing = true;
        $this->service()->request(10, $this->ts('2026-10-28 10:00:00'));

        $this->assertSame(1, $this->schemaRuns);
        $this->assertArrayHasKey(10, $this->repo->rows);
    }

    public function testProcessesOnlyRequestsOlderThanMinimumAge(): void
    {
        $service = $this->service();
        $service->request(10, $this->ts('2026-10-28 10:00:00'));
        $service->request(11, $this->ts('2026-10-28 10:04:50'));

        $processed = $service->processDue($this->ts('2026-10-28 10:05:00'));

        $this->assertSame(1, $processed);
        $this->assertSame([10], $this->checked);
        $this->assertSame([11 => '2026-10-28 10:04:50'], $this->repo->rows);
    }

    public function testCheckRunsOncePerRequest(): void
    {
        $service = $this->service();
        $service->request(10, $this->ts('2026-10-28 10:00:00'));

        $service->processDue($this->ts('2026-10-28 10:05:00'));
        $service->processDue($this->ts('2026-10-28 10:10:00'));

        $this->assertSame([10], $this->checked);
        $this->assertSame([], $this->repo->rows);
    }

    public function testRequestChangedDuringProcessingIsKeptForNextCycle(): void
    {
        $service = $this->service();
        $service->request(10, $this->ts('2026-10-28 10:00:00'));
        $this->repo->touchOnClaim = '2026-10-28 10:04:59';

        $this->assertSame(0, $service->processDue($this->ts('2026-10-28 10:05:00')));
        $this->assertSame([], $this->checked);
        $this->assertSame([10 => '2026-10-28 10:04:59'], $this->repo->rows);
    }

    public function testFailureInOneInvoiceDoesNotStopTheBatch(): void
    {
        $service = $this->service();
        $service->request(10, $this->ts('2026-10-28 10:00:00'));
        $service->request(11, $this->ts('2026-10-28 10:00:01'));
        $this->failFor = 10;

        $this->assertSame(1, $service->processDue($this->ts('2026-10-28 10:05:00')));
        $this->assertSame([11], $this->checked);
    }

    public function testRespectsBatchLimit(): void
    {
        $service = $this->service();
        for ($i = 1; $i <= 5; $i++) {
            $service->request($i, $this->ts('2026-10-28 10:00:0' . $i));
        }

        $this->assertSame(2, $service->processDue($this->ts('2026-10-28 10:05:00'), 2));
        $this->assertSame([1, 2], $this->checked);
    }
}
