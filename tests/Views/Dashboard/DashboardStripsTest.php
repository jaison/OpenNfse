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

    public function testAllZerosHasNoDivisionByZero(): void
    {
        $series = [];
        for ($d = 1; $d <= 7; $d++) {
            $series[] = ['date' => sprintf('2026-11-%02d', $d), 'emitidas' => 0, 'canceladas' => 0, 'erros_resolvidos' => 0];
        }
        $data = DashboardStrips::build($series);

        $this->assertCount(3, $data['strips']);
        foreach ($data['strips'] as $strip) {
            $this->assertSame(0, $strip['total']);
            $this->assertSame(1, $strip['scale']);
            $this->assertCount(7, $strip['bars']);
            foreach ($strip['bars'] as $bar) {
                $this->assertSame(0.0, $bar['percent']);
            }
        }
        $this->assertSame(0.0, DashboardStrips::percent(5, 0));
    }

    public function testEachStripHasItsOwnScaleAndWeekends(): void
    {
        $series = [
            ['date' => '2026-10-02', 'emitidas' => 29, 'canceladas' => 4, 'erros_resolvidos' => 1],
            ['date' => '2026-10-03', 'emitidas' => 15, 'canceladas' => 2, 'erros_resolvidos' => 0],
        ];
        $data = DashboardStrips::build($series);

        [$em, $ca, $er] = $data['strips'];
        $this->assertSame(['Emitidas', 30, 44], [$em['label'], $em['scale'], $em['total']]);
        $this->assertSame(96.7, $em['bars'][0]['percent']);
        $this->assertSame(50.0, $em['bars'][1]['percent']);
        $this->assertSame(['Canceladas', 4, 100.0], [$ca['label'], $ca['scale'], $ca['bars'][0]['percent']]);
        $this->assertSame(['Erros (incl. resolvidos)', 1, 100.0], [$er['label'], $er['scale'], $er['bars'][0]['percent']]);
        $this->assertFalse($data['days'][0]['weekend']);
        $this->assertTrue($data['days'][1]['weekend'], '03/10/2026 é sábado');
        $this->assertSame(['02', '03'], [$data['days'][0]['axis'], $data['days'][1]['axis']]);
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
