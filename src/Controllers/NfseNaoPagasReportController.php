<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Controllers\Support\AdminHelpersTrait;
use OpenNfse\Helpers\CsvSanitizer;
use OpenNfse\Repositories\UnpaidNfseReportRepository;
use OpenNfse\Services\TokenService;
use OpenNfse\Views\ReportView;
use OpenNfse\Views\Ui;

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

        echo Ui::filterBar(
            ['hidden' => ['module' => 'OpenNfse', 'action' => 'relatorios', 'tab' => self::TAB]],
            Ui::field('Emitida de', Ui::input('data_inicial', $filters['data_inicial'], 'date'))
            . Ui::field('Emitida até', Ui::input('data_final', $filters['data_final'], 'date'))
            . Ui::field('Situação', Ui::select('situacao', self::SITUACOES, $filters['situacao'], [], 'nfse-input--w-md'))
            . Ui::field('Cliente', Ui::input('cliente', $filters['cliente'], 'text', ['placeholder' => 'Cliente (ID ou nome)'], 'nfse-input--w-lg')),
            Ui::button('Filtrar', ['primary']) . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=' . self::TAB]),
            'Filtros das NFS-e de faturas não pagas'
        );

        echo Ui::callout(
            'info',
            'Cancelamento manual',
            'Quando cabível, o cancelamento da NFS-e é feito à mão. Veja também os ',
            '<a href="addonmodules.php?module=OpenNfse&amp;action=alertas">alertas de NFS-e</a>.'
        );

        echo Ui::kpis([
            ['label' => 'Total de notas', 'value' => (int) $summary['total_notas']],
            ['label' => 'Fatura não paga', 'value' => (int) $summary['total_nao_pagas'], 'tone' => (int) $summary['total_nao_pagas'] > 0 ? 'warn' : ''],
            ['label' => 'Paga após a emissão', 'value' => (int) $summary['total_pagas_depois']],
            ['label' => 'Valor das faturas', 'value' => $this->formatMoney((float) $summary['total_valor'], 'R$ ', '')],
        ]);

        $rowsHtml = '';
        foreach ($rows as $row) {
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $numeroNf = trim((string) ($row['numero_nf'] ?? ''));
            $chave = trim((string) ($row['chave_acesso'] ?? ''));
            $chaveShort = mb_strlen($chave, 'UTF-8') > 20 ? mb_substr($chave, 0, 20, 'UTF-8') . '...' : $chave;
            $situacao = (string) ($row['situacao'] ?? '');
            $prefix = (string) ($row['currency_prefix'] ?? 'R$ ');
            $suffix = (string) ($row['currency_suffix'] ?? '');

            $rowsHtml .= '<tr>'
                . '<td class="nfse-nowrap">' . Ui::h($this->formatDate((string) ($row['emitida_em'] ?? ''), 'd/m/Y H:i')) . '</td>'
                . '<td>' . ReportView::invoiceLink($invoiceId) . '</td>'
                . '<td class="nfse-break">' . Ui::h($this->resolveClientName($row)) . '</td>'
                . '<td class="nfse-break"><div class="nfse-cell-stack">' . ($numeroNf !== '' ? '<b>Nº ' . Ui::h($numeroNf) . '</b>' : '')
                . '<span class="nfse-mono" title="' . Ui::h($chave) . '">' . Ui::h($chave !== '' ? $chaveShort : '-') . '</span></div></td>'
                . '<td>' . ReportView::orDash((string) ($row['invoice_status'] ?? '')) . '</td>'
                . '<td class="nfse-nowrap">' . Ui::h($this->formatPaidDate((string) ($row['datepaid'] ?? ''))) . '</td>'
                . '<td class="nfse-num nfse-nowrap">' . Ui::h($this->formatMoney((float) ($row['invoice_total'] ?? 0), $prefix, $suffix)) . '</td>'
                . '<td>' . Ui::badge(self::SITUACOES[$situacao] ?? $situacao, $situacao === UnpaidNfseReportRepository::SITUACAO_NAO_PAGA ? 'warn' : '') . '</td>'
                . '</tr>';
        }

        $total = (int) $summary['total_notas'];
        $shown = count($rows);
        $summaryText = $shown === 0
            ? 'Nenhum resultado'
            : ($total > $shown
                ? 'Exibindo as <b>' . $shown . '</b> notas mais recentes de <b>' . $total . '</b>. Use os filtros ou o CSV para ver as demais.'
                : Ui::rangeText(1, $shown, $total, 'notas'));
        $token = (new TokenService())->token();
        echo ReportView::tableCard(
            ['Emitida em', 'Fatura', 'Cliente', 'NFS-e', 'Status da fatura', 'Pago em', ['Valor', 'nfse-num'], 'Situação'],
            $rowsHtml,
            'Nenhuma nota encontrada para os critérios selecionados.',
            '',
            '',
            Ui::tableFooter($summaryText, ReportView::postButton('relatoriosExport', 'Exportar CSV', [
                'tab' => self::TAB,
                'data_inicial' => $filters['data_inicial'],
                'data_final' => $filters['data_final'],
                'situacao' => $filters['situacao'],
                'cliente' => $filters['cliente'],
            ], $token)),
            'nfse-rel-secao'
        );
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
