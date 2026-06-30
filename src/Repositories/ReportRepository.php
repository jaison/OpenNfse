<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class ReportRepository
{
    public function listNotas(array $filters, int $limit): array
    {
        $limit = max(1, min(500, $limit));

        $q = Capsule::table('mod_opennfse_notas as n')
            ->join('tblinvoices as i', 'i.id', '=', 'n.invoiceid')
            ->join('tblclients as c', 'c.id', '=', 'n.userid')
            ->leftJoin('tblcurrencies as cur', 'cur.id', '=', 'c.currency')
            ->select([
                'n.invoiceid',
                'n.userid',
                'n.numero_nf',
                'n.status',
                'n.emitida_em',
                'n.cancelado_em',
                'n.chave_acesso',
                'n.xml_path',
                'n.updated_at as nfse_updated_at',
                'n.erro_api',
                'i.total as invoice_total',
                'c.currency as invoice_currency_id',
                'c.companyname',
                'c.firstname',
                'c.lastname',
                'cur.prefix as currency_prefix',
                'cur.suffix as currency_suffix',
            ]);

        $this->applyFiltersToNotasQuery($q, $filters);

        $dateField = (string) ($filters['date_field'] ?? '');
        if ($dateField === 'cancelado') {
            $q->orderBy('n.cancelado_em', 'desc');
        } else {
            $q->orderByRaw('COALESCE(n.emitida_em, n.updated_at) DESC');
        }

        $out = [];
        foreach ($q->limit($limit)->get() as $r) {
            $out[] = (array) $r;
        }
        return $out;
    }

    public function summaryNotas(array $filters): array
    {
        $q = Capsule::table('mod_opennfse_notas as n')
            ->join('tblinvoices as i', 'i.id', '=', 'n.invoiceid')
            ->join('tblclients as c', 'c.id', '=', 'n.userid');

        $this->applyFiltersToNotasQuery($q, $filters);

        $row = $q->select([
            Capsule::raw('COUNT(*) as total_notas'),
            Capsule::raw('SUM(i.total) as total_valor'),
        ])->first();

        $arr = $row ? (array) $row : [];

        return [
            'total_notas' => (int) ($arr['total_notas'] ?? 0),
            'total_valor' => (float) ($arr['total_valor'] ?? 0),
        ];
    }

    public function listFalhas(array $filters, int $limit): array
    {
        $limit = max(1, min(500, $limit));

        $notaQ = Capsule::table('mod_opennfse_notas as n')
            ->join('tblinvoices as i', 'i.id', '=', 'n.invoiceid')
            ->join('tblclients as c', 'c.id', '=', 'n.userid')
            ->select([
                'n.invoiceid',
                'n.userid',
                'n.updated_at as data',
                'n.erro_api as erro',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->whereIn('n.status', ['ERRO', 'REJEITADA']);

        $this->applyClientFilter($notaQ, (string) ($filters['cliente'] ?? ''), 'c');
        $this->applyDateFilter($notaQ, (string) ($filters['data_inicial'] ?? ''), (string) ($filters['data_final'] ?? ''), 'n.updated_at');

        $notas = [];
        foreach ($notaQ->orderBy('n.updated_at', 'desc')->limit($limit)->get() as $r) {
            $notas[] = (array) $r;
        }

        $queueQ = Capsule::table('mod_opennfse_queue as q')
            ->join('tblinvoices as i', 'i.id', '=', 'q.invoiceid')
            ->join('tblclients as c', 'c.id', '=', 'i.userid')
            ->select([
                'q.invoiceid',
                'i.userid',
                'q.updated_at as data',
                'q.last_error as erro',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->where('q.status', 'ERROR');

        $this->applyClientFilter($queueQ, (string) ($filters['cliente'] ?? ''), 'c');
        $this->applyDateFilter($queueQ, (string) ($filters['data_inicial'] ?? ''), (string) ($filters['data_final'] ?? ''), 'q.updated_at');

        $queues = [];
        foreach ($queueQ->orderBy('q.updated_at', 'desc')->limit($limit)->get() as $r) {
            $queues[] = (array) $r;
        }

        $all = array_merge($notas, $queues);
        usort($all, static function (array $a, array $b): int {
            $da = (string) ($a['data'] ?? '');
            $db = (string) ($b['data'] ?? '');
            if ($da === $db) {
                return 0;
            }
            return $da < $db ? 1 : -1;
        });

        return array_slice($all, 0, $limit);
    }

    public function dashboardThisMonth(): array
    {
        return $this->dashboardOverview(date('Y-m-01'), date('Y-m-t'));
    }

    public function dashboardOverview(string $fromDate = '', string $toDate = ''): array
    {
        [$startDate, $endDate, $start, $end] = $this->normalizeDashboardRange($fromDate, $toDate);

        $emitidas = (int) Capsule::table('mod_opennfse_notas as n')
            ->where('n.status', 'EMITIDA')
            ->whereBetween('n.emitida_em', [$start, $end])
            ->count();

        $canceladas = (int) Capsule::table('mod_opennfse_notas as n')
            ->whereNotNull('n.cancelado_em')
            ->whereBetween('n.cancelado_em', [$start, $end])
            ->count();

        $rejeitadas = (int) Capsule::table('mod_opennfse_notas as n')
            ->where('n.status', 'REJEITADA')
            ->whereBetween('n.updated_at', [$start, $end])
            ->count();

        $movimentadas = (int) Capsule::table('mod_opennfse_notas as n')
            ->where(static function ($q) use ($start, $end) {
                $q->whereBetween('n.emitida_em', [$start, $end])
                    ->orWhereBetween('n.cancelado_em', [$start, $end])
                    ->orWhereBetween('n.updated_at', [$start, $end]);
            })
            ->count();

        $pendSet = [];
        foreach (Capsule::table('mod_opennfse_notas as n')->select(['n.invoiceid'])->where('n.status', 'PROCESSANDO')->get() as $r) {
            $pendSet[(int) ($r->invoiceid ?? 0)] = true;
        }
        foreach (Capsule::table('mod_opennfse_queue as q')->select(['q.invoiceid'])->whereIn('q.status', ['PENDING', 'RUNNING', 'WAIT_STATUS'])->distinct()->get() as $r) {
            $pendSet[(int) ($r->invoiceid ?? 0)] = true;
        }
        unset($pendSet[0]);
        $pendentes = count($pendSet);

        $waitStatusSet = [];
        foreach (Capsule::table('mod_opennfse_queue as q')->select(['q.invoiceid'])->where('q.status', 'WAIT_STATUS')->distinct()->get() as $r) {
            $waitStatusSet[(int) ($r->invoiceid ?? 0)] = true;
        }
        unset($waitStatusSet[0]);
        $aguardandoStatus = count($waitStatusSet);

        $erroSet = [];
        foreach (Capsule::table('mod_opennfse_notas as n')->select(['n.invoiceid'])->whereIn('n.status', ['ERRO', 'REJEITADA'])->get() as $r) {
            $erroSet[(int) ($r->invoiceid ?? 0)] = true;
        }
        foreach (Capsule::table('mod_opennfse_queue as q')->select(['q.invoiceid'])->where('q.status', 'ERROR')->distinct()->get() as $r) {
            $erroSet[(int) ($r->invoiceid ?? 0)] = true;
        }
        unset($erroSet[0]);
        $comErro = count($erroSet);

        $erroPeriodoSet = [];
        foreach (Capsule::table('mod_opennfse_notas as n')
            ->select(['n.invoiceid'])
            ->whereIn('n.status', ['ERRO', 'REJEITADA'])
            ->whereBetween('n.updated_at', [$start, $end])
            ->get() as $r) {
            $erroPeriodoSet[(int) ($r->invoiceid ?? 0)] = true;
        }
        foreach (Capsule::table('mod_opennfse_queue as q')
            ->select(['q.invoiceid'])
            ->where('q.status', 'ERROR')
            ->whereBetween('q.updated_at', [$start, $end])
            ->distinct()
            ->get() as $r) {
            $erroPeriodoSet[(int) ($r->invoiceid ?? 0)] = true;
        }
        unset($erroPeriodoSet[0]);
        $comErroPeriodo = count($erroPeriodoSet);

        $row = Capsule::table('mod_opennfse_notas as n')
            ->join('tblinvoices as i', 'i.id', '=', 'n.invoiceid')
            ->where('n.status', 'EMITIDA')
            ->whereBetween('n.emitida_em', [$start, $end])
            ->select([Capsule::raw('SUM(i.total) as total')])
            ->first();

        $xmls = (int) Capsule::table('mod_opennfse_notas as n')
            ->where('n.status', 'EMITIDA')
            ->whereNotNull('n.xml_path')
            ->where('n.xml_path', '<>', '')
            ->whereBetween('n.emitida_em', [$start, $end])
            ->count();

        $total = $row ? (float) (((array) $row)['total'] ?? 0) : 0.0;
        $taxaSucesso = ($emitidas + $comErroPeriodo) > 0 ? round(($emitidas / ($emitidas + $comErroPeriodo)) * 100, 1) : 0.0;

        $ultimaEmissao = Capsule::table('mod_opennfse_notas as n')
            ->join('tblclients as c', 'c.id', '=', 'n.userid')
            ->select([
                'n.invoiceid',
                'n.numero_nf',
                'n.emitida_em as data',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->where('n.status', 'EMITIDA')
            ->whereBetween('n.emitida_em', [$start, $end])
            ->orderBy('n.emitida_em', 'desc')
            ->first();

        $ultimoErroNota = Capsule::table('mod_opennfse_notas as n')
            ->join('tblclients as c', 'c.id', '=', 'n.userid')
            ->select([
                'n.invoiceid',
                'n.status',
                'n.erro_api as erro',
                'n.updated_at as data',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->whereIn('n.status', ['ERRO', 'REJEITADA'])
            ->whereBetween('n.updated_at', [$start, $end])
            ->orderBy('n.updated_at', 'desc')
            ->first();

        $ultimoErroFila = Capsule::table('mod_opennfse_queue as q')
            ->join('tblinvoices as i', 'i.id', '=', 'q.invoiceid')
            ->join('tblclients as c', 'c.id', '=', 'i.userid')
            ->select([
                'q.invoiceid',
                Capsule::raw("'ERROR' as status"),
                'q.last_error as erro',
                'q.updated_at as data',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->where('q.status', 'ERROR')
            ->whereBetween('q.updated_at', [$start, $end])
            ->orderBy('q.updated_at', 'desc')
            ->first();

        $ultimoErro = null;
        $erroNotaArr = $ultimoErroNota ? (array) $ultimoErroNota : null;
        $erroFilaArr = $ultimoErroFila ? (array) $ultimoErroFila : null;
        if ($erroNotaArr !== null && $erroFilaArr !== null) {
            $ultimoErro = ((string) ($erroNotaArr['data'] ?? '') >= (string) ($erroFilaArr['data'] ?? '')) ? $erroNotaArr : $erroFilaArr;
        } elseif ($erroNotaArr !== null) {
            $ultimoErro = $erroNotaArr;
        } elseif ($erroFilaArr !== null) {
            $ultimoErro = $erroFilaArr;
        }

        return [
            'range_start' => $startDate,
            'range_end' => $endDate,
            'emitidas' => $emitidas,
            'canceladas' => $canceladas,
            'rejeitadas' => $rejeitadas,
            'movimentadas' => $movimentadas,
            'pendentes' => $pendentes,
            'aguardando_status' => $aguardandoStatus,
            'com_erro' => $comErro,
            'com_erro_periodo' => $comErroPeriodo,
            'valor_total' => (float) $total,
            'taxa_sucesso' => $taxaSucesso,
            'xmls' => $xmls,
            'ultima_emissao' => $ultimaEmissao ? (array) $ultimaEmissao : null,
            'ultimo_erro' => $ultimoErro,
        ];
    }

    public function dashboardRecentEmitidas(string $fromDate = '', string $toDate = '', int $limit = 5): array
    {
        [$startDate, $endDate, $start, $end] = $this->normalizeDashboardRange($fromDate, $toDate);
        unset($startDate, $endDate);

        $out = [];
        foreach (Capsule::table('mod_opennfse_notas as n')
            ->join('tblclients as c', 'c.id', '=', 'n.userid')
            ->select([
                'n.invoiceid',
                'n.numero_nf',
                'n.emitida_em as data',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->where('n.status', 'EMITIDA')
            ->whereBetween('n.emitida_em', [$start, $end])
            ->orderBy('n.emitida_em', 'desc')
            ->limit(max(1, min(20, $limit)))
            ->get() as $r) {
            $out[] = (array) $r;
        }

        return $out;
    }

    public function dashboardRecentCancelamentos(string $fromDate = '', string $toDate = '', int $limit = 5): array
    {
        [$startDate, $endDate, $start, $end] = $this->normalizeDashboardRange($fromDate, $toDate);
        unset($startDate, $endDate);

        $out = [];
        foreach (Capsule::table('mod_opennfse_notas as n')
            ->join('tblclients as c', 'c.id', '=', 'n.userid')
            ->select([
                'n.invoiceid',
                'n.numero_nf',
                'n.cancelado_em as data',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->whereNotNull('n.cancelado_em')
            ->whereBetween('n.cancelado_em', [$start, $end])
            ->orderBy('n.cancelado_em', 'desc')
            ->limit(max(1, min(20, $limit)))
            ->get() as $r) {
            $out[] = (array) $r;
        }

        return $out;
    }

    public function dashboardRecentIssues(string $fromDate = '', string $toDate = '', int $limit = 5): array
    {
        [$startDate, $endDate, $start, $end] = $this->normalizeDashboardRange($fromDate, $toDate);
        unset($startDate, $endDate);

        $notas = [];
        foreach (Capsule::table('mod_opennfse_notas as n')
            ->join('tblclients as c', 'c.id', '=', 'n.userid')
            ->select([
                'n.invoiceid',
                'n.status',
                'n.erro_api as erro',
                'n.updated_at as data',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->whereIn('n.status', ['ERRO', 'REJEITADA'])
            ->whereBetween('n.updated_at', [$start, $end])
            ->orderBy('n.updated_at', 'desc')
            ->limit(max(1, min(20, $limit)))
            ->get() as $r) {
            $notas[] = (array) $r;
        }

        $fila = [];
        foreach (Capsule::table('mod_opennfse_queue as q')
            ->join('tblinvoices as i', 'i.id', '=', 'q.invoiceid')
            ->join('tblclients as c', 'c.id', '=', 'i.userid')
            ->select([
                'q.invoiceid',
                Capsule::raw("'ERROR' as status"),
                'q.last_error as erro',
                'q.updated_at as data',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->where('q.status', 'ERROR')
            ->whereBetween('q.updated_at', [$start, $end])
            ->orderBy('q.updated_at', 'desc')
            ->limit(max(1, min(20, $limit)))
            ->get() as $r) {
            $fila[] = (array) $r;
        }

        $all = array_merge($notas, $fila);
        usort($all, static function (array $a, array $b): int {
            $da = (string) ($a['data'] ?? '');
            $db = (string) ($b['data'] ?? '');
            if ($da === $db) {
                return 0;
            }
            return $da < $db ? 1 : -1;
        });

        return array_slice($all, 0, max(1, min(20, $limit)));
    }

    private function normalizeDashboardRange(string $fromDate, string $toDate): array
    {
        $fromDate = trim($fromDate);
        $toDate = trim($toDate);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $fromDate = date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $toDate = date('Y-m-t');
        }

        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        return [
            $fromDate,
            $toDate,
            $fromDate . ' 00:00:00',
            $toDate . ' 23:59:59',
        ];
    }

    private function applyFiltersToNotasQuery($q, array $filters): void
    {
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            if (strpos($status, ',') !== false) {
                $parts = array_values(array_filter(array_map('trim', explode(',', $status)), static fn (string $v): bool => $v !== ''));
                if (!empty($parts)) {
                    $q->whereIn('n.status', $parts);
                }
            } else {
                $q->where('n.status', $status);
            }
        }

        $dateField = (string) ($filters['date_field'] ?? '');
        if ($dateField === 'cancelado') {
            $q->whereNotNull('n.cancelado_em');
            $this->applyDateFilter($q, (string) ($filters['data_inicial'] ?? ''), (string) ($filters['data_final'] ?? ''), 'n.cancelado_em');
        } else {
            $this->applyDateFilter($q, (string) ($filters['data_inicial'] ?? ''), (string) ($filters['data_final'] ?? ''), 'COALESCE(n.emitida_em, n.updated_at)');
        }

        $this->applyClientFilter($q, (string) ($filters['cliente'] ?? ''), 'c');
    }

    private function applyClientFilter($q, string $cliente, string $clientAlias): void
    {
        $cliente = trim($cliente);
        if ($cliente === '') {
            return;
        }

        if (ctype_digit($cliente)) {
            $q->where($clientAlias . '.id', (int) $cliente);
            return;
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $cliente) . '%';
        $q->where(static function ($sub) use ($clientAlias, $like) {
            $sub->where($clientAlias . '.companyname', 'like', $like)
                ->orWhere($clientAlias . '.firstname', 'like', $like)
                ->orWhere($clientAlias . '.lastname', 'like', $like);
        });
    }

    private function applyDateFilter($q, string $from, string $to, string $field): void
    {
        $from = trim($from);
        $to = trim($to);

        $fromOk = $from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from);
        $toOk = $to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to);

        if (!$fromOk && !$toOk) {
            return;
        }

        if ($fromOk) {
            $q->whereRaw($field . ' >= ?', [$from . ' 00:00:00']);
        }
        if ($toOk) {
            $q->whereRaw($field . ' <= ?', [$to . ' 23:59:59']);
        }
    }
}
