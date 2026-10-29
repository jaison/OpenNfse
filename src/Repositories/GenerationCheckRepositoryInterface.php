<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

/**
 * Conferências de criação de fatura (mod_opennfse_geracao_checks): faturas criadas que ainda
 * precisam passar pela emissão na geração. Uma linha por fatura.
 */
interface GenerationCheckRepositoryInterface
{
    /**
     * Registra a fatura para conferência em $dueAt. Registrar de novo não muda nada (mantém a
     * linha e as datas do primeiro registro).
     */
    public function register(int $invoiceId, string $at, string $dueAt): void;

    public function exists(int $invoiceId): bool;

    /**
     * Conferências vencidas (proxima_em <= $now), das mais antigas para as mais novas.
     *
     * @return array<int, array{invoiceid: int|string, registrado_em: string, proxima_em: string,
     *                          tentativas: int|string, erro_logado_em: ?string}>
     */
    public function listDue(string $now, int $limit): array;

    /**
     * Reagenda a conferência. $fields aceita verificado_em, proxima_em, tentativas e erro_logado_em.
     */
    public function reschedule(int $invoiceId, array $fields): void;

    public function delete(int $invoiceId): void;
}
