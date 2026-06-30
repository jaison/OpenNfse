<?php

namespace Nfse\Enums;

/**
 * Tributação do ISSQN
 */
enum TributacaoIssqn : int
{
    /**
     * Operação tributável
     */
    case OperacaoTributavel = 1;
    /**
     * Imunidade
     */
    case Imunidade = 2;
    /**
     * Exportação de serviço
     */
    case ExportacaoServico = 3;
    /**
     * Não Incidência
     */
    case NaoIncidencia = 4;
    public function getDescription(): string
    {
        return match ($this) {
            self::OperacaoTributavel => 'Operação tributável',
            self::Imunidade => 'Imunidade',
            self::ExportacaoServico => 'Exportação de serviço',
            self::NaoIncidencia => 'Não Incidência',
        };
    }
    public function label(): string
    {
        return $this->getDescription();
    }
}
