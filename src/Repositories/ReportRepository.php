<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use OpenNfse\Services\FiscalDocumentReportService;
use WHMCS\Database\Capsule;

final class ReportRepository
{
    public function listNotas(array $filters, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);

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
                'n.cancel_xml_path',
                'n.updated_at as nfse_updated_at',
                Capsule::raw($this->notaReferenceDateExpression('n') . ' as reference_date'),
                'n.erro_api',
                'i.total as invoice_total',
                'c.currency as invoice_currency_id',
                'c.companyname',
                'c.firstname',
                'c.lastname',
                'c.country',
                'cur.prefix as currency_prefix',
                'cur.suffix as currency_suffix',
            ]);

        $this->applyFiltersToNotasQuery($q, $filters);

        $dateField = (string) ($filters['date_field'] ?? '');
        if ($dateField === 'cancelado') {
            $q->orderBy('n.cancelado_em', 'desc');
        } else {
            $q->orderByRaw($this->notaReferenceDateExpression('n') . ' DESC');
        }

        if ($offset > 0) {
            $q->offset($offset);
        }

        $out = [];
        foreach ($q->limit($limit)->get() as $r) {
            $out[] = (array) $r;
        }
        return $out;
    }

    /**
     * Cancelamentos do período (data do cancelamento): registros CANCELAMENTO do histórico fiscal mais as
     * linhas atuais com cancelado_em. A nota cancelada e depois substituída por reemissão só está no histórico.
     */
    public function listCancelamentos(array $filters, int $limit): array
    {
        $limit = max(1, min(5000, $limit));
        $filters['status'] = '';
        $filters['date_field'] = 'cancelado';

        $history = Capsule::table('mod_opennfse_notas_history as h')
            ->join('tblinvoices as i', 'i.id', '=', 'h.invoiceid')
            ->join('tblclients as c', 'c.id', '=', 'h.userid')
            ->leftJoin('tblcurrencies as cur', 'cur.id', '=', 'c.currency')
            ->select([
                'h.invoiceid',
                'h.userid',
                'h.numero_nf',
                Capsule::raw("'CANCELADA' as status"),
                'h.emitida_em',
                'h.cancelado_em',
                'h.chave_acesso',
                'h.xml_path',
                'h.cancel_xml_path',
                'i.total as invoice_total',
                'c.currency as invoice_currency_id',
                'c.companyname',
                'c.firstname',
                'c.lastname',
                'c.country',
                'cur.prefix as currency_prefix',
                'cur.suffix as currency_suffix',
            ])
            ->where('h.tipo_registro', 'CANCELAMENTO')
            ->whereNotNull('h.cancelado_em');

        $invoiceId = trim((string) ($filters['invoiceid'] ?? ''));
        if ($invoiceId !== '' && ctype_digit($invoiceId)) {
            $history->where('h.invoiceid', (int) $invoiceId);
        }
        $this->applyDateFilter($history, (string) ($filters['data_inicial'] ?? ''), (string) ($filters['data_final'] ?? ''), 'h.cancelado_em');
        $this->applyClientFilter($history, (string) ($filters['cliente'] ?? ''), 'c');

        $historyRows = [];
        foreach ($history->orderBy('h.cancelado_em', 'desc')->orderBy('h.id', 'desc')->limit($limit)->get() as $r) {
            $historyRows[] = (array) $r;
        }

        return (new FiscalDocumentReportService())->mergeCancelamentos($historyRows, $this->listNotas($filters, $limit), $limit);
    }

    /**
     * Registros EMISSAO e CANCELAMENTO do histórico fiscal para o ZIP por competência, na mesma janela de datas
     * da tabela atual (data de referência da emissão). Inclui o CANCELAMENTO das chaves emitidas na janela,
     * mesmo se o registro de cancelamento cair fora dela. CANCELAMENTO sem cancelado_em não conta.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listHistoryDocuments(array $filters, int $limit): array
    {
        $limit = max(1, min(20000, $limit));

        $query = $this->historyDocumentsQuery($filters)
            ->leftJoin('mod_opennfse_notas as nk', 'nk.chave_acesso', '=', 'h.chave_acesso')
            // Só para registro sem chave. Se a fatura foi reemitida, a linha de nota_id pode já ser a da
            // nota nova: a data dela é aproximada, mas ainda melhor que h.created_at.
            ->leftJoin('mod_opennfse_notas as nid', static function ($join): void {
                $join->on('nid.id', '=', 'h.nota_id')->whereNull('h.chave_acesso');
            })
            ->where(static function ($where): void {
                $where->where('h.tipo_registro', 'EMISSAO')
                    ->orWhere(static function ($cancel): void {
                        $cancel->where('h.tipo_registro', 'CANCELAMENTO')->whereNotNull('h.cancelado_em');
                    });
            });
        $this->applyDateFilter(
            $query,
            (string) ($filters['data_inicial'] ?? ''),
            (string) ($filters['data_final'] ?? ''),
            $this->historyReferenceDateExpression()
        );

        $rows = [];
        $ids = [];
        $chavesSemCancelamento = [];
        foreach ($query->orderBy('h.id', 'desc')->limit($limit)->get() as $r) {
            $row = (array) $r;
            $rows[] = $row;
            $ids[(int) ($row['id'] ?? 0)] = true;
            $chave = trim((string) ($row['chave_acesso'] ?? ''));
            if ($chave === '') {
                continue;
            }
            if (($row['tipo_registro'] ?? '') === 'CANCELAMENTO') {
                $chavesSemCancelamento[$chave] = false;
            } elseif (!isset($chavesSemCancelamento[$chave])) {
                $chavesSemCancelamento[$chave] = true;
            }
        }

        $chaves = array_keys(array_filter($chavesSemCancelamento));
        foreach (array_chunk($chaves, 500) as $chunk) {
            $extra = $this->historyDocumentsQuery([])
                ->where('h.tipo_registro', 'CANCELAMENTO')
                ->whereNotNull('h.cancelado_em')
                ->whereIn('h.chave_acesso', $chunk)
                ->orderBy('h.id', 'desc')
                ->get();
            foreach ($extra as $r) {
                $row = (array) $r;
                if (!isset($ids[(int) ($row['id'] ?? 0)])) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Das chaves informadas, as que têm EMISSAO ou CANCELAMENTO (com cancelado_em) no histórico, em qualquer data.
     * O complemento do ZIP pela tabela atual ignora essas chaves: o histórico decide o mês delas.
     *
     * @param array<int, string> $chaves
     * @return array<int, string>
     */
    public function listHistoryChaves(array $chaves): array
    {
        $chaves = array_values(array_unique(array_filter(array_map(
            static fn ($chave): string => trim((string) $chave),
            $chaves
        ), static fn (string $chave): bool => $chave !== '')));

        $out = [];
        foreach (array_chunk($chaves, 500) as $chunk) {
            $rows = Capsule::table('mod_opennfse_notas_history as h')
                ->select(['h.chave_acesso'])
                ->whereIn('h.chave_acesso', $chunk)
                ->where(static function ($where): void {
                    $where->where('h.tipo_registro', 'EMISSAO')
                        ->orWhere(static function ($cancel): void {
                            $cancel->where('h.tipo_registro', 'CANCELAMENTO')->whereNotNull('h.cancelado_em');
                        });
                })
                ->distinct()
                ->get();
            foreach ($rows as $row) {
                $out[] = (string) ($row->chave_acesso ?? '');
            }
        }

        return $out;
    }

    private function historyDocumentsQuery(array $filters)
    {
        $query = Capsule::table('mod_opennfse_notas_history as h')
            ->join('tblinvoices as i', 'i.id', '=', 'h.invoiceid')
            ->join('tblclients as c', 'c.id', '=', 'h.userid')
            ->select([
                'h.id',
                'h.invoiceid',
                'h.userid',
                'h.tipo_registro',
                'h.numero_nf',
                'h.chave_acesso',
                'h.xml_path',
                'h.cancel_xml_path',
                'h.emitida_em',
                'h.cancelado_em',
                'c.country',
            ]);

        $invoiceId = trim((string) ($filters['invoiceid'] ?? ''));
        if ($invoiceId !== '' && ctype_digit($invoiceId)) {
            $query->where('h.invoiceid', (int) $invoiceId);
        }
        $this->applyClientFilter($query, (string) ($filters['cliente'] ?? ''), 'c');

        return $query;
    }

    /**
     * Data de referência da emissão no histórico. Sem emitida_em e sem competencia, usa a da linha atual
     * da mesma chave (ou da mesma nota_id, quando o registro não tem chave); h.created_at, que é a data
     * de gravação no histórico, fica só como último recurso.
     * A linha de nota_id (nid) pode ser a da nota nova, se a fatura foi reemitida: é uma data aproximada,
     * melhor que h.created_at.
     */
    private function historyReferenceDateExpression(): string
    {
        return "COALESCE(h.emitida_em, CONCAT(h.competencia, ' 00:00:00'), "
            . "nk.emitida_em, CONCAT(nk.competencia, ' 00:00:00'), nk.created_at, "
            . "nid.emitida_em, CONCAT(nid.competencia, ' 00:00:00'), nid.created_at, h.created_at)";
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

    public function dashboardDailySeries(string $fromDate = '', string $toDate = ''): array
    {
        [$startDate, $endDate, $start, $end] = $this->normalizeDashboardRange($fromDate, $toDate);

        $series = [];
        $cursor = new \DateTimeImmutable($startDate);
        $endCursor = new \DateTimeImmutable($endDate);
        while ($cursor <= $endCursor) {
            $key = $cursor->format('Y-m-d');
            $series[$key] = [
                'date' => $key,
                'label' => $cursor->format('d/m'),
                'emitidas' => 0,
                'canceladas' => 0,
                'erros_resolvidos' => 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        foreach (Capsule::table('mod_opennfse_notas as n')
            ->select([
                Capsule::raw('DATE(n.emitida_em) as dia'),
                Capsule::raw('COUNT(*) as total'),
            ])
            ->where('n.status', 'EMITIDA')
            ->whereBetween('n.emitida_em', [$start, $end])
            ->groupBy(Capsule::raw('DATE(n.emitida_em)'))
            ->get() as $row) {
            $dia = (string) ($row->dia ?? '');
            if (isset($series[$dia])) {
                $series[$dia]['emitidas'] = (int) ($row->total ?? 0);
            }
        }

        $series = self::addDailyCounts(
            $series,
            $this->notasCanceladasForaDoHistorico($start, $end, 'n.cancelado_em')->get(),
            'canceladas'
        );
        $series = self::addDailyCounts(
            $series,
            self::countByDay($this->historicoCancelamentosPorDocumento($start, $end)),
            'canceladas'
        );

        $issueSets = [];
        foreach (Capsule::table('mod_opennfse_notas as n')
            ->select([
                'n.invoiceid',
                Capsule::raw('DATE(n.updated_at) as dia'),
            ])
            ->whereIn('n.status', ['ERRO', 'REJEITADA'])
            ->whereBetween('n.updated_at', [$start, $end])
            ->get() as $row) {
            $dia = (string) ($row->dia ?? '');
            $invoiceId = (int) ($row->invoiceid ?? 0);
            if ($invoiceId > 0 && isset($series[$dia])) {
                $issueSets[$dia][$invoiceId] = true;
            }
        }

        foreach (Capsule::table('mod_opennfse_queue as q')
            ->select([
                'q.invoiceid',
                Capsule::raw('DATE(q.updated_at) as dia'),
            ])
            ->whereIn('q.status', ['ERROR', 'RESOLVIDO'])
            ->whereBetween('q.updated_at', [$start, $end])
            ->get() as $row) {
            $dia = (string) ($row->dia ?? '');
            $invoiceId = (int) ($row->invoiceid ?? 0);
            if ($invoiceId > 0 && isset($series[$dia])) {
                $issueSets[$dia][$invoiceId] = true;
            }
        }

        foreach ($issueSets as $dia => $invoiceIds) {
            if (isset($series[$dia])) {
                $series[$dia]['erros_resolvidos'] = count($invoiceIds);
            }
        }

        return array_values($series);
    }

    public function dashboardOverview(string $fromDate = '', string $toDate = ''): array
    {
        [$startDate, $endDate, $start, $end] = $this->normalizeDashboardRange($fromDate, $toDate);

        $emitidas = (int) Capsule::table('mod_opennfse_notas as n')
            ->where('n.status', 'EMITIDA')
            ->whereBetween('n.emitida_em', [$start, $end])
            ->count();

        // Canceladas: documentos do histórico mais as linhas cuja chave não tem cancelamento no histórico.
        $canceladas = (int) $this->notasCanceladasForaDoHistorico($start, $end)->count()
            + count($this->historicoCancelamentosPorDocumento($start, $end));

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
        foreach (Capsule::table('mod_opennfse_notas as n')
            ->select(['n.invoiceid'])
            ->where('n.status', 'PROCESSANDO')
            ->whereBetween('n.updated_at', [$start, $end])
            ->get() as $r) {
            $pendSet[(int) ($r->invoiceid ?? 0)] = true;
        }
        foreach (Capsule::table('mod_opennfse_queue as q')
            ->select(['q.invoiceid'])
            ->whereIn('q.status', ['PENDING', 'RUNNING', 'WAIT_STATUS'])
            ->whereBetween('q.updated_at', [$start, $end])
            ->distinct()
            ->get() as $r) {
            $pendSet[(int) ($r->invoiceid ?? 0)] = true;
        }
        unset($pendSet[0]);
        $pendentes = count($pendSet);

        $waitStatusSet = [];
        foreach (Capsule::table('mod_opennfse_queue as q')
            ->select(['q.invoiceid'])
            ->where('q.status', 'WAIT_STATUS')
            ->whereBetween('q.updated_at', [$start, $end])
            ->distinct()
            ->get() as $r) {
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

        $erroResolvidoPeriodoSet = [];
        foreach (Capsule::table('mod_opennfse_notas as n')
            ->select(['n.invoiceid'])
            ->whereIn('n.status', ['ERRO', 'REJEITADA'])
            ->whereBetween('n.updated_at', [$start, $end])
            ->get() as $r) {
            $erroResolvidoPeriodoSet[(int) ($r->invoiceid ?? 0)] = true;
        }
        foreach (Capsule::table('mod_opennfse_queue as q')
            ->select(['q.invoiceid'])
            ->whereIn('q.status', ['ERROR', 'RESOLVIDO'])
            ->whereBetween('q.updated_at', [$start, $end])
            ->distinct()
            ->get() as $r) {
            $erroResolvidoPeriodoSet[(int) ($r->invoiceid ?? 0)] = true;
        }
        unset($erroResolvidoPeriodoSet[0]);
        $errosResolvidosPeriodo = count($erroResolvidoPeriodoSet);

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
            'erros_resolvidos_periodo' => $errosResolvidosPeriodo,
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
        $limit = max(1, min(20, $limit));

        $notas = [];
        $query = $this->notasCanceladasForaDoHistorico($start, $end)
            ->join('tblclients as c', 'c.id', '=', 'n.userid')
            ->select([
                'n.invoiceid',
                'n.numero_nf',
                'n.cancelado_em as data',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->orderBy('n.cancelado_em', 'desc')
            ->limit($limit);
        foreach ($query->get() as $r) {
            $notas[] = (array) $r;
        }

        // Folga no limite: a mesma chave pode ter mais de um registro CANCELAMENTO.
        $historico = [];
        $query = $this->historicoCancelamentos($start, $end)
            ->join('tblclients as c', 'c.id', '=', 'h.userid')
            ->select([
                'h.invoiceid',
                'h.numero_nf',
                'h.chave_acesso',
                'h.cancelado_em as data',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->orderBy('h.cancelado_em', 'desc')
            ->limit($limit * 4);
        foreach ($query->get() as $r) {
            $historico[] = (array) $r;
        }

        return self::mergeRecentByDate(self::recentesPorDocumento($historico), $notas, $limit);
    }

    /**
     * Soma ao campo de cada dia da série as linhas agrupadas (dia, total).
     *
     * @param array<string, array<string, mixed>> $series
     * @param iterable<object|array> $rows
     * @return array<string, array<string, mixed>>
     */
    public static function addDailyCounts(array $series, iterable $rows, string $field): array
    {
        foreach ($rows as $row) {
            $row = (array) $row;
            $dia = (string) ($row['dia'] ?? '');
            if (isset($series[$dia])) {
                $series[$dia][$field] = (int) ($series[$dia][$field] ?? 0) + (int) ($row['total'] ?? 0);
            }
        }

        return $series;
    }

    /**
     * Junta duas listas já ordenadas por 'data' (mais nova primeiro) e devolve as mais novas.
     *
     * @param array<int, array<string, mixed>> $a
     * @param array<int, array<string, mixed>> $b
     * @return array<int, array<string, mixed>>
     */
    public static function mergeRecentByDate(array $a, array $b, int $limit): array
    {
        $all = array_merge($a, $b);
        usort($all, static fn (array $x, array $y): int => strcmp((string) ($y['data'] ?? ''), (string) ($x['data'] ?? '')));

        return array_slice($all, 0, max(1, $limit));
    }

    /**
     * Linhas atuais com cancelado_em no período cuja chave não tem CANCELAMENTO (com data) no histórico.
     * Linha sem chave conta pela tabela atual. Sem join com fatura ou cliente, como antes.
     * Com $groupByDay, devolve (dia, total) por DATE(cancelado_em).
     */
    private function notasCanceladasForaDoHistorico(string $start, string $end, ?string $groupByDay = null)
    {
        $query = Capsule::table('mod_opennfse_notas as n')
            ->whereNotNull('n.cancelado_em')
            ->whereBetween('n.cancelado_em', [$start, $end])
            ->whereNotExists(static function ($sub): void {
                $sub->select(Capsule::raw('1'))
                    ->from('mod_opennfse_notas_history as hc')
                    ->where('hc.tipo_registro', 'CANCELAMENTO')
                    ->whereNotNull('hc.cancelado_em')
                    ->whereRaw('hc.chave_acesso = n.chave_acesso');
            });

        return $groupByDay !== null ? $this->groupByDay($query, $groupByDay) : $query;
    }

    /**
     * Registros CANCELAMENTO do histórico com cancelado_em no período.
     */
    private function historicoCancelamentos(string $start, string $end)
    {
        return Capsule::table('mod_opennfse_notas_history as h')
            ->where('h.tipo_registro', 'CANCELAMENTO')
            ->whereNotNull('h.cancelado_em')
            ->whereBetween('h.cancelado_em', [$start, $end]);
    }

    /**
     * Cancelamentos do histórico no período, um por documento (ver cancelamentosPorDocumento).
     *
     * @return array<string, string>
     */
    private function historicoCancelamentosPorDocumento(string $start, string $end): array
    {
        return self::cancelamentosPorDocumento(
            $this->historicoCancelamentos($start, $end)
                ->select(['h.invoiceid', 'h.chave_acesso', 'h.cancelado_em'])
                ->get()
        );
    }

    /**
     * Um cancelamento por documento, como na aba Cancelamentos: a mesma chave com mais de um registro
     * CANCELAMENTO conta uma vez, no cancelado_em mais recente. Sem chave, conta por fatura e data do
     * cancelamento, como a aba.
     *
     * @param iterable<object|array> $rows linhas com invoiceid, chave_acesso e cancelado_em
     * @return array<string, string> documento => cancelado_em
     */
    public static function cancelamentosPorDocumento(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $canceladoEm = trim((string) ($row['cancelado_em'] ?? ''));
            if ($canceladoEm === '') {
                continue;
            }
            $doc = self::documentoCancelado($row, $canceladoEm);
            if (!isset($out[$doc]) || strcmp($canceladoEm, $out[$doc]) > 0) {
                $out[$doc] = $canceladoEm;
            }
        }

        return $out;
    }

    /**
     * Cancelamentos recentes do histórico (data em 'data'), um por documento, com a mesma regra
     * de cancelamentosPorDocumento: fica a linha com a data mais recente.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function recentesPorDocumento(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $data = trim((string) ($row['data'] ?? ''));
            if ($data === '') {
                continue;
            }
            $doc = self::documentoCancelado($row, $data);
            if (!isset($out[$doc]) || strcmp($data, trim((string) ($out[$doc]['data'] ?? ''))) > 0) {
                $out[$doc] = $row;
            }
        }

        return array_values($out);
    }

    /**
     * Documento do cancelamento: a chave; sem chave, fatura e data do cancelamento (como a aba Cancelamentos).
     */
    private static function documentoCancelado(array $row, string $canceladoEm): string
    {
        $chave = trim((string) ($row['chave_acesso'] ?? ''));

        return $chave !== '' ? 'chave:' . $chave : 'evento:' . (int) ($row['invoiceid'] ?? 0) . '|' . $canceladoEm;
    }

    /**
     * Conta as datas por dia, no formato (dia, total) do addDailyCounts.
     *
     * @param array<array-key, string> $datas
     * @return array<int, array{dia: string, total: int}>
     */
    public static function countByDay(array $datas): array
    {
        $totais = [];
        foreach ($datas as $data) {
            $dia = substr(trim((string) $data), 0, 10);
            $totais[$dia] = ($totais[$dia] ?? 0) + 1;
        }

        $out = [];
        foreach ($totais as $dia => $total) {
            $out[] = ['dia' => (string) $dia, 'total' => $total];
        }

        return $out;
    }

    private function groupByDay($query, string $field)
    {
        return $query
            ->select([
                Capsule::raw('DATE(' . $field . ') as dia'),
                Capsule::raw('COUNT(*) as total'),
            ])
            ->groupBy(Capsule::raw('DATE(' . $field . ')'));
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
                'q.status as status',
                'q.last_error as erro',
                'q.updated_at as data',
                'c.companyname',
                'c.firstname',
                'c.lastname',
            ])
            ->whereIn('q.status', ['ERROR', 'RESOLVIDO'])
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

    public function listAuditoriaInvoices(array $filters, int $limit): array
    {
        $limit = max(1, min(5000, $limit));

        $q = $this->buildAuditoriaInvoicesQuery($filters)
            ->leftJoin('tblcurrencies as cur', 'cur.id', '=', 'c.currency')
            ->select([
                'i.id as invoice_id',
                'i.userid',
                'i.datepaid as paid_at',
                'i.paymentmethod',
                'i.total as invoice_total',
                'i.credit as applied_credit',
                'c.companyname',
                'c.firstname',
                'c.lastname',
                'cur.prefix as currency_prefix',
                'cur.suffix as currency_suffix',
            ])
            ->orderBy('i.datepaid', 'desc')
            ->orderBy('i.id', 'desc');

        $out = [];
        foreach ($q->limit($limit)->get() as $r) {
            $out[] = (array) $r;
        }

        return $out;
    }

    public function summaryAuditoriaInvoices(array $filters): array
    {
        $row = $this->buildAuditoriaInvoicesQuery($filters)
            ->select([
                Capsule::raw('COUNT(*) as total_invoices'),
                Capsule::raw('SUM(i.total) as total_valor'),
            ])
            ->first();

        $arr = $row ? (array) $row : [];

        return [
            'total_invoices' => (int) ($arr['total_invoices'] ?? 0),
            'total_valor' => (float) ($arr['total_valor'] ?? 0),
        ];
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

    private function buildAuditoriaInvoicesQuery(array $filters)
    {
        $q = Capsule::table('tblinvoices as i')
            ->join('tblclients as c', 'c.id', '=', 'i.userid')
            ->where('i.status', 'Paid')
            ->where('i.total', '>', 0)
            ->whereNotNull('i.datepaid')
            ->where('i.datepaid', '<>', '0000-00-00 00:00:00')
            ->whereNotExists(static function ($sub) {
                $sub->select(Capsule::raw('1'))
                    ->from('mod_opennfse_notas as n')
                    ->whereRaw('n.invoiceid = i.id');
            })
            ->whereNotExists(static function ($sub) {
                $sub->select(Capsule::raw('1'))
                    ->from('mod_opennfse_queue as q')
                    ->whereRaw('q.invoiceid = i.id');
            });

        $gateways = array_values(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            is_array($filters['gateways'] ?? null) ? $filters['gateways'] : []
        ), static fn (string $value): bool => $value !== ''));

        if (empty($gateways)) {
            $q->whereRaw('1 = 0');
        } else {
            $placeholders = implode(',', array_fill(0, count($gateways), '?'));
            $q->whereRaw('LOWER(i.paymentmethod) IN (' . $placeholders . ')', $gateways);
        }

        $this->applyDateFilter($q, (string) ($filters['data_inicial'] ?? ''), (string) ($filters['data_final'] ?? ''), 'i.datepaid');

        return $q;
    }

    private function applyFiltersToNotasQuery($q, array $filters): void
    {
        $invoiceId = trim((string) ($filters['invoiceid'] ?? ''));
        if ($invoiceId !== '' && ctype_digit($invoiceId)) {
            $q->where('n.invoiceid', (int) $invoiceId);
        }

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
            $this->applyDateFilter($q, (string) ($filters['data_inicial'] ?? ''), (string) ($filters['data_final'] ?? ''), $this->notaReferenceDateExpression('n'));
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

    private function notaReferenceDateExpression(string $alias = 'n'): string
    {
        return "COALESCE({$alias}.emitida_em, CONCAT({$alias}.competencia, ' 00:00:00'), {$alias}.created_at)";
    }
}
