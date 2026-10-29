<?php

declare(strict_types=1);

namespace OpenNfse\Dto;

/**
 * Resultado de EmissionPolicyService::decide().
 *
 * - emit: se a emissão deve ser enfileirada/feita.
 * - code/message: motivo (código estável para log e texto curto).
 * - allowUnpaid: se a emissão pode seguir com a fatura Unpaid.
 * - source: de onde veio a regra (customfield, grupo, global ou manual).
 * - trigger: gatilho efetivo (nunca, paga, gerada) nos eventos automáticos.
 * - silent: motivo esperado que não precisa de log (ex.: automação desligada na regra global).
 * - warnings: avisos para log, como valor desconhecido no custom field.
 */
final class EmissionDecision
{
    /**
     * @param array<int, array{code: string, message: string}> $warnings
     */
    public function __construct(
        public readonly bool $emit,
        public readonly string $code,
        public readonly string $message,
        public readonly bool $allowUnpaid = false,
        public readonly string $source = '',
        public readonly string $trigger = '',
        public readonly bool $silent = false,
        public readonly array $warnings = [],
    ) {
    }

    public function toLogContext(): array
    {
        return [
            'motivo' => $this->code,
            'regra' => $this->source,
            'gatilho' => $this->trigger,
            'allow_unpaid' => $this->allowUnpaid ? 1 : 0,
        ];
    }
}
