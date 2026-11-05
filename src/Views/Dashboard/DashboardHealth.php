<?php

declare(strict_types=1);

namespace OpenNfse\Views\Dashboard;

use OpenNfse\Services\EmissionPolicyService;

/**
 * Itens da barra de saúde da Dashboard.
 *
 * Contadores do período (todos de ReportRepository::dashboardOverview, exceto o último):
 * - erros: com_erro_periodo;
 * - pendentes: pendentes;
 * - rejeitadas: rejeitadas;
 * - aguardando_status: aguardando_status;
 * - nao_emitidas: faturas pagas sem nota (ReportsController::getAuditoriaDashboardSummary, total_invoices).
 */
final class DashboardHealth
{
    public const CERTIFICATE_WARNING_DAYS = 30;

    /**
     * @param array<string, int> $counters erros, pendentes, rejeitadas, aguardando_status, nao_emitidas
     * @param array<string, mixed> $certificate saída de ConfigController::evaluateCertificateFromConfig (status, days_left, valid_to)
     * @param array<string, string> $urls fila, auditoria, certificado, customfield, alertas
     * @return array<int, array{tone: string, icon: string, title: string, text: string, action: string, href: string}>
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

        $filaParts = array_values(array_filter([
            $erros > 0 ? self::plural($erros, 'erro', 'erros') : '',
            $rejeitadas > 0 ? self::plural($rejeitadas, 'rejeitada', 'rejeitadas') : '',
            $pendentes > 0 ? self::plural($pendentes, 'pendente', 'pendentes') : '',
            $aguardando > 0 ? $aguardando . ' aguardando status' : '',
        ]));
        if ($filaParts !== []) {
            $critical = $erros > 0 || $rejeitadas > 0;
            $items[] = self::item(
                $critical ? 'danger' : 'warn',
                $critical ? 'alert' : 'clock',
                implode(' · ', $filaParts),
                $critical
                    ? 'Há notas que não saíram no período. Veja o motivo na fila e reprocesse.'
                    : 'A fila ainda está trabalhando nestas notas.',
                'Ver na fila',
                (string) ($urls['fila'] ?? '')
            );
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
     * Item crítico quando o certificado venceu ou vence em 30 dias ou menos.
     *
     * @param array<string, mixed> $certificate
     * @return array{tone: string, icon: string, title: string, text: string, action: string, href: string}|null
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

        if (!in_array($status, ['ok', 'expiring', 'critical'], true) || $daysLeft === null || $daysLeft > self::CERTIFICATE_WARNING_DAYS) {
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

    public static function plural(int $n, string $one, string $many): string
    {
        return number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? $one : $many);
    }

    /**
     * @return array{tone: string, icon: string, title: string, text: string, action: string, href: string}
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
        ];
    }
}
