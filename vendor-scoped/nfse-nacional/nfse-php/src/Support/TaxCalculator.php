<?php

namespace Nfse\Support;

class TaxCalculator
{
    /**
     * Calcula o valor do imposto baseado na base de cálculo e alíquota.
     *
     * @param  float  $baseCalculation  Base de cálculo.
     * @param  float  $aliquot  Alíquota em porcentagem (ex: 5.0 para 5%).
     * @return float Valor do imposto arredondado para 2 casas decimais.
     */
    public static function calculate(float $baseCalculation, float $aliquot): float
    {
        return round($baseCalculation * ($aliquot / 100), 2);
    }
}
