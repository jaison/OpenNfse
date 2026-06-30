<?php

namespace Nfse\Enums;

/**
 * Tipo de Retenção do ISSQN
 */
enum TipoRetencaoIssqn : int
{
    /**
     * Não Retido
     */
    case NaoRetido = 1;
    /**
     * Retido pelo Tomador
     */
    case RetidoTomador = 2;
    /**
     * Retido pelo Intermediário
     */
    case RetidoIntermediario = 3;
    public function getDescription(): string
    {
        return match ($this) {
            self::NaoRetido => 'Não Retido',
            self::RetidoTomador => 'Retido pelo Tomador',
            self::RetidoIntermediario => 'Retido pelo Intermediário',
        };
    }
    public function label(): string
    {
        return $this->getDescription();
    }
}
