<?php

declare(strict_types=1);

namespace OpenNfse\Migrations;

use WHMCS\Database\Capsule;

final class Migrator
{
    /** As migrations rodam no máximo uma vez por processo PHP (cron, lote da fila, hooks, telas). */
    private static bool $ran = false;

    public function up(): void
    {
        if (self::$ran) {
            return;
        }

        $dir = dirname(__DIR__, 2) . '/migrations';
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $sql = trim((string) file_get_contents($file));
            if ($sql === '') {
                continue;
            }
            $statements = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '') {
                    continue;
                }
                try {
                    Capsule::statement($stmt);
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    if (stripos($msg, 'Duplicate column') !== false || stripos($msg, 'already exists') !== false) {
                        continue;
                    }
                    if (stripos($msg, 'Base table or view already exists') !== false) {
                        continue;
                    }
                    if (stripos($msg, 'Duplicate key name') !== false || stripos($msg, 'Duplicate index') !== false) {
                        continue;
                    }
                    throw $e;
                }
            }
        }

        // Só marca depois de tudo aplicado: uma falha faz a próxima chamada tentar de novo.
        self::$ran = true;
    }

    /**
     * @internal Para testes.
     */
    public static function resetForTests(): void
    {
        self::$ran = false;
    }
}
