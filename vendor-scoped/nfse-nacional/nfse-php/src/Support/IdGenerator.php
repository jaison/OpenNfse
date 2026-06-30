<?php

namespace Nfse\Support;

class IdGenerator
{
    /**
     * Gera o ID da DPS (Declaração de Prestação de Serviço).
     *
     * Formato: DPS + Cód.Mun.(7) + Tipo Inscr.(1) + Inscr.Fed.(14) + Série(5) + Número(15)
     *
     * @param  string  $cpfCnpj  CPF ou CNPJ do emitente.
     * @param  string  $codIbge  Código IBGE do município de emissão (7 dígitos).
     * @param  string  $serieDps  Série da DPS (até 5 caracteres).
     * @param  string|int  $numDps  Número da DPS (até 15 dígitos).
     * @return string ID gerado (45 caracteres).
     */
    public static function generateDpsId(string $cpfCnpj, string $codIbge, string $serieDps, string|int $numDps): string
    {
        $cpfCnpj = preg_replace('/\D/', '', $cpfCnpj);
        $string = 'DPS';
        $string .= substr($codIbge, 0, 7);
        // Cód.Mun. (7)
        $string .= strlen($cpfCnpj) === 14 ? '2' : '1';
        // Tipo de Inscrição Federal (1) (2=CNPJ, 1=CPF)
        $string .= str_pad($cpfCnpj, 14, '0', \STR_PAD_LEFT);
        // Inscrição Federal (14)
        $string .= str_pad($serieDps, 5, '0', \STR_PAD_LEFT);
        // Série DPS (5)
        $string .= str_pad((string) $numDps, 15, '0', \STR_PAD_LEFT);
        // Número DPS (15)
        return $string;
    }
}
