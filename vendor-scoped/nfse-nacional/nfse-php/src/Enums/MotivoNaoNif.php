<?php

namespace Nfse\Enums;

/**
 * Motivo da não informação do NIF
 *
 * Baseado no schema: TSCNaoNIF
 */
enum MotivoNaoNif : string
{
    /**
     * Dispensado do NIF
     */
    case Dispensado = '1';
    /**
     * Não exigência do NIF
     */
    case NaoExigencia = '2';
    /**
     * Get description for the enum case
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Dispensado => 'Dispensado do NIF',
            self::NaoExigencia => 'Não exigência do NIF',
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
