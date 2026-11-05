<?php

declare(strict_types=1);

namespace OpenNfse\Views\Dashboard;

use OpenNfse\Services\EmissionPolicyService;

/**
 * Itens da barra de saúde da Dashboard.
 *
 * Contadores do período, sem repetir a mesma nota (ver counters()):
 * - erros: com_erro_periodo menos rejeitadas (com_erro_periodo já inclui as rejeitadas);
 * - pendentes: pendentes menos aguardando_status (pendentes já inclui WAIT_STATUS);
 * - rejeitadas: rejeitadas;
 * - aguardando_status: aguardando_status;
 * - nao_emitidas: faturas pagas sem nota (ReportsController::getAuditoriaDashboardSummary, total_invoices).
 */
final class DashboardHealth
{
    public const CERTIFICATE_WARNING_DAYS = 30;

    private const VALID_CERTIFICATE_STATUSES = ['ok', 'expiring', 'critical'];

    /**
     * Contadores exclusivos a partir das métricas de ReportRepository::dashboardOverview.
     *
     * @param array<string, mixed> $metrics
     * @return array{erros: int, pendentes: int, rejeitadas: int, aguardando_status: int, nao_emitidas: int}
     */
    public static function counters(array $metrics, int $naoEmitidas): array
    {
        $rejeitadas = max(0, (int) ($metrics['rejeitadas'] ?? 0));
        $aguardando = max(0, (int) ($metrics['aguardando_status'] ?? 0));

        return [
            'erros' => max(0, (int) ($metrics['com_erro_periodo'] ?? 0) - $rejeitadas),
            'pendentes' => max(0, (int) ($metrics['pendentes'] ?? 0) - $aguardando),
            'rejeitadas' => $rejeitadas,
            'aguardando_status' => $aguardando,
            'nao_emitidas' => max(0, $naoEmitidas),
        ];
    }

    /**
     * @param array<string, int> $counters erros, pendentes, rejeitadas, aguardando_status, nao_emitidas (já exclusivos)
     * @param array<string, mixed> $certificate saída de ConfigController::evaluateCertificateFromConfig (status, days_left, valid_to)
     * @param array<string, string> $urls erros, rejeitadas, aguardando_status, pendentes, auditoria, certificado, customfield, alertas
     * @return array<int, array{tone: string, icon: string, title: string, text: string, action: string, href: string,
     *     parts: array<int, array{label: string, href: string}>}>
     */
    public static function items(
        array $counters,
        array $certificate,
        bool $customFieldMissing,
        int $alertasAbertos,
        array $urls
    ): array {
        $erros = max(0, (int) ($counters['erros'] ?? 0));
        $pendentes = max(0, (int) ($counters['pendentes'] ?? 0));
        $rejeitadas = max(0, (int) ($counters['rejeitadas'] ?? 0));
        $aguardando = max(0, (int) ($counters['aguardando_status'] ?? 0));
        $naoEmitidas = max(0, (int) ($counters['nao_emitidas'] ?? 0));

        $items = [];
        if ($erros + $pendentes + $rejeitadas + $aguardando + $naoEmitidas === 0) {
            $items[] = self::item(
                'ok',
                'check',
                'Operação em dia',
                '0 erros · 0 pendentes · 0 rejeitadas · 0 aguardando status · 0 faturas pagas sem nota'
            );
        }

        $parts = [];
        $counted = [
            'erros' => [$erros, self::plural($erros, 'erro', 'erros')],
            'rejeitadas' => [$rejeitadas, self::plural($rejeitadas, 'rejeitada', 'rejeitadas')],
            'pendentes' => [$pendentes, self::plural($pendentes, 'pendente', 'pendentes')],
            'aguardando_status' => [$aguardando, number_format($aguardando, 0, ',', '.') . ' aguardando status'],
        ];
        foreach ($counted as $key => [$count, $label]) {
            if ($count > 0) {
                $parts[] = ['label' => $label, 'href' => (string) ($urls[$key] ?? '')];
            }
        }
        if ($parts !== []) {
            $critical = $erros > 0 || $rejeitadas > 0;
            $item = self::item(
                $critical ? 'danger' : 'warn',
                $critical ? 'alert' : 'clock',
                implode(' · ', array_column($parts, 'label')),
                $critical
                    ? 'Há notas que não saíram no período. Abra cada contador para ver o motivo e reprocessar.'
                    : 'A fila ainda está trabalhando nestas notas.'
            );
            $item['parts'] = $parts;
            $items[] = $item;
        }

        if ($naoEmitidas > 0) {
            $items[] = self::item(
                'warn',
                'alert',
                self::plural($naoEmitidas, 'fatura paga sem nota', 'faturas pagas sem nota'),
                'Pagas no período, sem NFS-e e sem item na fila.',
                'Ver auditoria',
                (string) ($urls['auditoria'] ?? '')
            );
        }

        $certificateItem = self::certificateItem($certificate, (string) ($urls['certificado'] ?? ''));
        if ($certificateItem !== null) {
            $items[] = $certificateItem;
        }

        if ($customFieldMissing) {
            $items[] = self::item(
                'warn',
                'alert',
                'Regra por cliente ignorada',
                EmissionPolicyService::MSG_CUSTOMFIELD_MISSING,
                'Revisar a configuração',
                (string) ($urls['customfield'] ?? '')
            );
        }

        if ($alertasAbertos > 0) {
            $items[] = self::item(
                'warn',
                'alert',
                self::plural($alertasAbertos, 'alerta de NFS-e aberto', 'alertas de NFS-e abertos'),
                'Revisão manual, de qualquer período.',
                'Ver alertas',
                (string) ($urls['alertas'] ?? '')
            );
        }

        return $items;
    }

    /**
     * Item crítico quando o certificado venceu ou vence em 30 dias ou menos; aviso quando
     * não está configurado (missing) ou não pôde ser lido (error e demais status).
     *
     * @param array<string, mixed> $certificate
     * @return array{tone: string, icon: string, title: string, text: string, action: string, href: string,
     *     parts: array<int, array{label: string, href: string}>}|null
     */
    public static function certificateItem(array $certificate, string $href): ?array
    {
        $status = trim((string) ($certificate['status'] ?? ''));
        $rawDays = $certificate['days_left'] ?? null;
        $daysLeft = $rawDays !== null && $rawDays !== '' ? (int) $rawDays : null;
        $validTo = trim((string) strtok(trim((string) ($certificate['valid_to'] ?? '')), ' '));
        $expired = $status === 'expired' || ($daysLeft !== null && $daysLeft < 0);

        if ($expired) {
            return self::item(
                'danger',
                'alert',
                'Certificado vencido',
                ($validTo !== '' ? 'Venceu em ' . $validTo . ' · ' : '') . 'as emissões estão paradas',
                'Renovar certificado',
                $href
            );
        }

        if ($status === 'missing') {
            return self::item('warn', 'alert', 'Certificado não configurado', 'Sem certificado A1 as notas não são emitidas.', 'Abrir Configurações', $href);
        }

        if ($status !== '' && !in_array($status, self::VALID_CERTIFICATE_STATUSES, true)) {
            return self::item('warn', 'alert', 'Não foi possível ler o certificado', 'Confira o arquivo e a senha do certificado A1.', 'Abrir Configurações', $href);
        }

        if ($status === '' || $daysLeft === null || $daysLeft > self::CERTIFICATE_WARNING_DAYS) {
            return null;
        }

        $title = match (true) {
            $daysLeft === 0 => 'Certificado vence hoje',
            $daysLeft === 1 => 'Certificado vence em 1 dia',
            default => 'Certificado vence em ' . $daysLeft . ' dias',
        };

        return self::item(
            'danger',
            'alert',
            $title,
            ($validTo !== '' ? $validTo . ' · ' : '') . 'as emissões param no vencimento',
            'Renovar certificado',
            $href
        );
    }

    /**
     * "Certificado válido até dd/mm/aaaa" quando o certificado está legível e não gera item na barra
     * (mais de 30 dias). Vazio nos demais casos.
     *
     * @param array<string, mixed> $certificate
     */
    public static function certificateValidLine(array $certificate): string
    {
        $status = trim((string) ($certificate['status'] ?? ''));
        $rawDays = $certificate['days_left'] ?? null;
        $validTo = trim((string) strtok(trim((string) ($certificate['valid_to'] ?? '')), ' '));
        if (
            !in_array($status, self::VALID_CERTIFICATE_STATUSES, true)
            || $rawDays === null || $rawDays === ''
            || (int) $rawDays <= self::CERTIFICATE_WARNING_DAYS
            || $validTo === ''
        ) {
            return '';
        }

        return 'Certificado válido até ' . $validTo;
    }

    public static function plural(int $n, string $one, string $many): string
    {
        return number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? $one : $many);
    }

    /**
     * @return array{tone: string, icon: string, title: string, text: string, action: string, href: string,
     *     parts: array<int, array{label: string, href: string}>}
     */
    private static function item(string $tone, string $icon, string $title, string $text, string $action = '', string $href = ''): array
    {
        return [
            'tone' => $tone,
            'icon' => $icon,
            'title' => $title,
            'text' => $text,
            'action' => $href !== '' ? $action : '',
            'href' => $href,
            'parts' => [],
        ];
    }
}
