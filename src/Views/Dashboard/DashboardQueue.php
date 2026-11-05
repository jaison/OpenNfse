<?php

declare(strict_types=1);

namespace OpenNfse\Views\Dashboard;

/**
 * Card "Fila agora": estado atual da fila (sem o filtro de período da Dashboard) e do cron.
 *
 * Entradas:
 * - QueueRepository::currentSnapshot(): contagem por status e created_at do PENDING mais antigo;
 * - cron_last_run_at e cron_last_source da configuração, gravados pelo CronService.
 *
 * "Com erro" conta só ERROR: quando a fatura é emitida ou reprocessada, o erro antigo vira RESOLVIDO.
 */
final class DashboardQueue
{
    /** Sem execução do cron há mais que isso, a barra de saúde mostra "Cron parado". */
    public const CRON_STALE_MINUTES = 10;

    /** Status da fila que o card mostra, com o rótulo. */
    public const STATUSES = [
        'PENDING' => 'Pendentes',
        'RUNNING' => 'Processando',
        'WAIT_STATUS' => 'Aguardando status',
        'ERROR' => 'Com erro',
    ];

    /** Rótulo curto no card, quando o nome do status não cabe (o nome completo vai no title). */
    private const SHORT_LABELS = [
        'WAIT_STATUS' => 'Aguard. status',
    ];

    private const CRON_SOURCES = [
        'whmcs_cron' => 'WHMCS',
        'standalone' => 'CLI',
    ];

    /**
     * Tempo decorrido em texto curto: "menos de 1 min", "12 min", "3 h", "1 dia", "2 dias".
     * Horário no futuro (relógio adiantado) conta como zero.
     */
    public static function age(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return 'menos de 1 min';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' h';
        }
        $days = intdiv($seconds, 86400);

        return $days . ($days === 1 ? ' dia' : ' dias');
    }

    /**
     * Segundos desde $datetime (Y-m-d H:i:s), ou null quando vazio ou ilegível.
     */
    public static function secondsSince(string $datetime, int $now): ?int
    {
        $datetime = trim($datetime);
        if ($datetime === '' || str_starts_with($datetime, '0000-00-00')) {
            return null;
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return null;
        }

        return max(0, $now - $ts);
    }

    /**
     * text é a frase completa ("Cron executado há 2 min (WHMCS)"); short, a versão do rodapé do card ("Cron há 2 min").
     *
     * @return array{ran: bool, seconds: int, stale: bool, text: string, short: string}
     */
    public static function cronState(string $lastRunAt, string $source, int $now): array
    {
        $seconds = self::secondsSince($lastRunAt, $now);
        if ($seconds === null) {
            return ['ran' => false, 'seconds' => 0, 'stale' => true, 'text' => 'Cron nunca executado', 'short' => 'Cron nunca executado'];
        }

        $source = trim($source);
        $sourceLabel = self::CRON_SOURCES[$source] ?? $source;
        $text = 'Cron executado há ' . self::age($seconds) . ($sourceLabel !== '' ? ' (' . $sourceLabel . ')' : '');

        return [
            'ran' => true,
            'seconds' => $seconds,
            'stale' => $seconds > self::CRON_STALE_MINUTES * 60,
            'text' => $text,
            'short' => 'Cron há ' . self::age($seconds),
        ];
    }

    /**
     * Item crítico da barra de saúde quando o cron está parado ou nunca rodou.
     *
     * @param array{ran: bool, seconds: int, stale: bool, text: string, short: string} $cron
     * @return array{tone: string, icon: string, title: string, text: string, action: string, href: string,
     *     parts: array<int, array{label: string, href: string}>}|null
     */
    public static function cronHealthItem(array $cron, string $href): ?array
    {
        if (!$cron['stale']) {
            return null;
        }

        return [
            'tone' => 'danger',
            'icon' => 'alert',
            'title' => $cron['ran'] ? 'Cron parado há ' . self::age($cron['seconds']) : 'Cron nunca executado',
            'text' => 'Sem o cron, a fila, as consultas de status e os alertas não andam.',
            'action' => $href !== '' ? 'Ver configuração do cron' : '',
            'href' => $href,
            'parts' => [],
        ];
    }

    /**
     * Itens do card, um por status, com link para a Fila filtrada. label é o nome do status;
     * short, o rótulo mostrado no card ("Aguard. status").
     *
     * @param array<string, int> $counts contagem por status (PENDING, RUNNING, WAIT_STATUS, ERROR)
     * @param array<string, string> $urls link por status
     * @return array<int, array{label: string, short: string, value: string, tone: string, href: string}>
     */
    public static function cells(array $counts, array $urls): array
    {
        $cells = [];
        foreach (self::STATUSES as $status => $label) {
            $n = max(0, (int) ($counts[$status] ?? 0));
            $cells[] = [
                'label' => $label,
                'short' => self::SHORT_LABELS[$status] ?? $label,
                'value' => number_format($n, 0, ',', '.'),
                'tone' => $n === 0 ? 'zero' : ($status === 'ERROR' ? 'danger' : ''),
                'href' => (string) ($urls[$status] ?? ''),
            ];
        }

        return $cells;
    }

    /**
     * "mais antigo há 12 min" para o PENDING mais antigo; vazio quando não há pendentes.
     *
     * @param array<string, int> $counts
     */
    public static function oldestPendingText(array $counts, string $oldestPending, int $now): string
    {
        if ((int) ($counts['PENDING'] ?? 0) <= 0) {
            return '';
        }
        $seconds = self::secondsSince($oldestPending, $now);

        return $seconds === null ? '' : 'mais antigo há ' . self::age($seconds);
    }
}
