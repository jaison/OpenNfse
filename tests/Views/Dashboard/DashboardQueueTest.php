<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Views\Dashboard;

use OpenNfse\Repositories\QueueRepository;
use OpenNfse\Views\Dashboard\DashboardQueue;
use PHPUnit\Framework\TestCase;

final class DashboardQueueTest extends TestCase
{
    private const NOW_TEXT = '2026-11-05 10:00:00';

    private static function now(): int
    {
        return (int) strtotime(self::NOW_TEXT);
    }

    public function testAge(): void
    {
        $this->assertSame('menos de 1 min', DashboardQueue::age(0));
        $this->assertSame('menos de 1 min', DashboardQueue::age(59));
        $this->assertSame('menos de 1 min', DashboardQueue::age(-30), 'relógio adiantado conta como zero');
        $this->assertSame('1 min', DashboardQueue::age(60));
        $this->assertSame('12 min', DashboardQueue::age(12 * 60 + 59));
        $this->assertSame('59 min', DashboardQueue::age(3599));
        $this->assertSame('1 h', DashboardQueue::age(3600));
        $this->assertSame('3 h', DashboardQueue::age(3 * 3600 + 1800));
        $this->assertSame('23 h', DashboardQueue::age(86399));
        $this->assertSame('1 dia', DashboardQueue::age(86400));
        $this->assertSame('2 dias', DashboardQueue::age(2 * 86400 + 5000));
    }

    public function testSecondsSince(): void
    {
        $this->assertSame(120, DashboardQueue::secondsSince('2026-11-05 09:58:00', self::now()));
        $this->assertSame(0, DashboardQueue::secondsSince('2026-11-05 10:05:00', self::now()));
        $this->assertNull(DashboardQueue::secondsSince('', self::now()));
        $this->assertNull(DashboardQueue::secondsSince('0000-00-00 00:00:00', self::now()));
        $this->assertNull(DashboardQueue::secondsSince('ontem à tarde', self::now()));
    }

    public function testCronFresh(): void
    {
        $cron = DashboardQueue::cronState('2026-11-05 09:58:00', 'whmcs_cron', self::now());

        $this->assertTrue($cron['ran']);
        $this->assertFalse($cron['stale']);
        $this->assertSame('Cron executado há 2 min (WHMCS)', $cron['text']);
        $this->assertSame('Cron há 2 min', $cron['short']);
        $this->assertNull(DashboardQueue::cronHealthItem($cron, 'cfg-url'));

        $this->assertSame('Cron executado há 2 min (CLI)', DashboardQueue::cronState('2026-11-05 09:58:00', 'standalone', self::now())['text']);
        $this->assertSame('Cron executado há 2 min', DashboardQueue::cronState('2026-11-05 09:58:00', '', self::now())['text']);
    }

    public function testCronTenMinuteBoundary(): void
    {
        $this->assertFalse(DashboardQueue::cronState('2026-11-05 09:50:00', 'whmcs_cron', self::now())['stale'], 'exatamente 10 min ainda está em dia');

        $cron = DashboardQueue::cronState('2026-11-05 09:49:59', 'whmcs_cron', self::now());
        $this->assertTrue($cron['stale']);
        $item = DashboardQueue::cronHealthItem($cron, 'cfg-url');
        $this->assertSame('danger', $item['tone']);
        $this->assertSame('Cron parado há 10 min', $item['title']);
        $this->assertSame('cfg-url', $item['href']);
        $this->assertSame([], $item['parts']);

        $this->assertSame(
            'Cron parado há 2 dias',
            DashboardQueue::cronHealthItem(DashboardQueue::cronState('2026-11-03 09:00:00', 'whmcs_cron', self::now()), 'x')['title']
        );
    }

    public function testCronNeverRun(): void
    {
        $cron = DashboardQueue::cronState('', '', self::now());

        $this->assertFalse($cron['ran']);
        $this->assertTrue($cron['stale']);
        $this->assertSame('Cron nunca executado', $cron['text']);
        $this->assertSame('Cron nunca executado', $cron['short']);
        $this->assertSame('Cron nunca executado', DashboardQueue::cronHealthItem($cron, 'cfg-url')['title']);
    }

    public function testCellsZero(): void
    {
        $cells = DashboardQueue::cells([], ['PENDING' => 'p', 'RUNNING' => 'r', 'WAIT_STATUS' => 'w', 'ERROR' => 'e']);

        $this->assertSame(['Pendentes', 'Processando', 'Aguardando status', 'Com erro'], array_column($cells, 'label'));
        $this->assertSame(['Pendentes', 'Processando', 'Aguard. status', 'Com erro'], array_column($cells, 'short'));
        $this->assertSame(['0', '0', '0', '0'], array_column($cells, 'value'));
        $this->assertSame(['zero', 'zero', 'zero', 'zero'], array_column($cells, 'tone'));
        $this->assertSame(['p', 'r', 'w', 'e'], array_column($cells, 'href'));
    }

    public function testCellsNonZero(): void
    {
        $cells = DashboardQueue::cells(['PENDING' => 3, 'RUNNING' => 1, 'WAIT_STATUS' => 0, 'ERROR' => 1200], []);

        $this->assertSame(['3', '1', '0', '1.200'], array_column($cells, 'value'));
        $this->assertSame(['', '', 'zero', 'danger'], array_column($cells, 'tone'), 'só "Com erro" acima de zero usa o destaque de perigo');
    }

    public function testOldestPendingText(): void
    {
        $this->assertSame('mais antigo há 12 min', DashboardQueue::oldestPendingText(['PENDING' => 3], '2026-11-05 09:48:00', self::now()));
        $this->assertSame('mais antigo há 2 dias', DashboardQueue::oldestPendingText(['PENDING' => 1], '2026-11-03 09:00:00', self::now()));
        $this->assertSame('', DashboardQueue::oldestPendingText(['PENDING' => 0], '2026-11-05 09:48:00', self::now()), 'sem PENDING, a data antiga é ignorada');
        $this->assertSame('', DashboardQueue::oldestPendingText([], '', self::now()));
        $this->assertSame('', DashboardQueue::oldestPendingText(['PENDING' => 2], '', self::now()));
    }

    public function testSnapshotFromRows(): void
    {
        $snapshot = QueueRepository::snapshotFromRows([
            (object) ['status' => 'PENDING', 'total' => '3', 'oldest' => '2026-11-05 09:48:00'],
            (object) ['status' => 'ERROR', 'total' => 2, 'oldest' => '2026-10-01 10:00:00'],
            ['status' => 'DONE', 'total' => 99, 'oldest' => '2026-01-01 00:00:00'],
        ]);

        $this->assertSame(['PENDING' => 3, 'RUNNING' => 0, 'WAIT_STATUS' => 0, 'ERROR' => 2], $snapshot['counts']);
        $this->assertSame('2026-11-05 09:48:00', $snapshot['oldest_pending']);
        $this->assertSame(
            ['counts' => ['PENDING' => 0, 'RUNNING' => 0, 'WAIT_STATUS' => 0, 'ERROR' => 0], 'oldest_pending' => ''],
            QueueRepository::snapshotFromRows([])
        );
    }
}
