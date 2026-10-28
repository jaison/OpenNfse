<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class QueueRepository
{
    public function findById(int $id): ?array
    {
        $row = Capsule::table('mod_opennfse_queue')->where('id', $id)->first();
        return $row ? (array) $row : null;
    }

    public function hasActive(int $invoiceId): bool
    {
        $row = Capsule::table('mod_opennfse_queue')
            ->where('invoiceid', $invoiceId)
            ->whereIn('status', ['PENDING', 'RUNNING', 'WAIT_STATUS'])
            ->first();

        return $row !== null;
    }

    public function hasPendingOrRunning(int $invoiceId): bool
    {
        $row = Capsule::table('mod_opennfse_queue')
            ->where('invoiceid', $invoiceId)
            ->whereIn('status', ['PENDING', 'RUNNING'])
            ->first();

        return $row !== null;
    }

    public function hasInFlightSequentialJob(): bool
    {
        $row = Capsule::table('mod_opennfse_queue')
            ->whereIn('status', ['RUNNING', 'WAIT_STATUS'])
            ->orderBy('created_at', 'asc')
            ->first();

        return $row !== null;
    }

    /**
     * Cria o item PENDING se a fatura não tiver item ativo. Retorna false quando já existe um.
     * O check-then-insert não é atômico: quem chama deve segurar o lock da fatura (InvoiceLockService).
     */
    public function enqueue(int $invoiceId, ?string $correlationId = null, ?bool $allowUnpaid = null): bool
    {
        if ($invoiceId <= 0) {
            return false;
        }
        if ($this->hasActive($invoiceId)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_opennfse_queue')->insert([
            'invoiceid' => $invoiceId,
            'correlation_id' => $correlationId,
            'status' => 'PENDING',
            'tentativas' => 0,
            'ultima_tentativa' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'last_error' => null,
            'status_checks' => 0,
            'next_check_at' => null,
            'allow_unpaid' => $allowUnpaid === null ? null : ($allowUnpaid ? 1 : 0),
]);

        return true;
    }

    public function claimNext(int $limit): array
    {
        return $this->claimNextByStatus('PENDING', $limit, true);
    }

    public function claimNextStatus(int $limit): array
    {
        $now = date('Y-m-d H:i:s');

        return $this->claimNextByStatus('WAIT_STATUS', $limit, false, [
            static function ($q) use ($now) {
                $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now);
            },
        ]);
    }

    private function claimNextByStatus(string $status, int $limit, bool $incrementAttempts, array $extraWhere = []): array
    {
        if ($limit <= 0) {
            return [];
        }

        $query = Capsule::table('mod_opennfse_queue')
            ->where('status', $status);

        foreach ($extraWhere as $cb) {
            $query->where($cb);
        }

        $rows = $query->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        $claimed = [];
        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id <= 0) {
                continue;
            }

            $update = [
                'status' => 'RUNNING',
                'ultima_tentativa' => $now,
                'updated_at' => $now,
                'last_error' => null,
            ];
            if ($incrementAttempts) {
                $update['tentativas'] = Capsule::raw('tentativas + 1');
            }

            $updated = Capsule::table('mod_opennfse_queue')
                ->where('id', $id)
                ->where('status', $status)
                ->update($update);

            if ($updated) {
                $claimed[] = (array) $row;
            }
        }

        return $claimed;
    }

    public function markDone(int $id): void
    {
        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_opennfse_queue')->where('id', $id)->update([
            'status' => 'DONE',
            'updated_at' => $now,
            'last_error' => null,
            'next_check_at' => null,
        ]);
    }

    public function markRunningForStatusCheck(int $id): bool
    {
        $now = date('Y-m-d H:i:s');
        $updated = Capsule::table('mod_opennfse_queue')
            ->where('id', $id)
            ->where('status', 'WAIT_STATUS')
            ->update([
                'status' => 'RUNNING',
                'ultima_tentativa' => $now,
                'updated_at' => $now,
                'last_error' => null,
            ]);
        return (bool) $updated;
    }

    public function markWaitStatus(int $id, int $nextIntervalSeconds, ?string $error = null): void
    {
        $now = date('Y-m-d H:i:s');
        $next = date('Y-m-d H:i:s', time() + max(0, $nextIntervalSeconds));
        Capsule::table('mod_opennfse_queue')->where('id', $id)->update([
            'status' => 'WAIT_STATUS',
            'updated_at' => $now,
            'last_error' => $error,
            'status_checks' => 0,
            'next_check_at' => $next,
        ]);
    }

    public function touchWaitStatus(int $id, int $nextIntervalSeconds, ?string $error = null): void
    {
        $now = date('Y-m-d H:i:s');
        $next = date('Y-m-d H:i:s', time() + max(0, $nextIntervalSeconds));
        Capsule::table('mod_opennfse_queue')->where('id', $id)->update([
            'status' => 'WAIT_STATUS',
            'updated_at' => $now,
            'last_error' => $error,
            'status_checks' => Capsule::raw('status_checks + 1'),
            'next_check_at' => $next,
        ]);
    }

    public function markRetry(int $id, string $error): void
    {
        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_opennfse_queue')->where('id', $id)->update([
            'status' => 'PENDING',
            'updated_at' => $now,
            'last_error' => $error,
        ]);
    }

    /**
     * Reprocessar manual: volta para PENDING com as tentativas zeradas, sem agendamento
     * pendente e sem erro. Só altera se o item ainda estiver no status esperado.
     * Não mexe na nota (o contador de reemissão por E2404 é controle fiscal e fica como está).
     */
    public function resetToPending(int $id, string $expectedStatus): bool
    {
        $now = date('Y-m-d H:i:s');
        $updated = Capsule::table('mod_opennfse_queue')
            ->where('id', $id)
            ->where('status', $expectedStatus)
            ->update([
                'status' => 'PENDING',
                'tentativas' => 0,
                'status_checks' => 0,
                'next_check_at' => null,
                'updated_at' => $now,
                'last_error' => null,
            ]);

        return (bool) $updated;
    }

    public function hasActiveOtherThan(int $invoiceId, int $exceptId): bool
    {
        $row = Capsule::table('mod_opennfse_queue')
            ->where('invoiceid', $invoiceId)
            ->where('id', '<>', $exceptId)
            ->whereIn('status', ['PENDING', 'RUNNING', 'WAIT_STATUS'])
            ->first();

        return $row !== null;
    }

    /**
     * Itens em RUNNING desde antes de $cutoff (o claim grava ultima_tentativa).
     */
    public function findStaleRunning(string $cutoff, int $limit = 50): array
    {
        $rows = Capsule::table('mod_opennfse_queue')
            ->where('status', 'RUNNING')
            ->whereRaw('COALESCE(ultima_tentativa, updated_at, created_at) < ?', [$cutoff])
            ->orderBy('created_at', 'asc')
            ->limit(max(1, $limit))
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = (array) $row;
        }

        return $result;
    }

    /**
     * Tira um item travado de RUNNING. Só altera se ele ainda estiver em RUNNING.
     */
    public function recoverStaleRunning(int $id, string $targetStatus, string $message): bool
    {
        $now = date('Y-m-d H:i:s');
        $update = [
            'status' => $targetStatus,
            'updated_at' => $now,
            'last_error' => $message,
        ];
        if ($targetStatus === 'WAIT_STATUS') {
            $update['next_check_at'] = $now;
        }

        $updated = Capsule::table('mod_opennfse_queue')
            ->where('id', $id)
            ->where('status', 'RUNNING')
            ->update($update);

        return (bool) $updated;
    }

    public function markError(int $id, string $error): void
    {
        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_opennfse_queue')->where('id', $id)->update([
            'status' => 'ERROR',
            'updated_at' => $now,
            'last_error' => $error,
        ]);
    }

    public function resolvePreviousErrorsByInvoice(int $invoiceId): int
    {
        if ($invoiceId <= 0) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');

        return (int) Capsule::table('mod_opennfse_queue')
            ->where('invoiceid', $invoiceId)
            ->where('status', 'ERROR')
            ->update([
                'status' => 'RESOLVIDO',
                'updated_at' => $now,
            ]);
    }

    public function resolveErrorsForIssuedInvoices(int $limit = 100): int
    {
        $limit = max(1, $limit);

        $invoiceIds = Capsule::table('mod_opennfse_queue as q')
            ->join('mod_opennfse_notas as n', 'n.invoiceid', '=', 'q.invoiceid')
            ->where('q.status', 'ERROR')
            ->where('n.status', 'EMITIDA')
            ->whereNotNull('n.chave_acesso')
            ->where('n.chave_acesso', '<>', '')
            ->distinct()
            ->limit($limit)
            ->pluck('q.invoiceid')
            ->all();

        $resolved = 0;
        foreach ($invoiceIds as $invoiceId) {
            $resolved += $this->resolvePreviousErrorsByInvoice((int) $invoiceId);
        }

        return $resolved;
    }
}
