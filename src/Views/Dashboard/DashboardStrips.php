<?php

declare(strict_types=1);

namespace OpenNfse\Views\Dashboard;

/**
 * Linha do tempo em faixas (Emitidas, Canceladas, Erros), cada uma com escala própria
 * e o mesmo eixo de datas.
 */
final class DashboardStrips
{
    /** Acima disso, as barras ficam finas e o eixo mostra só o dia 1 de cada mês. */
    public const LONG_RANGE_DAYS = 62;

    private const SERIES = [
        'emitidas' => 'Emitidas',
        'canceladas' => 'Canceladas',
        'erros_resolvidos' => 'Erros',
    ];

    /**
     * Topo redondo da escala: até 5 fica o próprio máximo; acima, arredonda para cima
     * em meia potência de 10 (7 → 10, 12 → 15, 29 → 30, 105 → 150). Nunca menor que 1.
     */
    public static function niceScale(int $max): int
    {
        if ($max <= 0) {
            return 1;
        }
        if ($max <= 5) {
            return $max;
        }
        if ($max <= 10) {
            return 10;
        }
        $step = (int) (10 ** (int) floor(log10($max))) / 2;

        return (int) (ceil($max / $step) * $step);
    }

    public static function percent(int $value, int $scale): float
    {
        if ($value <= 0 || $scale <= 0) {
            return 0.0;
        }

        return round(min(100.0, $value / $scale * 100), 1);
    }

    /**
     * @param array<int, array<string, mixed>> $series
     * @return array{
     *     long: bool,
     *     days: array<int, array{date: string, label: string, axis: string, weekend: bool}>,
     *     strips: array<int, array{key: string, label: string, total: int, scale: int, bars: array<int, array{percent: float, value: int}>}>
     * }
     */
    public static function build(array $series): array
    {
        $series = array_values($series);
        $count = count($series);
        $long = $count > self::LONG_RANGE_DAYS;
        $multiMonth = $count > 0
            && substr((string) ($series[0]['date'] ?? ''), 0, 7) !== substr((string) ($series[$count - 1]['date'] ?? ''), 0, 7);

        $days = [];
        foreach ($series as $i => $row) {
            $date = (string) ($row['date'] ?? '');
            $valid = DashboardPeriod::isDate($date);
            $dt = $valid ? new \DateTimeImmutable($date) : null;
            $days[] = [
                'date' => $date,
                'label' => $dt ? $dt->format('d/m') : '',
                'axis' => $dt ? self::axisLabel($dt, $i, $count, $long, $multiMonth) : '',
                'weekend' => $dt !== null && in_array((int) $dt->format('w'), [0, 6], true),
            ];
        }

        $strips = [];
        foreach (self::SERIES as $key => $label) {
            $values = array_map(static fn (array $row): int => (int) ($row[$key] ?? 0), $series);
            $scale = self::niceScale($values === [] ? 0 : max($values));
            $strips[] = [
                'key' => $key,
                'label' => $label,
                'total' => array_sum($values),
                'scale' => $scale,
                'bars' => array_map(static fn (int $v): array => ['percent' => self::percent($v, $scale), 'value' => $v], $values),
            ];
        }

        return ['long' => $long, 'days' => $days, 'strips' => $strips];
    }

    /**
     * Rótulo do eixo: até 14 dias, todos; até 62, o primeiro, os dias 1, 5, 10… e o último;
     * acima disso, o primeiro e o dia 1 de cada mês. Dia 1 num período com mais de um mês leva o mês.
     */
    private static function axisLabel(\DateTimeImmutable $dt, int $index, int $count, bool $long, bool $multiMonth): string
    {
        $day = (int) $dt->format('j');
        $isEdge = $index === 0 || $index === $count - 1;
        if ($long) {
            $show = $index === 0 || $day === 1;
        } elseif ($count <= 14) {
            $show = true;
        } else {
            $show = $isEdge || $day === 1 || $day % 5 === 0;
        }
        if (!$show) {
            return '';
        }

        return $multiMonth && ($day === 1 || $index === 0) ? $dt->format('d/m') : $dt->format('d');
    }
}
