<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

/**
 * Pausa da operação durante a atualização (etapa 2).
 *
 * Com a pausa ativa, nada do OpenNfse roda: hooks de fatura guardam o evento para
 * depois, o cron e as migrations não rodam, a área do cliente mostra só um aviso e
 * o admin só abre a tela de atualização. A marca fica no banco, então sobrevive à
 * troca dos arquivos. Se a tabela ainda não existe, a pausa conta como desligada.
 */
final class MaintenanceMode
{
    /** Tempo para terminar requisições que começaram antes da pausa. */
    public const SETTLE_SECONDS = 60;

    private static ?UpdaterStore $store = null;

    private static ?bool $paused = null;

    public static function useStore(?UpdaterStore $store): void
    {
        self::$store = $store;
        self::$paused = null;
    }

    public static function store(): UpdaterStore
    {
        return self::$store ??= new CapsuleUpdaterStore();
    }

    public static function isPaused(): bool
    {
        if (self::$paused === null) {
            try {
                self::$paused = (int) (self::store()->state()['paused'] ?? 0) === 1;
            } catch (\Throwable $e) {
                self::$paused = false;
            }
        }

        return self::$paused;
    }

    public static function pause(int $adminId, string $fromVersion, string $toVersion): void
    {
        self::store()->saveState([
            'paused' => 1,
            'paused_at' => date('Y-m-d H:i:s'),
            'paused_by' => $adminId > 0 ? $adminId : null,
            'step' => 'paused',
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
        ]);
        self::$paused = true;
    }

    public static function resume(): void
    {
        self::store()->saveState(['paused' => 0, 'step' => null]);
        self::$paused = false;
    }

    /**
     * Pausa ativa há tempo suficiente e sem cron rodando: dá para seguir para o backup.
     *
     * @param callable(): bool $cronIdle
     */
    public static function isSettled(callable $cronIdle, ?int $now = null): bool
    {
        if (!self::isPaused()) {
            return false;
        }
        $pausedAt = strtotime((string) (self::store()->state()['paused_at'] ?? ''));
        if ($pausedAt === false) {
            return false;
        }
        if (($now ?? time()) - $pausedAt < self::SETTLE_SECONDS) {
            return false;
        }

        return (bool) $cronIdle();
    }

    /**
     * Guarda o evento de hook para rodar na volta. Só mantém valores simples.
     *
     * @param array<string, mixed> $vars
     */
    public static function defer(string $hook, array $vars): void
    {
        self::store()->addEvent($hook, self::scalarVars($vars));
    }

    /**
     * @param array<mixed> $vars
     * @return array<mixed>
     */
    private static function scalarVars(array $vars): array
    {
        $clean = [];
        foreach ($vars as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } elseif (is_array($value)) {
                $clean[$key] = self::scalarVars($value);
            }
        }

        return $clean;
    }
}
