<?php

namespace Nfse\Enums;

/**
 * Tipo de dedução/redução
 *
 * Baseado no schema: TSTpDedRed
 */
enum TipoDeducaoReducao : string
{
    /**
     * Materiais
     */
    case Materiais = '1';
    /**
     * Subempreitada
     */
    case Subempreitada = '2';
    /**
     * Reembolso
     */
    case Reembolso = '3';
    /**
     * Outros
     */
    case Outros = '99';
    /**
     * Get description for the enum case
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Materiais => 'Materiais',
            self::Subempreitada => 'Subempreitada',
            self::Reembolso => 'Reembolso',
            self::Outros => 'Outros',
        };
    }
    /**
     * Get label (alias for getDescription)
     */
    public function label(): string
    {
        return $this->getDescription();
    }
}
