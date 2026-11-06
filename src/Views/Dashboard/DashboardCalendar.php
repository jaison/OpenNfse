<?php

declare(strict_types=1);

namespace OpenNfse\Views\Dashboard;

/**
 * Calendário de calor e médias por dia da semana, a partir da série diária do período
 * (ReportRepository::dashboardDailySeries: date, emitidas, canceladas, erros_resolvidos).
 */
final class DashboardCalendar
{
    public const WEEKDAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    /** Inicial do dia da semana, para o cabeçalho do calendário compacto. */
    public const WEEKDAY_LETTERS = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];

    private const MONTHS_SHORT = [
        1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez',
    ];

    /**
     * Faixa fixa de calor: 1 (0–7), 2 (8–13), 3 (14–18), 4 (19–23), 5 (24+).
     */
    public static function heatBand(int $count): int
    {
        if ($count >= 24) {
            return 5;
        }
        if ($count >= 19) {
            return 4;
        }
        if ($count >= 14) {
            return 3;
        }
        if ($count >= 8) {
            return 2;
        }

        return 1;
    }

    /**
     * Células do calendário, em semanas de domingo a sábado que cobrem o período.
     * Dias fora do período são células vazias. Num período com mais de um mês,
     * o primeiro dia e cada dia 1 levam o nome curto do mês.
     *
     * @param array<int, array<string, mixed>> $series
     * @return array<int, array{empty: bool, date: string, day: string, month: string, count: int,
     *     canceladas: int, erros: int, band: int, future: bool, selected: bool}>
     */
    public static function grid(array $series, string $start, string $end, string $today = '', string $selected = ''): array
    {
        if (!DashboardPeriod::isDate($start) || !DashboardPeriod::isDate($end) || $start > $end) {
            return [];
        }

        $byDate = self::indexByDate($series);
        $first = new \DateTimeImmutable($start);
        $last = new \DateTimeImmutable($end);
        $multiMonth = $first->format('Y-m') !== $last->format('Y-m');

        $cells = [];
        for ($i = 0, $lead = (int) $first->format('w'); $i < $lead; $i++) {
            $cells[] = self::emptyCell();
        }

        for ($cursor = $first; $cursor <= $last; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $row = $byDate[$key] ?? [];
            $count = (int) ($row['emitidas'] ?? 0);
            $month = '';
            if ($multiMonth && ($key === $start || $cursor->format('j') === '1')) {
                $month = self::MONTHS_SHORT[(int) $cursor->format('n')];
            }
            $cells[] = [
                'empty' => false,
                'date' => $key,
                'day' => $cursor->format('d'),
                'month' => $month,
                'count' => $count,
                'canceladas' => (int) ($row['canceladas'] ?? 0),
                'erros' => (int) ($row['erros_resolvidos'] ?? 0),
                'band' => self::heatBand($count),
                'future' => $today !== '' && $key > $today,
                'selected' => $selected !== '' && $key === $selected,
            ];
        }

        for ($i = (int) $last->format('w'); $i < 6; $i++) {
            $cells[] = self::emptyCell();
        }

        return $cells;
    }

    /**
     * Total, média por dia corrido (até hoje) e pico do período.
     *
     * @param array<int, array<string, mixed>> $series
     * @return array{total: int, days: int, average: float, peak: int, peak_date: string}
     */
    public static function summary(array $series, string $today = ''): array
    {
        $total = 0;
        $days = 0;
        $peak = 0;
        $peakDate = '';
        foreach ($series as $row) {
            $date = (string) ($row['date'] ?? '');
            $count = (int) ($row['emitidas'] ?? 0);
            $total += $count;
            if ($today !== '' && $date > $today) {
                continue;
            }
            $days++;
            if ($count > $peak) {
                $peak = $count;
                $peakDate = $date;
            }
        }

        return [
            'total' => $total,
            'days' => $days,
            'average' => $days > 0 ? (float) $total / $days : 0.0,
            'peak' => $peak,
            'peak_date' => $peakDate,
        ];
    }

    /**
     * Média de emitidas por dia da semana (0 = domingo), só com os dias corridos até hoje.
     * Dia da semana sem nenhum dia no período fica com média null. A maior média vale 100%.
     *
     * @param array<int, array<string, mixed>> $series
     * @return array<int, array{name: string, average: ?float, percent: float, weekend: bool}>
     */
    public static function weekdayAverages(array $series, string $today = ''): array
    {
        $sum = array_fill(0, 7, 0);
        $n = array_fill(0, 7, 0);
        foreach ($series as $row) {
            $date = (string) ($row['date'] ?? '');
            if (!DashboardPeriod::isDate($date) || ($today !== '' && $date > $today)) {
                continue;
            }
            $w = (int) (new \DateTimeImmutable($date))->format('w');
            $sum[$w] += (int) ($row['emitidas'] ?? 0);
            $n[$w]++;
        }

        $averages = [];
        $max = 0.0;
        for ($w = 0; $w < 7; $w++) {
            $averages[$w] = $n[$w] > 0 ? (float) $sum[$w] / $n[$w] : null;
            $max = max($max, (float) ($averages[$w] ?? 0.0));
        }

        $out = [];
        foreach ($averages as $w => $avg) {
            $out[$w] = [
                'name' => self::WEEKDAYS[$w],
                'average' => $avg,
                'percent' => $avg !== null && $max > 0 ? round($avg / $max * 100, 1) : 0.0,
                'weekend' => $w === 0 || $w === 6,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $series
     * @return array<string, array<string, mixed>>
     */
    public static function indexByDate(array $series): array
    {
        $out = [];
        foreach ($series as $row) {
            $date = (string) ($row['date'] ?? '');
            if ($date !== '') {
                $out[$date] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{empty: bool, date: string, day: string, month: string, count: int,
     *     canceladas: int, erros: int, band: int, future: bool, selected: bool}
     */
    private static function emptyCell(): array
    {
        return [
            'empty' => true,
            'date' => '',
            'day' => '',
            'month' => '',
            'count' => 0,
            'canceladas' => 0,
            'erros' => 0,
            'band' => 0,
            'future' => false,
            'selected' => false,
        ];
    }
}
