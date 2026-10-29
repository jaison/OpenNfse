<?php

declare(strict_types=1);

namespace OpenNfse\Repositories;

/**
 * Pedidos de conferência diferida do total da fatura (mod_opennfse_alerta_checks).
 * Uma linha por fatura: pedir de novo só atualiza solicitado_em.
 */
interface AlertCheckRepositoryInterface
{
    public function request(int $invoiceId, string $requestedAt): void;

    /**
     * Pedidos com solicitado_em até $requestedBefore, dos mais antigos para os mais novos.
     *
     * @return array<int, array{invoiceid: int|string, solicitado_em: string}>
     */
    public function listDue(string $requestedBefore, int $limit): array;

    /**
     * Remove o pedido se ele ainda for o mesmo que foi lido. Devolve false se outro processo
     * já o removeu ou se a fatura foi alterada de novo (solicitado_em mudou).
     */
    public function claim(int $invoiceId, string $requestedAt): bool;
}
