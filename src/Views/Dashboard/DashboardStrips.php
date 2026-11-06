<?php

declare(strict_types=1);

namespace OpenNfse\Views\Dashboard;

/**
 * Linha do tempo: um gráfico só, com as três séries (Emitidas, Canceladas, Erros incl. resolvidos)
 * na mesma escala e o mesmo eixo de datas. Por dia, a barra larga de emitidas e, na frente dela,
 * duas barras finas de canceladas e erros.
 */
final class DashboardStrips
{
    /** Acima disso, o gráfico ganha largura mínima (rolagem horizontal) e o eixo mostra só o dia 1 de cada mês. */
    public const LONG_RANGE_DAYS = 62;

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

    /**
     * Escala comum às três séries: o maior valor arredondado para cima em múltiplo de 5 (mínimo 5);
     * acima de 100, a meia potência de 10 de niceScale (105 → 150).
     */
    public static function sharedScale(int $max): int
    {
        if ($max <= 0) {
            return 5;
        }
        if ($max > 100) {
            return self::niceScale($max);
        }

        return (int) (ceil($max / 5) * 5);
    }

    public static function percent(int $value, int $scale): float
    {
        if ($value <= 0 || $scale <= 0) {
            return 0.0;
        }

        return round(min(100.0, $value / $scale * 100), 1);
    }

    /**
     * Dias depois de $today (Y-m-d) saem com future = true e o próprio dia com today = true.
     *
     * @param array<int, array<string, mixed>> $series
     * @return array{
     *     long: bool,
     *     scale: int,
     *     totals: array{emitidas: int, canceladas: int, erros: int},
     *     days: array<int, array{date: string, label: string, axis: string, weekend: bool, future: bool, today: bool,
     *         emitidas: int, canceladas: int, erros: int, pct_emitidas: float, pct_canceladas: float, pct_erros: float}>
     * }
     */
    public static function build(array $series, string $today = ''): array
    {
        $series = array_values($series);
        $count = count($series);
        $long = $count > self::LONG_RANGE_DAYS;
        $multiMonth = $count > 0
            && substr((string) ($series[0]['date'] ?? ''), 0, 7) !== substr((string) ($series[$count - 1]['date'] ?? ''), 0, 7);

        $max = 0;
        $totals = ['emitidas' => 0, 'canceladas' => 0, 'erros' => 0];
        $rows = [];
        foreach ($series as $row) {
            $values = [
                'emitidas' => max(0, (int) ($row['emitidas'] ?? 0)),
                'canceladas' => max(0, (int) ($row['canceladas'] ?? 0)),
                'erros' => max(0, (int) ($row['erros_resolvidos'] ?? 0)),
            ];
            foreach ($values as $key => $value) {
                $totals[$key] += $value;
                $max = max($max, $value);
            }
            $rows[] = $values;
        }
        $scale = self::sharedScale($max);

        $days = [];
        foreach ($series as $i => $row) {
            $date = (string) ($row['date'] ?? '');
            $dt = DashboardPeriod::isDate($date) ? new \DateTimeImmutable($date) : null;
            $values = $rows[$i];
            $days[] = [
                'date' => $date,
                'label' => $dt ? $dt->format('d/m') : '',
                'axis' => $dt ? self::axisLabel($dt, $i, $count, $long, $multiMonth) : '',
                'weekend' => $dt !== null && in_array((int) $dt->format('w'), [0, 6], true),
                'future' => $today !== '' && $date > $today,
                'today' => $today !== '' && $date === $today,
                'emitidas' => $values['emitidas'],
                'canceladas' => $values['canceladas'],
                'erros' => $values['erros'],
                'pct_emitidas' => self::percent($values['emitidas'], $scale),
                'pct_canceladas' => self::percent($values['canceladas'], $scale),
                'pct_erros' => self::percent($values['erros'], $scale),
            ];
        }

        return ['long' => $long, 'scale' => $scale, 'totals' => $totals, 'days' => $days];
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
