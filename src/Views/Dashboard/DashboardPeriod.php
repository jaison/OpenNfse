<?php

declare(strict_types=1);

namespace OpenNfse\Views\Dashboard;

/**
 * Período da Dashboard: leitura dos parâmetros, rótulo e navegação por mês.
 *
 * Parâmetros aceitos na URL (os mesmos de antes):
 * - periodo: hoje, 7_dias, mes_atual (padrão), mes_anterior ou personalizado;
 * - data_inicial e data_final (Y-m-d), usados com periodo=personalizado.
 *
 * As setas de mês geram periodo=personalizado com o primeiro e o último dia do mês.
 */
final class DashboardPeriod
{
    public const PRESETS = ['hoje', '7_dias', 'mes_atual', 'mes_anterior', 'personalizado'];

    private const MONTHS = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
        7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
    ];

    /**
     * @return array{periodo: string, start: string, end: string}
     */
    public static function resolve(string $periodo, string $dataInicial, string $dataFinal, string $today): array
    {
        $periodo = trim($periodo);
        $todayDate = self::isDate($today) ? new \DateTimeImmutable($today) : new \DateTimeImmutable('today');
        $todayKey = $todayDate->format('Y-m-d');

        switch ($periodo) {
            case 'hoje':
                return ['periodo' => 'hoje', 'start' => $todayKey, 'end' => $todayKey];
            case '7_dias':
                return ['periodo' => '7_dias', 'start' => $todayDate->modify('-6 days')->format('Y-m-d'), 'end' => $todayKey];
            case 'mes_anterior':
                $first = $todayDate->modify('first day of last month');

                return ['periodo' => 'mes_anterior', 'start' => $first->format('Y-m-01'), 'end' => $first->format('Y-m-t')];
            case 'personalizado':
                $start = trim($dataInicial);
                $end = trim($dataFinal);
                if (!self::isDate($start)) {
                    $start = $todayDate->format('Y-m-01');
                }
                if (!self::isDate($end)) {
                    $end = $todayDate->format('Y-m-t');
                }
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }

                return ['periodo' => 'personalizado', 'start' => $start, 'end' => $end];
            default:
                return ['periodo' => 'mes_atual', 'start' => $todayDate->format('Y-m-01'), 'end' => $todayDate->format('Y-m-t')];
        }
    }

    public static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function isFullMonth(string $start, string $end): bool
    {
        if (!self::isDate($start) || !self::isDate($end) || substr($start, 8, 2) !== '01') {
            return false;
        }

        return (new \DateTimeImmutable($start))->format('Y-m-t') === $end;
    }

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month] ?? '';
    }

    /**
     * "Outubro 2026" para um mês inteiro, "05/11/2026" para um dia e o intervalo nos demais casos.
     */
    public static function label(string $start, string $end): string
    {
        if (!self::isDate($start) || !self::isDate($end)) {
            return '';
        }
        $s = new \DateTimeImmutable($start);
        $e = new \DateTimeImmutable($end);
        if (self::isFullMonth($start, $end)) {
            return self::monthName((int) $s->format('n')) . ' ' . $s->format('Y');
        }
        if ($start === $end) {
            return $s->format('d/m/Y');
        }
        if ($s->format('Y') === $e->format('Y')) {
            return $s->format('d/m') . ' – ' . $e->format('d/m/Y');
        }

        return $s->format('d/m/Y') . ' – ' . $e->format('d/m/Y');
    }

    /**
     * Mês anterior ao período. Num mês inteiro, é o mês de antes; num intervalo, o mês antes do mês da data final.
     *
     * @return array{start: string, end: string}
     */
    public static function previousMonth(string $start, string $end): array
    {
        $anchor = self::isFullMonth($start, $end) ? $start : $end;

        return self::monthRange(self::firstOfMonth($anchor)->modify('-1 month'));
    }

    /**
     * Próximo mês. Num mês inteiro, é o mês seguinte; num intervalo, o mês inteiro da data final.
     *
     * @return array{start: string, end: string}
     */
    public static function nextMonth(string $start, string $end): array
    {
        if (self::isFullMonth($start, $end)) {
            return self::monthRange(self::firstOfMonth($start)->modify('+1 month'));
        }

        return self::monthRange(self::firstOfMonth($end));
    }

    /**
     * Parâmetros de URL que reproduzem o período.
     *
     * @return array<string, string>
     */
    public static function queryParams(string $periodo, string $start, string $end): array
    {
        if ($periodo !== 'personalizado' && in_array($periodo, self::PRESETS, true)) {
            return ['periodo' => $periodo];
        }

        return ['periodo' => 'personalizado', 'data_inicial' => $start, 'data_final' => $end];
    }

    /**
     * Dia escolhido no calendário (parâmetro dia). Vazio quando inválido ou fora do período.
     */
    public static function selectedDay(string $dia, string $start, string $end): string
    {
        $dia = trim($dia);
        if (!self::isDate($dia) || $dia < $start || $dia > $end) {
            return '';
        }

        return $dia;
    }

    private static function firstOfMonth(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable(substr($date, 0, 7) . '-01');
    }

    /**
     * @return array{start: string, end: string}
     */
    private static function monthRange(\DateTimeImmutable $first): array
    {
        return ['start' => $first->format('Y-m-01'), 'end' => $first->format('Y-m-t')];
    }
}
