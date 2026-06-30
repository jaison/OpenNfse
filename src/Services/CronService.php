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
                ->where('status', 'DONE')
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
        if (!$lock->acquire('mod_opennfse_cron')) {
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

            $this->cleanup($config);
        } catch (\Throwable $e) {
            $this->logCronFailure($source, $e);
            throw $e;
        } finally {
            $lock->release('mod_opennfse_cron');
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

        $notas = Capsule::table('mod_opennfse_notas')
            ->where('status', 'PROCESSANDO')
            ->orderBy('updated_at', 'asc')
            ->limit(50)
            ->get();

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
