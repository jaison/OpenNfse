<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

/**
 * Persistência dos alertas de NFS-e (mod_opennfse_alertas).
 *
 * A coluna chave_aberto guarda a chave de idempotência enquanto o alerta está aberto
 * e volta a NULL quando ele é resolvido; o índice único dela impede dois alertas
 * abertos iguais.
 */
interface AlertRepositoryInterface
{
    public function findOpenByKey(string $openKey): ?array;

    /**
     * Grava o alerta e devolve o id. Lança exceção se já existir um alerta aberto com a mesma chave.
     */
    public function insert(array $row): int;

    public function findById(int $id): ?array;

    /**
     * Marca o alerta como resolvido. Retorna false se ele não existir ou já estiver resolvido.
     */
    public function markResolved(int $id, int $adminId, string $resolvedAt): bool;
}
