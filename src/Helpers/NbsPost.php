<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

/**
 * NBS vinda do formulário. Select desabilitado não entra no POST: nesse caso
 * o valor já salvo permanece. Vazio explícito continua vazio.
 */
final class NbsPost
{
    public static function padrao(array $post, ?string $atual): ?string
    {
        if (!array_key_exists('nbs_padrao', $post)) {
            $digits = preg_replace('/\D/', '', (string) $atual);
        } else {
            $digits = preg_replace('/\D/', '', (string) $post['nbs_padrao']);
        }

        return $digits !== '' ? $digits : null;
    }

    /**
     * Dígitos da NBS do grupo. Sem a chave no POST, devolve a NBS já salva.
     */
    public static function doGrupo(array $nbsPorGrupo, int $groupId, ?string $atual): string
    {
        if (!array_key_exists($groupId, $nbsPorGrupo) && !array_key_exists((string) $groupId, $nbsPorGrupo)) {
            return preg_replace('/\D/', '', (string) $atual) ?? '';
        }

        $raw = array_key_exists($groupId, $nbsPorGrupo)
            ? $nbsPorGrupo[$groupId]
            : $nbsPorGrupo[(string) $groupId];

        return preg_replace('/\D/', '', (string) $raw) ?? '';
    }
}
