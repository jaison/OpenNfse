<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Views\Dashboard;

use OpenNfse\Views\Dashboard\DashboardPeriod;
use PHPUnit\Framework\TestCase;

final class DashboardPeriodTest extends TestCase
{
    public function testPresets(): void
    {
        $today = '2026-11-05';
        $this->assertSame(['periodo' => 'hoje', 'start' => $today, 'end' => $today], DashboardPeriod::resolve('hoje', '', '', $today));
        $this->assertSame(['periodo' => '7_dias', 'start' => '2026-10-30', 'end' => $today], DashboardPeriod::resolve('7_dias', '', '', $today));
        $this->assertSame(['periodo' => 'mes_atual', 'start' => '2026-11-01', 'end' => '2026-11-30'], DashboardPeriod::resolve('', '', '', $today));
        $this->assertSame(['periodo' => 'mes_atual', 'start' => '2026-11-01', 'end' => '2026-11-30'], DashboardPeriod::resolve('qualquer', '', '', $today));
        $this->assertSame(['periodo' => 'mes_anterior', 'start' => '2026-10-01', 'end' => '2026-10-31'], DashboardPeriod::resolve('mes_anterior', '', '', $today));
        $this->assertSame(['periodo' => 'mes_anterior', 'start' => '2026-12-01', 'end' => '2026-12-31'], DashboardPeriod::resolve('mes_anterior', '', '', '2027-01-15'));
    }

    public function testCustomRangeValidatesAndSwaps(): void
    {
        $today = '2026-11-05';
        $this->assertSame(
            ['periodo' => 'personalizado', 'start' => '2026-10-10', 'end' => '2026-11-02'],
            DashboardPeriod::resolve('personalizado', '2026-11-02', '2026-10-10', $today)
        );
        $this->assertSame(
            ['periodo' => 'personalizado', 'start' => '2026-11-01', 'end' => '2026-11-30'],
            DashboardPeriod::resolve('personalizado', '2026-02-30', 'abc', $today)
        );
    }

    public function testLabel(): void
    {
        $this->assertSame('Outubro 2026', DashboardPeriod::label('2026-10-01', '2026-10-31'));
        $this->assertSame('Fevereiro 2028', DashboardPeriod::label('2028-02-01', '2028-02-29'));
        $this->assertSame('05/11/2026', DashboardPeriod::label('2026-11-05', '2026-11-05'));
        $this->assertSame('30/10 – 05/11/2026', DashboardPeriod::label('2026-10-30', '2026-11-05'));
        $this->assertSame('28/12/2026 – 03/01/2027', DashboardPeriod::label('2026-12-28', '2027-01-03'));
        $this->assertSame('01/10 – 30/10/2026', DashboardPeriod::label('2026-10-01', '2026-10-30'));
    }

    public function testArrowsAcrossYearBoundary(): void
    {
        $this->assertSame(['start' => '2027-01-01', 'end' => '2027-01-31'], DashboardPeriod::nextMonth('2026-12-01', '2026-12-31'));
        $this->assertSame(['start' => '2026-12-01', 'end' => '2026-12-31'], DashboardPeriod::previousMonth('2027-01-01', '2027-01-31'));
        $this->assertSame(['start' => '2026-11-01', 'end' => '2026-11-30'], DashboardPeriod::previousMonth('2026-12-01', '2026-12-31'));
        $this->assertSame(['start' => '2027-02-01', 'end' => '2027-02-28'], DashboardPeriod::nextMonth('2027-01-01', '2027-01-31'));
    }

    public function testArrowsFromPartialRange(): void
    {
        // 7 dias de 30/10 a 05/11: a seta para trás vai a outubro, a seta para frente ao mês inteiro de novembro.
        $this->assertSame(['start' => '2026-10-01', 'end' => '2026-10-31'], DashboardPeriod::previousMonth('2026-10-30', '2026-11-05'));
        $this->assertSame(['start' => '2026-11-01', 'end' => '2026-11-30'], DashboardPeriod::nextMonth('2026-10-30', '2026-11-05'));
    }

    public function testQueryParams(): void
    {
        $this->assertSame(['periodo' => 'hoje'], DashboardPeriod::queryParams('hoje', '2026-11-05', '2026-11-05'));
        $this->assertSame(
            ['periodo' => 'personalizado', 'data_inicial' => '2026-10-01', 'data_final' => '2026-10-31'],
            DashboardPeriod::queryParams('personalizado', '2026-10-01', '2026-10-31')
        );
    }

    public function testSelectedDay(): void
    {
        $this->assertSame('2026-10-23', DashboardPeriod::selectedDay('2026-10-23', '2026-10-01', '2026-10-31'));
        $this->assertSame('', DashboardPeriod::selectedDay('2026-11-01', '2026-10-01', '2026-10-31'));
        $this->assertSame('', DashboardPeriod::selectedDay('2026-10-32', '2026-10-01', '2026-10-31'));
        $this->assertSame('', DashboardPeriod::selectedDay('<script>', '2026-10-01', '2026-10-31'));
    }
}
