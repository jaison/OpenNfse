<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

use WHMCS\Database\Capsule;

final class CapsuleUpdaterStore implements UpdaterStore
{
    private const STATE_TABLE = 'mod_opennfse_updater';
    private const EVENTS_TABLE = 'mod_opennfse_updater_events';
    private const ROW_ID = 1;

    public function state(): array
    {
        $row = Capsule::table(self::STATE_TABLE)->where('id', self::ROW_ID)->first();

        return $row ? (array) $row : [];
    }

    public function saveState(array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $exists = Capsule::table(self::STATE_TABLE)->where('id', self::ROW_ID)->exists();
        if ($exists) {
            Capsule::table(self::STATE_TABLE)->where('id', self::ROW_ID)->update($fields);

            return;
        }
        Capsule::table(self::STATE_TABLE)->insert(array_merge(['id' => self::ROW_ID], $fields));
    }

    public function addEvent(string $hook, array $vars): void
    {
        Capsule::table(self::EVENTS_TABLE)->insert([
            'hook' => $hook,
            'vars' => (string) json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function pendingEvents(int $limit): array
    {
        $rows = Capsule::table(self::EVENTS_TABLE)
            ->whereNull('replayed_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $events = [];
        foreach ($rows as $row) {
            $vars = json_decode((string) ($row->vars ?? ''), true);
            $events[] = [
                'id' => (int) $row->id,
                'hook' => (string) $row->hook,
                'vars' => is_array($vars) ? $vars : [],
            ];
        }

        return $events;
    }

    public function countPendingEvents(): int
    {
        return (int) Capsule::table(self::EVENTS_TABLE)->whereNull('replayed_at')->count();
    }

    public function markEventReplayed(int $id, ?string $error): void
    {
        Capsule::table(self::EVENTS_TABLE)->where('id', $id)->update([
            'replayed_at' => date('Y-m-d H:i:s'),
            'error' => $error,
        ]);
    }
}
