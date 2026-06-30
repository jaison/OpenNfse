<?php

namespace Nfse\Enums;

/**
 * Opção pelo Simples Nacional
 *
 * Baseado no schema: TSOpSimpNac
 */
enum OpcaoSimplesNacional : string
{
    /**
     * Não Optante
     */
    case NaoOptante = '1';
    /**
     * Optante - Microempreendedor Individual (MEI)
     */
    case Mei = '2';
    /**
     * Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)
     */
    case MeEpp = '3';
    /**
     * Get description for the enum case
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::NaoOptante => 'Não Optante',
            self::Mei => 'Optante - Microempreendedor Individual (MEI)',
            self::MeEpp => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
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
