<?php

namespace Nfse\Enums;

/**
 * Tipo de NSU para consulta no ADN
 */
enum TipoNsu : string
{
    /**
     * Recepção
     */
    case Recepcao = 'RECEPCAO';
    /**
     * Distribuição
     */
    case Distribuicao = 'DISTRIBUICAO';
    /**
     * Geral
     */
    case Geral = 'GERAL';
    /**
     * MEI
     */
    case Mei = 'MEI';
    /**
     * Get description for the enum case
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Recepcao => 'Recepção',
            self::Distribuicao => 'Distribuição',
            self::Geral => 'Geral',
            self::Mei => 'MEI',
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
