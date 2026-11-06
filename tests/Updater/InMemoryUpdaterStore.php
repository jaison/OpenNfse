<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Updater;

use OpenNfse\Updater\UpdaterStore;

final class InMemoryUpdaterStore implements UpdaterStore
{
    public array $state = [];

    /** @var list<array{id: int, hook: string, vars: array, replayed: bool, error: ?string}> */
    public array $events = [];

    public function state(): array
    {
        return $this->state;
    }

    public function saveState(array $fields): void
    {
        $this->state = array_merge($this->state, $fields);
    }

    public function addEvent(string $hook, array $vars): void
    {
        $this->events[] = ['id' => count($this->events) + 1, 'hook' => $hook, 'vars' => $vars, 'replayed' => false, 'error' => null];
    }

    public function pendingEvents(int $limit): array
    {
        $pending = [];
        foreach ($this->events as $event) {
            if (!$event['replayed'] && count($pending) < $limit) {
                $pending[] = ['id' => $event['id'], 'hook' => $event['hook'], 'vars' => $event['vars']];
            }
        }

        return $pending;
    }

    public function countPendingEvents(): int
    {
        return count(array_filter($this->events, static fn (array $e): bool => !$e['replayed']));
    }

    public function markEventReplayed(int $id, ?string $error): void
    {
        foreach ($this->events as $i => $event) {
            if ($event['id'] === $id) {
                $this->events[$i]['replayed'] = true;
                $this->events[$i]['error'] = $error;
            }
        }
    }
}
