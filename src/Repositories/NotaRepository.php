<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

use WHMCS\Database\Capsule;

final class NotaRepository
{
    public function findByInvoiceId(int $invoiceId): ?array
    {
        $row = Capsule::table('mod_opennfse_notas')->where('invoiceid', $invoiceId)->first();
        return $row ? (array) $row : null;
    }

    /**
     * Claim atômico do e-mail automático da nota para a chave atual. Só um processo consegue:
     * o UPDATE exige a nota EMITIDA com essa chave e que o e-mail ainda não tenha sido disparado
     * para ela. Uma reemissão (chave nova) libera um novo e-mail; a mesma chave nunca duplica.
     */
    public function claimAutomaticEmail(int $notaId, string $chave): bool
    {
        $chave = trim($chave);
        if ($notaId <= 0 || $chave === '') {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $updated = Capsule::table('mod_opennfse_notas')
            ->where('id', $notaId)
            ->where('status', 'EMITIDA')
            ->where('chave_acesso', $chave)
            ->where(static function ($q) use ($chave) {
                $q->whereNull('email_automatico_chave')->orWhere('email_automatico_chave', '<>', $chave);
            })
            ->update([
                'email_automatico_chave' => $chave,
                'email_automatico_processado_at' => $now,
                'email_automatico_status' => 'PROCESSANDO',
                'email_automatico_tentativas' => 1,
                'email_automatico_proxima_tentativa_at' => null,
                'updated_at' => $now,
            ]);

        return $updated === 1;
    }

    /**
     * Claim atômico de uma nova tentativa depois de falha. Exige que a falha seja da chave atual
     * e que a próxima tentativa já esteja vencida, para que só um processo a execute.
     */
    public function claimAutomaticEmailRetry(int $notaId, string $chave, string $now): bool
    {
        $chave = trim($chave);
        if ($notaId <= 0 || $chave === '') {
            return false;
        }

        $updated = Capsule::table('mod_opennfse_notas')
            ->where('id', $notaId)
            ->where('status', 'EMITIDA')
            ->where('chave_acesso', $chave)
            ->where('email_automatico_chave', $chave)
            ->where('email_automatico_status', 'FALHOU')
            ->whereNotNull('email_automatico_proxima_tentativa_at')
            ->where('email_automatico_proxima_tentativa_at', '<=', $now)
            ->update([
                'email_automatico_processado_at' => $now,
                'email_automatico_status' => 'PROCESSANDO',
                'email_automatico_tentativas' => Capsule::raw('email_automatico_tentativas + 1'),
                'email_automatico_proxima_tentativa_at' => null,
                'updated_at' => $now,
            ]);

        return $updated === 1;
    }

    /**
     * Notas com nova tentativa de e-mail automático vencida, das mais antigas para as mais novas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAutomaticEmailRetriesDue(string $now, int $limit): array
    {
        $rows = Capsule::table('mod_opennfse_notas')
            ->where('email_automatico_status', 'FALHOU')
            ->whereNotNull('email_automatico_proxima_tentativa_at')
            ->where('email_automatico_proxima_tentativa_at', '<=', $now)
            ->where('status', 'EMITIDA')
            ->whereColumn('email_automatico_chave', 'chave_acesso')
            ->orderBy('email_automatico_proxima_tentativa_at', 'asc')
            ->limit(max(1, $limit))
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = (array) $row;
        }

        return $result;
    }

    public function markAutomaticEmailSent(int $notaId, string $chave): void
    {
        Capsule::table('mod_opennfse_notas')
            ->where('id', $notaId)
            ->where('email_automatico_chave', $chave)
            ->update([
                'email_automatico_status' => 'ENVIADO',
                'email_automatico_proxima_tentativa_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * @param string|null $nextRetryAt próxima tentativa, ou null quando as tentativas acabaram
     */
    public function markAutomaticEmailFailed(int $notaId, string $chave, ?string $nextRetryAt): void
    {
        Capsule::table('mod_opennfse_notas')
            ->where('id', $notaId)
            ->where('email_automatico_chave', $chave)
            ->update([
                'email_automatico_status' => 'FALHOU',
                'email_automatico_proxima_tentativa_at' => $nextRetryAt,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Envio manual bem-sucedido da nota atual: cancela a nova tentativa automática pendente
     * dessa chave, para o cliente não receber o mesmo e-mail duas vezes. O status FALHOU fica.
     */
    public function cancelAutomaticEmailRetryAfterManualSend(int $invoiceId): void
    {
        Capsule::table('mod_opennfse_notas')
            ->where('invoiceid', $invoiceId)
            ->where('email_automatico_status', 'FALHOU')
            ->whereNotNull('email_automatico_proxima_tentativa_at')
            ->whereColumn('email_automatico_chave', 'chave_acesso')
            ->update([
                'email_automatico_proxima_tentativa_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function upsert(array $data, array $options = []): void
    {
        $invoiceId = (int) $data['invoiceid'];
        $now = date('Y-m-d H:i:s');
        $existing = $this->findByInvoiceId($invoiceId);
        $touchLastStatusCheckedAt = (bool) ($options['touch_last_status_checked_at'] ?? false);

        if ($existing === null) {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            if ($touchLastStatusCheckedAt && !array_key_exists('last_status_checked_at', $data)) {
                $data['last_status_checked_at'] = $now;
            }
            Capsule::table('mod_opennfse_notas')->insert($data);
            return;
        }

        if ($touchLastStatusCheckedAt && !array_key_exists('last_status_checked_at', $data)) {
            $data['last_status_checked_at'] = $now;
        }

        $hasSubstantiveChange = false;
        foreach ($data as $field => $value) {
            if (in_array($field, ['invoiceid', 'created_at', 'updated_at', 'last_status_checked_at'], true)) {
                continue;
            }

            $current = $existing[$field] ?? null;
            if (!$this->valuesAreEquivalent($current, $value)) {
                $hasSubstantiveChange = true;
                break;
            }
        }

        if ($hasSubstantiveChange) {
            $data['updated_at'] = $now;
        } elseif (count($data) === 1 && array_key_exists('invoiceid', $data)) {
            return;
        }

        Capsule::table('mod_opennfse_notas')->where('id', (int) $existing['id'])->update($data);
    }

    private function valuesAreEquivalent($current, $next): bool
    {
        if ($current === null || $next === null) {
            return $current === $next;
        }

        if (is_bool($current) || is_bool($next)) {
            return (bool) $current === (bool) $next;
        }

        if (is_numeric($current) && is_numeric($next)) {
            return (string) (+$current) === (string) (+$next);
        }

        return trim((string) $current) === trim((string) $next);
    }
}
