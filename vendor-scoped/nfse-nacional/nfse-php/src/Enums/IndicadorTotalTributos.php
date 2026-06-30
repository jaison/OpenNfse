<?php

namespace Nfse\Enums;

/**
 * Indicador de informação de valor total de tributos
 */
enum IndicadorTotalTributos : int
{
    /**
     * Nenhum
     */
    case Nenhum = 0;
    /**
     * Valor total aproximado dos tributos federais, estaduais e municipais (Lei 12.741/12)
     */
    case Lei12741 = 1;
    /**
     * Sem informação de tributos totais
     */
    case SemInformacao = 2;
    public function getDescription(): string
    {
        return match ($this) {
            self::Nenhum => 'Nenhum',
            self::Lei12741 => 'Valor total aproximado dos tributos federais, estaduais e municipais (Lei 12.741/12)',
            self::SemInformacao => 'Sem informação de tributos totais',
        };
    }
    public function label(): string
    {
        return $this->getDescription();
    }
}
