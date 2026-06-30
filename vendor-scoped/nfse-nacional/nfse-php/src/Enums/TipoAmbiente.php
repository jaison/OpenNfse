<?php

namespace Nfse\Enums;

/**
 * Tipo de Ambiente do Sistema Nacional NFS-e
 *
 * Baseado no schema: TSTipoAmbiente
 */
enum TipoAmbiente : string
{
    /**
     * Produção
     */
    case Producao = '1';
    /**
     * Homologação
     */
    case Homologacao = '2';
    /**
     * Get description for the enum case
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Producao => 'Produção',
            self::Homologacao => 'Homologação',
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
