<?php

declare(strict_types=1);

namespace OpenNfse\Views\Dashboard;

/**
 * Atividade recente: junta emissões, cancelamentos e erros numa lista só, da mais nova para a mais antiga.
 *
 * Entradas (linhas já lidas pelo ReportRepository):
 * - dashboardRecentEmitidas: invoiceid, numero_nf, data, companyname, firstname, lastname;
 * - dashboardRecentCancelamentos: os mesmos campos, com data = cancelado_em;
 * - dashboardRecentIssues: invoiceid, status, erro, data e os campos do cliente.
 */
final class DashboardFeed
{
    public const FILTERS = ['tudo', 'emitidas', 'canceladas', 'erros'];

    public const LIMIT = 10;

    public static function filter(string $value): string
    {
        $value = trim($value);

        return in_array($value, self::FILTERS, true) ? $value : 'tudo';
    }

    /**
     * Quais listas o filtro precisa ler.
     *
     * @return array{emitidas: bool, canceladas: bool, erros: bool}
     */
    public static function sources(string $filter): array
    {
        $filter = self::filter($filter);

        return [
            'emitidas' => $filter === 'tudo' || $filter === 'emitidas',
            'canceladas' => $filter === 'tudo' || $filter === 'canceladas',
            'erros' => $filter === 'tudo' || $filter === 'erros',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $emitidas
     * @param array<int, array<string, mixed>> $canceladas
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array{type: string, data: string, invoiceid: int, title: string, client: string}>
     */
    public static function merge(array $emitidas, array $canceladas, array $issues, string $filter = 'tudo', int $limit = self::LIMIT): array
    {
        $sources = self::sources($filter);
        $items = [];

        if ($sources['emitidas']) {
            foreach ($emitidas as $row) {
                $numero = trim((string) ($row['numero_nf'] ?? ''));
                $items[] = self::item('emitida', $row, $numero !== '' ? 'NFS-e ' . $numero . ' emitida' : 'NFS-e emitida');
            }
        }

        if ($sources['canceladas']) {
            foreach ($canceladas as $row) {
                $numero = trim((string) ($row['numero_nf'] ?? ''));
                $items[] = self::item('cancelada', $row, $numero !== '' ? 'NFS-e ' . $numero . ' cancelada' : 'NFS-e cancelada');
            }
        }

        if ($sources['erros']) {
            foreach (self::latestIssuePerInvoice($issues) as $row) {
                $items[] = self::item('erro', $row, self::issueTitle($row));
            }
        }

        usort($items, static fn (array $a, array $b): int => strcmp($b['data'], $a['data']));

        return array_slice($items, 0, max(1, $limit));
    }

    /**
     * Um erro por fatura, o mais recente: a mesma fatura pode vir da nota e da fila.
     * Linhas sem fatura ficam todas.
     *
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    public static function latestIssuePerInvoice(array $issues): array
    {
        $byInvoice = [];
        $out = [];
        foreach ($issues as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            if ($invoiceId <= 0) {
                $out[] = $row;
                continue;
            }
            $data = trim((string) ($row['data'] ?? ''));
            if (!isset($byInvoice[$invoiceId]) || strcmp($data, trim((string) ($byInvoice[$invoiceId]['data'] ?? ''))) > 0) {
                $byInvoice[$invoiceId] = $row;
            }
        }

        return array_merge(array_values($byInvoice), $out);
    }

    /**
     * "Erro na requisição: 400 Bad Request — resolvido", "Rejeitada: <mensagem>" ou a mensagem.
     *
     * @param array<string, mixed> $row
     */
    public static function issueTitle(array $row): string
    {
        $message = self::normalizeIssueMessage((string) ($row['erro'] ?? ''));
        if ($message === '-') {
            $message = 'Erro sem mensagem';
        }
        $status = strtoupper(trim((string) ($row['status'] ?? '')));

        return match ($status) {
            'RESOLVIDO' => $message . ' — resolvido',
            'REJEITADA' => 'Rejeitada: ' . $message,
            default => $message,
        };
    }

    /**
     * Resume a mensagem de erro da API para caber numa linha (mesma regra de antes na Dashboard).
     */
    public static function normalizeIssueMessage(string $error): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $error));
        if ($normalized === '') {
            return '-';
        }

        if (preg_match('/(?:Client|Server) error:\s*`[A-Z]+\s+[^`]+`\s+resulted in a\s+`(\d{3}\s+[A-Za-z ]+)`/i', $normalized, $matches) === 1) {
            $statusSummary = trim((string) ($matches[1] ?? ''));
            if ($statusSummary !== '') {
                return 'Erro na requisição: ' . $statusSummary;
            }
        }

        $normalized = (string) preg_replace_callback('/https?:\/\/[^\s]+/i', static function (array $matches): string {
            $host = parse_url((string) ($matches[0] ?? ''), PHP_URL_HOST);
            if (is_string($host) && trim($host) !== '') {
                return $host;
            }

            return 'URL omitida';
        }, $normalized);
        $normalized = str_replace([' for url ', ' for URI ', ' for uri '], ' - ', $normalized);
        $normalized = trim((string) preg_replace('/\s+/', ' ', $normalized), " \t\n\r\0\x0B-");

        if (mb_strlen($normalized) > 110) {
            $normalized = rtrim(mb_substr($normalized, 0, 107)) . '...';
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function clientName(array $row): string
    {
        $company = trim((string) ($row['companyname'] ?? ''));
        if ($company !== '') {
            return $company;
        }
        $name = trim(trim((string) ($row['firstname'] ?? '')) . ' ' . trim((string) ($row['lastname'] ?? '')));

        return $name !== '' ? $name : '-';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{type: string, data: string, invoiceid: int, title: string, client: string}
     */
    private static function item(string $type, array $row, string $title): array
    {
        return [
            'type' => $type,
            'data' => trim((string) ($row['data'] ?? '')),
            'invoiceid' => (int) ($row['invoiceid'] ?? 0),
            'title' => $title,
            'client' => self::clientName($row),
        ];
    }
}
