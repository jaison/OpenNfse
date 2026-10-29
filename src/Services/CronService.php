<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Migrations\Migrator;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use WHMCS\Database\Capsule;

final class CronService
{
    private const SOURCE_STANDALONE = 'standalone';
    private const SOURCE_WHMCS_CRON = 'whmcs_cron';

    // Intervalo mínimo entre dois logs CRON_LOCK_BUSY, para não inundar a tabela de logs.
    public const LOCK_BUSY_LOG_INTERVAL_SECONDS = 900;

    public function run(): void
    {
        $this->runInternal(self::SOURCE_STANDALONE);
    }

    public function runFromWhmcsCron(): void
    {
        $this->runInternal(self::SOURCE_WHMCS_CRON);
    }

    private function cleanup(array $config): void
    {
        $logsDays = (int) ($config['logs_retention_days'] ?? 90);
        if ($logsDays > 0) {
            $cutoff = date('Y-m-d H:i:s', time() - ($logsDays * 86400));
            Capsule::table('mod_opennfse_logs')->where('created_at', '<', $cutoff)->delete();
        }

        $queueDays = (int) ($config['queue_done_retention_days'] ?? 30);
        if ($queueDays > 0) {
            $cutoff = date('Y-m-d H:i:s', time() - ($queueDays * 86400));
            Capsule::table('mod_opennfse_queue')
                ->whereIn('status', ['DONE', 'RESOLVIDO'])
                ->where('updated_at', '<', $cutoff)
                ->delete();
        }
    }

    private function runInternal(string $source): void
    {
        (new Migrator())->up();
        $configRepo = new ConfigRepository();
        $config = $configRepo->get();
        if (empty($config)) {
            return;
        }

        $lock = new CronLockService();
        $lockName = $lock->cronLockName();
        if (!$lock->acquire($lockName)) {
            $this->registerLockBusy($configRepo, $config, $source, $lockName);
            return;
        }

        try {
            $config = $configRepo->get();
            if (empty($config) || !$this->shouldRunForCurrentMinute($config)) {
                return;
            }

            $this->markCronRun($configRepo, $source);

            if ((string) ($config['queue_enabled'] ?? '0') === '1') {
                $this->processAutomationBatch();
            }

            // Novas tentativas do e-mail automático: valem também com a fila desligada (emissão direta).
            (new AutoEmailService())->processRetries();

            (new UpdateCheckService())->autoCheckIfDue($config);
            $this->cleanup($config);
        } catch (\Throwable $e) {
            $this->logCronFailure($source, $e);
            throw $e;
        } finally {
            $lock->release($lockName);
        }
    }

    /**
     * Grava um log CRON_LOCK_BUSY por ocorrência: enquanto o lock seguir ocupado,
     * novos logs só saem depois de LOCK_BUSY_LOG_INTERVAL_SECONDS.
     */
    public static function shouldLogLockBusy(
        ?string $lastLoggedAt,
        int $now,
        int $interval = self::LOCK_BUSY_LOG_INTERVAL_SECONDS
    ): bool {
        $lastLoggedAt = trim((string) $lastLoggedAt);
        if ($lastLoggedAt === '') {
            return true;
        }

        $last = strtotime($lastLoggedAt);
        if ($last === false) {
            return true;
        }

        return ($now - $last) >= $interval;
    }

    private function registerLockBusy(
        ConfigRepository $configRepo,
        array $config,
        string $source,
        string $lockName
    ): void {
        try {
            if (!self::shouldLogLockBusy($config['cron_lock_busy_at'] ?? null, time())) {
                return;
            }

            $configRepo->save(['cron_lock_busy_at' => date('Y-m-d H:i:s')]);
            (new LogRepository())->insert(
                null,
                'CRON_LOCK_BUSY',
                json_encode(
                    ['source' => $source, 'lock' => $lockName],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'Outro processamento do cron ainda está com o lock. Execução ignorada.'
            );
        } catch (\Throwable $e) {
        }
    }

    private function shouldRunForCurrentMinute(array $config): bool
    {
        $lastMinuteKey = trim((string) ($config['cron_last_minute_key'] ?? ''));
        return $lastMinuteKey !== $this->getCurrentMinuteKey();
    }

    private function markCronRun(ConfigRepository $configRepo, string $source): void
    {
        $configRepo->save([
            'cron_last_run_at' => date('Y-m-d H:i:s'),
            'cron_last_source' => $source,
            'cron_last_minute_key' => $this->getCurrentMinuteKey(),
        ]);
    }

    private function processAutomationBatch(): void
    {
        (new QueueService())->processBatch(20);

        $activeQueueInvoiceIds = Capsule::table('mod_opennfse_queue')
            ->whereIn('status', ['PENDING', 'RUNNING', 'WAIT_STATUS'])
            ->distinct()
            ->pluck('invoiceid')
            ->all();

        $query = Capsule::table('mod_opennfse_notas')
            ->where('status', 'PROCESSANDO')
            ->orderByRaw('COALESCE(last_status_checked_at, updated_at, created_at) ASC')
            ->limit(50);

        if (!empty($activeQueueInvoiceIds)) {
            $query->whereNotIn('invoiceid', array_map('intval', $activeQueueInvoiceIds));
        }

        $notas = $query->get();

        $nfseService = new NfseService();
        $notaRepo = new NotaRepository();
        $logRepo = new LogRepository();

        foreach ($notas as $row) {
            $invoiceId = (int) ($row->invoiceid ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }

            try {
                $nfseService->consultarStatus($invoiceId);
            } catch (\Throwable $e) {
                $notaRepo->upsert([
                    'invoiceid' => $invoiceId,
                    'userid' => (int) ($row->userid ?? 0),
                    'status' => 'ERRO',
                    'erro_api' => $e->getMessage(),
                ]);
                $logRepo->insert((int) ($row->id ?? 0), 'CRON_ERROR', null, $e->getMessage());
            }
        }

        $this->recoverMissingKeysForIssuedNotas();
    }

    private function recoverMissingKeysForIssuedNotas(): void
    {
        $activeQueueInvoiceIds = Capsule::table('mod_opennfse_queue')
            ->whereIn('status', ['PENDING', 'RUNNING', 'WAIT_STATUS'])
            ->distinct()
            ->pluck('invoiceid')
            ->all();

        $query = Capsule::table('mod_opennfse_notas')
            ->where('status', 'EMITIDA')
            ->where(static function ($q) {
                $q->whereNull('chave_acesso')->orWhere('chave_acesso', '');
            })
            ->orderByRaw('COALESCE(last_status_checked_at, updated_at, created_at) ASC')
            ->limit(50);

        if (!empty($activeQueueInvoiceIds)) {
            $query->whereNotIn('invoiceid', array_map('intval', $activeQueueInvoiceIds));
        }

        $notas = $query->get();
        if ($notas->isEmpty()) {
            return;
        }

        $nfseService = new NfseService();
        $logRepo = new LogRepository();

        foreach ($notas as $row) {
            $invoiceId = (int) ($row->invoiceid ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }

            try {
                $nfseService->consultarStatus($invoiceId);
            } catch (\Throwable $e) {
                $logRepo->insert((int) ($row->id ?? 0), 'CRON_RECOVER_KEY_ERROR', json_encode(['invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE), $e->getMessage());
            }
        }
    }

    private function logCronFailure(string $source, \Throwable $e): void
    {
        try {
            (new LogRepository())->insert(
                null,
                'CRON_ERROR',
                json_encode(['source' => $source], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $e->getMessage()
            );
        } catch (\Throwable $loggingError) {
        }
    }

    private function getCurrentMinuteKey(): string
    {
        return date('YmdHi');
    }
}
