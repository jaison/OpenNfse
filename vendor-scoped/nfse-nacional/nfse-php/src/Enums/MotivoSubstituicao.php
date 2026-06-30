<?php

namespace Nfse\Enums;

/**
 * Motivo da substituição de NFS-e
 *
 * Baseado no schema: TSMotivoSubst
 */
enum MotivoSubstituicao : string
{
    /**
     * Desenquadramento de NFS-e do Simples Nacional
     */
    case DesenquadramentoSimplesNacional = '01';
    /**
     * Enquadramento de NFS-e no Simples Nacional
     */
    case EnquadramentoSimplesNacional = '02';
    /**
     * Inclusão Retroativa de Imunidade/Isenção para NFS-e
     */
    case InclusaoRetroativaImunidadeIsencao = '03';
    /**
     * Exclusão Retroativa de Imunidade/Isenção para NFS-e
     */
    case ExclusaoRetroativaImunidadeIsencao = '04';
    /**
     * Rejeição de NFS-e pelo tomador ou pelo intermediário se responsável pelo recolhimento do tributo
     */
    case RejeicaoNfs = '05';
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
            self::DesenquadramentoSimplesNacional => 'Desenquadramento de NFS-e do Simples Nacional',
            self::EnquadramentoSimplesNacional => 'Enquadramento de NFS-e no Simples Nacional',
            self::InclusaoRetroativaImunidadeIsencao => 'Inclusão Retroativa de Imunidade/Isenção para NFS-e',
            self::ExclusaoRetroativaImunidadeIsencao => 'Exclusão Retroativa de Imunidade/Isenção para NFS-e',
            self::RejeicaoNfs => 'Rejeição de NFS-e pelo tomador ou pelo intermediário se responsável pelo recolhimento do tributo',
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
