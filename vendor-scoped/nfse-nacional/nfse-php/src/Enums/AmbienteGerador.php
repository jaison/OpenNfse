<?php

namespace Nfse\Enums;

/**
 * Ambiente Gerador da NFS-e
 *
 * Baseado no schema: TSAmbGer
 */
enum AmbienteGerador : string
{
    /**
     * Sistema Próprio do Município
     */
    case Municipio = '1';
    /**
     * Sefin Nacional
     */
    case SefinNacional = '2';
    /**
     * Get description for the enum case
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Municipio => 'Sistema Próprio do Município',
            self::SefinNacional => 'Sefin Nacional',
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
