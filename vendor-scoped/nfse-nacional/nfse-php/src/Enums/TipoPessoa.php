<?php

namespace Nfse\Enums;

/**
 * Tipo de Pessoa (Física ou Jurídica)
 */
enum TipoPessoa : int
{
    /**
     * Pessoa Jurídica
     */
    case Juridica = 1;
    /**
     * Pessoa Física
     */
    case Fisica = 2;
    /**
     * Get description for the enum case
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Juridica => 'Pessoa Jurídica',
            self::Fisica => 'Pessoa Física',
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
