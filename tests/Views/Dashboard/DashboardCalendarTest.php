<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Views\Dashboard;

use OpenNfse\Views\Dashboard\DashboardCalendar;
use PHPUnit\Framework\TestCase;

final class DashboardCalendarTest extends TestCase
{
    /**
     * @param array<int, int> $emitidas
     * @return array<int, array<string, mixed>>
     */
    private static function series(string $start, array $emitidas, array $canceladas = [], array $erros = []): array
    {
        $out = [];
        $d = new \DateTimeImmutable($start);
        foreach ($emitidas as $i => $v) {
            $out[] = [
                'date' => $d->format('Y-m-d'),
                'label' => $d->format('d/m'),
                'emitidas' => $v,
                'canceladas' => $canceladas[$i] ?? 0,
                'erros_resolvidos' => $erros[$i] ?? 0,
            ];
            $d = $d->modify('+1 day');
        }

        return $out;
    }

    public function testFebruary2028LeapYear(): void
    {
        // 01/02/2028 é terça; 29/02/2028 também.
        $cells = DashboardCalendar::grid(self::series('2028-02-01', array_fill(0, 29, 1)), '2028-02-01', '2028-02-29');

        $this->assertCount(35, $cells);
        $this->assertTrue($cells[0]['empty']);
        $this->assertTrue($cells[1]['empty']);
        $this->assertSame('2028-02-01', $cells[2]['date']);
        $this->assertSame('2028-02-29', $cells[30]['date']);
        $this->assertSame(29, count(array_filter($cells, static fn (array $c): bool => !$c['empty'])));
        $this->assertTrue($cells[31]['empty']);
        $this->assertSame('', $cells[2]['month'], 'mês único: sem nome do mês nas células');
    }

    public function testOctober2026StartsOnThursday(): void
    {
        $series = self::series('2026-10-01', array_fill(0, 31, 3), [7 => 4], [9 => 1]);
        $cells = DashboardCalendar::grid($series, '2026-10-01', '2026-10-31', '2026-11-05', '2026-10-23');

        $this->assertCount(35, $cells);
        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($cells[$i]['empty']);
        }
        $this->assertFalse($cells[4]['empty']);
        $this->assertSame('2026-10-01', $cells[4]['date']);
        $this->assertSame('01', $cells[4]['day']);
        $this->assertSame('', $cells[4]['month'], 'mês único não repete o nome do mês');
        $this->assertSame('2026-10-31', $cells[34]['date']);
        $this->assertSame(4, $cells[4 + 7]['canceladas']);
        $this->assertSame(1, $cells[4 + 9]['erros']);
        $this->assertTrue($cells[4 + 22]['selected']);
        $this->assertSame(1, array_sum(array_map(static fn ($c) => $c['selected'] ? 1 : 0, $cells)));
        $this->assertFalse($cells[34]['future']);
    }

    public function testSevenDaysAcrossMonths(): void
    {
        $series = self::series('2026-10-30', [1, 2, 3, 4, 5, 6, 7]);
        $cells = DashboardCalendar::grid($series, '2026-10-30', '2026-11-05', '2026-11-05');

        // 30/10/2026 é sexta (5 vazias antes), 05/11 é quinta (2 vazias depois).
        $this->assertCount(14, $cells);
        $this->assertSame(5, count(array_filter(array_slice($cells, 0, 5), static fn ($c) => $c['empty'])));
        $this->assertSame('2026-10-30', $cells[5]['date']);
        $this->assertSame('out', $cells[5]['month']);
        $this->assertSame('2026-11-01', $cells[7]['date']);
        $this->assertSame('nov', $cells[7]['month']);
        $this->assertSame('', $cells[8]['month']);
        $this->assertTrue($cells[12]['empty']);
        $this->assertTrue($cells[13]['empty']);
    }

    public function testCustomRangeAcrossTwoMonths(): void
    {
        $series = self::series('2026-09-20', array_fill(0, 26, 10));
        $cells = DashboardCalendar::grid($series, '2026-09-20', '2026-10-15', '2026-10-10');

        // 20/09/2026 é domingo: sem vazias antes. 15/10 é quinta: 2 vazias depois.
        $this->assertSame('2026-09-20', $cells[0]['date']);
        $this->assertSame('set', $cells[0]['month']);
        $this->assertCount(28, $cells);
        $real = array_values(array_filter($cells, static fn ($c) => !$c['empty']));
        $this->assertCount(26, $real);
        $oct1 = array_values(array_filter($real, static fn ($c) => $c['date'] === '2026-10-01'))[0];
        $this->assertSame('out', $oct1['month']);
        $this->assertSame(2, $oct1['band']);
        $future = array_values(array_filter($real, static fn ($c) => $c['future']));
        $this->assertSame('2026-10-11', $future[0]['date']);
        $this->assertCount(5, $future);
    }

    public function testSingleDay(): void
    {
        $cells = DashboardCalendar::grid(self::series('2026-11-05', [9]), '2026-11-05', '2026-11-05', '2026-11-05');

        $this->assertCount(7, $cells);
        $real = array_values(array_filter($cells, static fn ($c) => !$c['empty']));
        $this->assertCount(1, $real);
        $this->assertSame(9, $real[0]['count']);
        $this->assertSame(2, $real[0]['band']);
        $this->assertFalse($cells[4]['empty'], '05/11/2026 é quinta');
    }

    public function testHeatBandBoundaries(): void
    {
        $this->assertSame(1, DashboardCalendar::heatBand(0));
        $this->assertSame(1, DashboardCalendar::heatBand(7));
        $this->assertSame(2, DashboardCalendar::heatBand(8));
        $this->assertSame(2, DashboardCalendar::heatBand(13));
        $this->assertSame(3, DashboardCalendar::heatBand(14));
        $this->assertSame(3, DashboardCalendar::heatBand(18));
        $this->assertSame(4, DashboardCalendar::heatBand(19));
        $this->assertSame(4, DashboardCalendar::heatBand(23));
        $this->assertSame(5, DashboardCalendar::heatBand(24));
        $this->assertSame(5, DashboardCalendar::heatBand(300));
    }

    public function testSummaryUsesElapsedDays(): void
    {
        $series = self::series('2026-11-01', [10, 20, 5, 0, 0]);
        $summary = DashboardCalendar::summary($series, '2026-11-03');

        $this->assertSame(35, $summary['total']);
        $this->assertSame(3, $summary['days']);
        $this->assertEqualsWithDelta(35 / 3, $summary['average'], 0.0001);
        $this->assertSame(20, $summary['peak']);
        $this->assertSame('2026-11-02', $summary['peak_date']);
    }

    public function testWeekdayAverages(): void
    {
        // Outubro de 2026, protótipo C.
        $em = [18, 18, 11, 10, 17, 20, 17, 23, 14, 15, 8, 8, 16, 21, 12, 17, 12, 14, 20, 15, 17, 13, 29, 14, 5, 20, 18, 15, 7, 13, 18];
        $rows = DashboardCalendar::weekdayAverages(self::series('2026-10-01', $em), '2026-11-05');

        $this->assertSame('Dom', $rows[0]['name']);
        $this->assertTrue($rows[0]['weekend']);
        $this->assertTrue($rows[6]['weekend']);
        $this->assertFalse($rows[3]['weekend']);
        // Domingos: 4, 11, 18, 25 → 10, 8, 14, 5.
        $this->assertEqualsWithDelta(9.25, $rows[0]['average'], 0.0001);
        // Sextas: 2, 9, 16, 23, 30 → 18, 14, 17, 29, 13.
        $this->assertEqualsWithDelta(18.2, $rows[5]['average'], 0.0001);
        $max = max(array_map(static fn ($r) => $r['average'], $rows));
        foreach ($rows as $row) {
            if ($row['average'] === $max) {
                $this->assertSame(100.0, $row['percent']);
            }
        }
    }

    public function testWeekdayAveragesWithMissingWeekdays(): void
    {
        $rows = DashboardCalendar::weekdayAverages(self::series('2026-11-05', [0]), '2026-11-05');

        $this->assertSame(0.0, $rows[4]['average']);
        $this->assertSame(0.0, $rows[4]['percent']);
        $this->assertNull($rows[0]['average']);
    }
}
