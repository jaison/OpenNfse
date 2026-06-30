<?php

namespace Nfse\Enums;

/**
 * Tipo de Suspensão da Exigibilidade do ISSQN
 */
enum TipoSuspensao : int
{
    /**
     * Suspenso por decisão judicial
     */
    case DecisaoJudicial = 1;
    /**
     * Suspenso por decisão administrativa
     */
    case DecisaoAdministrativa = 2;
    public function getDescription(): string
    {
        return match ($this) {
            self::DecisaoJudicial => 'Suspenso por decisão judicial',
            self::DecisaoAdministrativa => 'Suspenso por decisão administrativa',
        };
    }
    public function label(): string
    {
        return $this->getDescription();
    }
}
