<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Views\Dashboard;

use OpenNfse\Views\Dashboard\DashboardStrips;
use PHPUnit\Framework\TestCase;

final class DashboardStripsTest extends TestCase
{
    public function testNiceScale(): void
    {
        $this->assertSame(1, DashboardStrips::niceScale(0));
        $this->assertSame(1, DashboardStrips::niceScale(-3));
        $this->assertSame(1, DashboardStrips::niceScale(1));
        $this->assertSame(4, DashboardStrips::niceScale(4));
        $this->assertSame(10, DashboardStrips::niceScale(7));
        $this->assertSame(15, DashboardStrips::niceScale(12));
        $this->assertSame(30, DashboardStrips::niceScale(29));
        $this->assertSame(30, DashboardStrips::niceScale(30));
        $this->assertSame(150, DashboardStrips::niceScale(105));
    }

    public function testSharedScale(): void
    {
        $this->assertSame(5, DashboardStrips::sharedScale(0));
        $this->assertSame(5, DashboardStrips::sharedScale(-2));
        $this->assertSame(5, DashboardStrips::sharedScale(1));
        $this->assertSame(5, DashboardStrips::sharedScale(5));
        $this->assertSame(10, DashboardStrips::sharedScale(6));
        $this->assertSame(20, DashboardStrips::sharedScale(16));
        $this->assertSame(30, DashboardStrips::sharedScale(29));
        $this->assertSame(100, DashboardStrips::sharedScale(100));
        $this->assertSame(150, DashboardStrips::sharedScale(105));
    }

    public function testAllZerosHasNoDivisionByZero(): void
    {
        $series = [];
        for ($d = 1; $d <= 7; $d++) {
            $series[] = ['date' => sprintf('2026-11-%02d', $d), 'emitidas' => 0, 'canceladas' => 0, 'erros_resolvidos' => 0];
        }
        $data = DashboardStrips::build($series);

        $this->assertSame(5, $data['scale']);
        $this->assertSame(['emitidas' => 0, 'canceladas' => 0, 'erros' => 0], $data['totals']);
        $this->assertCount(7, $data['days']);
        foreach ($data['days'] as $day) {
            $this->assertSame([0.0, 0.0, 0.0], [$day['pct_emitidas'], $day['pct_canceladas'], $day['pct_erros']]);
        }
        $this->assertSame(0.0, DashboardStrips::percent(5, 0));
    }

    public function testThreeSeriesShareOneScale(): void
    {
        $series = [
            ['date' => '2026-10-02', 'emitidas' => 16, 'canceladas' => 4, 'erros_resolvidos' => 1],
            ['date' => '2026-10-03', 'emitidas' => 5, 'canceladas' => 2, 'erros_resolvidos' => 0],
        ];
        $data = DashboardStrips::build($series, '2026-10-03');

        $this->assertSame(20, $data['scale'], 'maior valor das três séries (16), em múltiplo de 5');
        $this->assertSame(['emitidas' => 21, 'canceladas' => 6, 'erros' => 1], $data['totals']);
        [$fri, $sat] = $data['days'];
        $this->assertSame([16, 4, 1], [$fri['emitidas'], $fri['canceladas'], $fri['erros']]);
        $this->assertSame([80.0, 20.0, 5.0], [$fri['pct_emitidas'], $fri['pct_canceladas'], $fri['pct_erros']], 'canceladas e erros na mesma escala das emitidas');
        $this->assertSame([25.0, 10.0, 0.0], [$sat['pct_emitidas'], $sat['pct_canceladas'], $sat['pct_erros']]);
        $this->assertFalse($fri['weekend']);
        $this->assertTrue($sat['weekend'], '03/10/2026 é sábado');
        $this->assertSame([false, true], [$fri['today'], $sat['today']]);
        $this->assertSame(['02', '03'], [$fri['axis'], $sat['axis']]);
        $this->assertSame('02/10', $fri['label']);
    }

    public function testFutureDays(): void
    {
        $series = [
            ['date' => '2026-11-04', 'emitidas' => 3],
            ['date' => '2026-11-05', 'emitidas' => 2],
            ['date' => '2026-11-06', 'emitidas' => 0],
        ];
        $this->assertSame([false, false, true], array_column(DashboardStrips::build($series, '2026-11-05')['days'], 'future'));
        $this->assertSame([false, false, false], array_column(DashboardStrips::build($series)['days'], 'future'));
    }

    public function testAxisLabelsForMonthAndLongRange(): void
    {
        $series = [];
        $d = new \DateTimeImmutable('2026-10-01');
        for ($i = 0; $i < 31; $i++) {
            $series[] = ['date' => $d->format('Y-m-d')];
            $d = $d->modify('+1 day');
        }
        $axis = array_column(DashboardStrips::build($series)['days'], 'axis');
        $this->assertSame('01', $axis[0]);
        $this->assertSame('', $axis[1]);
        $this->assertSame('05', $axis[4]);
        $this->assertSame('31', $axis[30]);
        $this->assertFalse(DashboardStrips::build($series)['long']);

        $long = [];
        $d = new \DateTimeImmutable('2026-09-15');
        for ($i = 0; $i < 90; $i++) {
            $long[] = ['date' => $d->format('Y-m-d')];
            $d = $d->modify('+1 day');
        }
        $data = DashboardStrips::build($long);
        $this->assertTrue($data['long']);
        $labels = array_values(array_filter(array_column($data['days'], 'axis')));
        $this->assertSame(['15/09', '01/10', '01/11', '01/12'], $labels);
    }
}
