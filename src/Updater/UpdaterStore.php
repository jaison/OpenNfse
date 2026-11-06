<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

/**
 * Estado do atualizador (uma linha) e eventos de hook guardados durante a pausa.
 */
interface UpdaterStore
{
    /** @return array<string, mixed> linha atual; [] se não existir */
    public function state(): array;

    /** @param array<string, mixed> $fields */
    public function saveState(array $fields): void;

    /** @param array<string, mixed> $vars */
    public function addEvent(string $hook, array $vars): void;

    /** @return list<array{id: int, hook: string, vars: array<string, mixed>}> */
    public function pendingEvents(int $limit): array;

    public function countPendingEvents(): int;

    public function markEventReplayed(int $id, ?string $error): void;
}
