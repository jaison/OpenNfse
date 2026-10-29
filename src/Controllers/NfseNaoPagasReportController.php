<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Controllers\Support\AdminHelpersTrait;
use OpenNfse\Helpers\CsvSanitizer;
use OpenNfse\Repositories\UnpaidNfseReportRepository;

/**
 * Aba "NFS-e de faturas não pagas" dos relatórios e o export CSV dela.
 */
final class NfseNaoPagasReportController
{
    use AdminHelpersTrait;

    public const TAB = 'nfse_nao_pagas';

    private const LIST_LIMIT = 500;

    private const SITUACOES = [
        '' => 'Todas',
        UnpaidNfseReportRepository::SITUACAO_NAO_PAGA => 'Fatura não paga',
        UnpaidNfseReportRepository::SITUACAO_PAGA_DEPOIS => 'Paga após a emissão',
    ];

    public function render(): void
    {
        $filters = $this->readFilters($_REQUEST);
        $repo = new UnpaidNfseReportRepository();
        $rows = $repo->list($filters, self::LIST_LIMIT);
        $summary = $repo->summary($filters);

        $h = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $labelStyle = 'font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;';
        $inputStyle = 'height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;';

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="relatorios" />';
        echo '<input type="hidden" name="tab" value="' . $h(self::TAB) . '" />';
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;flex:1 1 620px;">';
        echo '<div><div style="' . $labelStyle . '">Emitida de</div><input type="date" name="data_inicial" value="' . $h($filters['data_inicial']) . '" style="width:160px;' . $inputStyle . '" /></div>';
        echo '<div><div style="' . $labelStyle . '">Emitida até</div><input type="date" name="data_final" value="' . $h($filters['data_final']) . '" style="width:160px;' . $inputStyle . '" /></div>';
        echo '<div><div style="' . $labelStyle . '">Situação</div><select name="situacao" style="' . $inputStyle . '">';
        foreach (self::SITUACOES as $key => $label) {
            echo '<option value="' . $h($key) . '"' . ($key === $filters['situacao'] ? ' selected' : '') . '>' . $h($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div style="flex:1 1 240px;min-width:240px;"><div style="' . $labelStyle . '">Cliente</div><input type="text" name="cliente" placeholder="Cliente (ID ou nome)" value="' . $h($filters['cliente']) . '" style="width:100%;' . $inputStyle . '" /></div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=relatorios&tab=' . $h(self::TAB) . '">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        echo '<div style="margin-bottom:12px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">';
        echo '<div style="font-size:12px;color:#4b5563;">Notas EMITIDA cuja fatura não está Paid e notas emitidas antes do pagamento. O cancelamento da NFS-e, quando cabível, é manual. Veja também os <a href="addonmodules.php?module=OpenNfse&action=alertas">alertas de NFS-e</a>.</div>';
        echo $this->renderPostActionButton('relatoriosExport', 'Exportar CSV', [
            'tab' => self::TAB,
            'data_inicial' => $filters['data_inicial'],
            'data_final' => $filters['data_final'],
            'situacao' => $filters['situacao'],
            'cliente' => $filters['cliente'],
        ], 'btn btn-xs btn-default');
        echo '</div>';

        if ((int) $summary['total_notas'] > count($rows)) {
            echo '<div class="alert alert-info" style="margin-bottom:10px;">Exibindo as ' . count($rows) . ' notas mais recentes de ' . (int) $summary['total_notas'] . '. Use os filtros ou o CSV para ver as demais.</div>';
        }

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:11%;">Emitida em</th>';
        echo '<th style="width:8%;">Fatura</th>';
        echo '<th style="width:20%;">Cliente</th>';
        echo '<th style="width:17%;">NFS-e</th>';
        echo '<th style="width:10%;">Status da fatura</th>';
        echo '<th style="width:11%;">Pago em</th>';
        echo '<th style="width:10%;">Valor</th>';
        echo '<th style="width:13%;">Situação</th>';
        echo '</tr>';

        if (empty($rows)) {
            echo '<tr><td colspan="8" style="text-align:center;color:#666;">Nenhuma nota encontrada para os critérios selecionados.</td></tr>';
        }

        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $numeroNf = trim((string) ($row['numero_nf'] ?? ''));
            $chave = trim((string) ($row['chave_acesso'] ?? ''));
            $chaveShort = mb_strlen($chave, 'UTF-8') > 20 ? mb_substr($chave, 0, 20, 'UTF-8') . '...' : $chave;
            $situacao = (string) ($row['situacao'] ?? '');
            $prefix = (string) ($row['currency_prefix'] ?? 'R$ ');
            $suffix = (string) ($row['currency_suffix'] ?? '');

            echo '<tr>';
            echo '<td>' . $h($this->formatDate((string) ($row['emitida_em'] ?? ''), 'd/m/Y H:i')) . '</td>';
            echo '<td><a href="' . $h('invoices.php?action=edit&id=' . $invoiceId) . '">' . $h((string) $invoiceId) . '</a></td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . $h($this->resolveClientName($row)) . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . ($numeroNf !== '' ? '<div><strong>Nº ' . $h($numeroNf) . '</strong></div>' : '')
                . '<div title="' . $h($chave) . '">' . $h($chave !== '' ? $chaveShort : '-') . '</div></td>';
            echo '<td>' . $h((string) ($row['invoice_status'] ?? '')) . '</td>';
            echo '<td>' . $h($this->formatPaidDate((string) ($row['datepaid'] ?? ''))) . '</td>';
            echo '<td>' . $h($this->formatMoney((float) ($row['invoice_total'] ?? 0), $prefix, $suffix)) . '</td>';
            echo '<td>' . $h(self::SITUACOES[$situacao] ?? $situacao) . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        echo '<div style="margin-top:12px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px;background:linear-gradient(180deg,#fbfcfe 0%,#f5f7fa 100%);display:flex;gap:10px;flex-wrap:wrap;">';
        $cards = [
            'Total de notas' => (string) $summary['total_notas'],
            'Fatura não paga' => (string) $summary['total_nao_pagas'],
            'Paga após a emissão' => (string) $summary['total_pagas_depois'],
            'Valor das faturas' => $this->formatMoney((float) $summary['total_valor'], 'R$ ', ''),
        ];
        foreach ($cards as $label => $value) {
            echo '<div style="min-width:180px;flex:1 1 180px;padding:10px 12px;border:1px solid #dbe5f0;border-radius:6px;background:#fff;">';
            echo '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">' . $h($label) . '</div>';
            echo '<div style="font-size:17px;font-weight:700;color:#1f2937;">' . $h($value) . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Escreve o CSV no handle já aberto (o chamador cuida dos cabeçalhos HTTP e do BOM).
     *
     * @param resource $out
     */
    public function writeCsv($out): void
    {
        $filters = $this->readFilters($_REQUEST);
        $rows = (new UnpaidNfseReportRepository())->list($filters, 5000);

        fputcsv($out, CsvSanitizer::row(['emitida_em', 'invoiceid', 'cliente', 'numero_nf', 'chave_acesso', 'status_fatura', 'data_pagamento', 'valor', 'credito_aplicado', 'situacao']), ';');
        foreach ($rows as $r) {
            $situacao = (string) ($r['situacao'] ?? '');
            fputcsv($out, CsvSanitizer::row([
                (string) ($r['emitida_em'] ?? ''),
                (string) ($r['invoiceid'] ?? ''),
                $this->resolveClientName($r),
                (string) ($r['numero_nf'] ?? ''),
                (string) ($r['chave_acesso'] ?? ''),
                (string) ($r['invoice_status'] ?? ''),
                $this->isZeroDate((string) ($r['datepaid'] ?? '')) ? '' : (string) ($r['datepaid'] ?? ''),
                (string) ($r['invoice_total'] ?? ''),
                (string) ($r['applied_credit'] ?? ''),
                self::SITUACOES[$situacao] ?? $situacao,
            ]), ';');
        }
    }

    /**
     * @return array{data_inicial: string, data_final: string, situacao: string, cliente: string}
     */
    private function readFilters(array $source): array
    {
        $dataInicial = trim((string) ($source['data_inicial'] ?? ''));
        $dataFinal = trim((string) ($source['data_final'] ?? ''));
        $situacao = trim((string) ($source['situacao'] ?? ''));
        if (!array_key_exists($situacao, self::SITUACOES)) {
            $situacao = '';
        }

        return [
            'data_inicial' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicial) === 1 ? $dataInicial : '',
            'data_final' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFinal) === 1 ? $dataFinal : '',
            'situacao' => $situacao,
            'cliente' => mb_substr(trim((string) ($source['cliente'] ?? '')), 0, 120, 'UTF-8'),
        ];
    }

    private function formatPaidDate(string $value): string
    {
        return $this->isZeroDate($value) ? '-' : $this->formatDate($value, 'd/m/Y H:i');
    }

    private function isZeroDate(string $value): bool
    {
        $value = trim($value);

        return $value === '' || str_starts_with($value, '0000-00-00');
    }
}
