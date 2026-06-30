<?php

namespace Nfse\Enums;

/**
 * Motivo da emissão da DPS pelo Tomador ou Intermediário
 *
 * Baseado no schema: TSMotivoEmisTI
 */
enum MotivoEmissaoTomadorIntermediario : string
{
    /**
     * Rejeição de NFS-e emitida pelo prestador
     */
    case Rejeicao = '4';
    /**
     * Get description for the enum case
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Rejeicao => 'Rejeição de NFS-e emitida pelo prestador',
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
