<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use OpenNfse\Helpers\BrasiliaTime;
use WHMCS\Database\Capsule;

/**
 * Relatório "NFS-e de faturas não pagas": notas EMITIDA cuja fatura não está Paid
 * e notas emitidas antes do pagamento da fatura.
 */
final class UnpaidNfseReportRepository
{
    public const SITUACAO_NAO_PAGA = 'nao_paga';
    public const SITUACAO_PAGA_DEPOIS = 'paga_depois';

    /** Mesma regra de BrasiliaTime::isPaidAfterEmission(), com o deslocamento de fuso em segundos. */
    private const PAID_AFTER_EMISSION_SQL = 'i.datepaid > DATE_ADD(n.emitida_em, INTERVAL ? SECOND)';

    /**
     * @param array{data_inicial?: string, data_final?: string, situacao?: string, cliente?: string} $filters
     */
    public function list(array $filters, int $limit): array
    {
        $limit = max(1, min(5000, $limit));

        $q = $this->buildQuery($filters)
            ->leftJoin('tblcurrencies as cur', 'cur.id', '=', 'c.currency')
            ->select([
                'n.id as nota_id',
                'n.invoiceid',
                'n.numero_nf',
                'n.chave_acesso',
                'n.emitida_em',
                'i.status as invoice_status',
                'i.datepaid',
                'i.total as invoice_total',
                'i.credit as applied_credit',
                'i.paymentmethod',
                'c.id as client_id',
                'c.companyname',
                'c.firstname',
                'c.lastname',
                'cur.prefix as currency_prefix',
                'cur.suffix as currency_suffix',
            ])
            ->orderBy('n.emitida_em', 'desc')
            ->orderBy('n.id', 'desc');

        $out = [];
        foreach ($q->limit($limit)->get() as $r) {
            $row = (array) $r;
            $row['situacao'] = strcasecmp((string) ($row['invoice_status'] ?? ''), 'Paid') === 0
                ? self::SITUACAO_PAGA_DEPOIS
                : self::SITUACAO_NAO_PAGA;
            $out[] = $row;
        }

        return $out;
    }

    public function summary(array $filters): array
    {
        $row = $this->buildQuery($filters)
            ->select([
                Capsule::raw('COUNT(*) as total_notas'),
                Capsule::raw("SUM(CASE WHEN i.status <> 'Paid' THEN 1 ELSE 0 END) as total_nao_pagas"),
                Capsule::raw('SUM(i.total) as total_valor'),
            ])
            ->first();
        $arr = $row ? (array) $row : [];

        $total = (int) ($arr['total_notas'] ?? 0);
        $naoPagas = (int) ($arr['total_nao_pagas'] ?? 0);

        return [
            'total_notas' => $total,
            'total_nao_pagas' => $naoPagas,
            'total_pagas_depois' => max(0, $total - $naoPagas),
            'total_valor' => (float) ($arr['total_valor'] ?? 0),
        ];
    }

    private function buildQuery(array $filters)
    {
        $situacao = (string) ($filters['situacao'] ?? '');
        // emitida_em está no horário de Brasília e datepaid no fuso do servidor (ver BrasiliaTime).
        $shift = BrasiliaTime::shiftToServerSeconds();

        $q = Capsule::table('mod_opennfse_notas as n')
            ->join('tblinvoices as i', 'i.id', '=', 'n.invoiceid')
            ->leftJoin('tblclients as c', 'c.id', '=', 'i.userid')
            ->where('n.status', 'EMITIDA');

        if ($situacao === self::SITUACAO_NAO_PAGA) {
            $q->where('i.status', '<>', 'Paid');
        } elseif ($situacao === self::SITUACAO_PAGA_DEPOIS) {
            $q->where('i.status', 'Paid')
                ->whereNotNull('n.emitida_em')
                ->whereRaw(self::PAID_AFTER_EMISSION_SQL, [$shift]);
        } else {
            $q->where(static function ($w) use ($shift) {
                $w->where('i.status', '<>', 'Paid')
                    ->orWhere(static function ($paid) use ($shift) {
                        $paid->where('i.status', 'Paid')
                            ->whereNotNull('n.emitida_em')
                            ->whereRaw(self::PAID_AFTER_EMISSION_SQL, [$shift]);
                    });
            });
        }

        $from = trim((string) ($filters['data_inicial'] ?? ''));
        $to = trim((string) ($filters['data_final'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $q->where('n.emitida_em', '>=', $from . ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $q->where('n.emitida_em', '<=', $to . ' 23:59:59');
        }

        $cliente = trim((string) ($filters['cliente'] ?? ''));
        if ($cliente !== '') {
            if (ctype_digit($cliente)) {
                $q->where('c.id', (int) $cliente);
            } else {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $cliente) . '%';
                $q->where(static function ($sub) use ($like) {
                    $sub->where('c.companyname', 'like', $like)
                        ->orWhere('c.firstname', 'like', $like)
                        ->orWhere('c.lastname', 'like', $like);
                });
            }
        }

        return $q;
    }
}
