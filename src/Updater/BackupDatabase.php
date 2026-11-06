<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

/**
 * O que o backup das tabelas precisa do banco. Separado para testar sem MySQL.
 */
interface BackupDatabase
{
    /** @return list<string> tabelas com o prefixo, em ordem alfabética */
    public function tablesWithPrefix(string $prefix): array;

    /** CREATE TABLE completo da tabela. */
    public function createStatement(string $table): string;

    /**
     * Linhas da tabela em lotes, como arrays associativos.
     *
     * @return iterable<list<array<string, mixed>>>
     */
    public function rowBatches(string $table, int $batchSize): iterable;

    /** Valor pronto para SQL (NULL, número ou texto entre aspas). */
    public function quote(mixed $value): string;

    public function exec(string $sql): void;
}
