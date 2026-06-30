<?php

namespace Nfse\Enums;

/**
 * Modo de Prestação do Serviço (Comércio Exterior)
 *
 * Baseado no schema: TSMdPrestacao
 */
enum ModoPrestacao : string
{
    /**
     * Desconhecido ou Não Aplicável
     */
    case Desconhecido = '0';
    /**
     * Transfronteiriço
     */
    case Transfronteirico = '1';
    /**
     * Consumo no Exterior
     */
    case ConsumoNoExterior = '2';
    /**
     * Presença Comercial no Exterior
     */
    case PresencaComercialNoExterior = '3';
    /**
     * Movimento Temporário de Pessoas Físicas
     */
    case MovimentoTemporarioPessoasFisicas = '4';
    /**
     * Get description for the enum case
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Transfronteirico => 'Transfronteiriço',
            self::ConsumoNoExterior => 'Consumo no Exterior',
            self::PresencaComercialNoExterior => 'Presença Comercial no Exterior',
            self::MovimentoTemporarioPessoasFisicas => 'Movimento Temporário de Pessoas Físicas',
            self::Desconhecido => 'Desconhecido ou Não Aplicável',
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
