<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

/**
 * Conferências de criação de fatura (mod_opennfse_geracao_checks): faturas criadas que ainda
 * precisam passar pela emissão na geração. Uma linha por fatura.
 */
interface GenerationCheckRepositoryInterface
{
    /** Registra a fatura. Registrar de novo não muda nada (mantém a data do primeiro registro). */
    public function register(int $invoiceId, string $at): void;

    /**
     * Conferências pendentes, das verificadas há mais tempo para as mais recentes.
     *
     * @return array<int, array{invoiceid: int|string, registrado_em: string, verificado_em: string}>
     */
    public function listPending(int $limit): array;

    /** Marca a conferência como verificada agora (rascunho que segue pendente vai para o fim da fila). */
    public function touch(int $invoiceId, string $at): void;

    public function delete(int $invoiceId): void;
}
