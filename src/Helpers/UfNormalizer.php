<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

final class UfNormalizer
{
    public static function normalize(string $ufOrStateName): string
    {
        $v = trim($ufOrStateName);
        if ($v === '') {
            return '';
        }

        $vUpper = strtoupper($v);
        if (strlen($vUpper) === 2 && preg_match('/^[A-Z]{2}$/', $vUpper)) {
            return $vUpper;
        }

        $name = NameNormalizer::normalize($v);

        $map = [
            'acre' => 'AC',
            'alagoas' => 'AL',
            'amapa' => 'AP',
            'amazonas' => 'AM',
            'bahia' => 'BA',
            'ceara' => 'CE',
            'distrito federal' => 'DF',
            'espirito santo' => 'ES',
            'goias' => 'GO',
            'maranhao' => 'MA',
            'mato grosso' => 'MT',
            'mato grosso do sul' => 'MS',
            'minas gerais' => 'MG',
            'para' => 'PA',
            'paraiba' => 'PB',
            'parana' => 'PR',
            'pernambuco' => 'PE',
            'piaui' => 'PI',
            'rio de janeiro' => 'RJ',
            'rio grande do norte' => 'RN',
            'rio grande do sul' => 'RS',
            'rondonia' => 'RO',
            'roraima' => 'RR',
            'santa catarina' => 'SC',
            'sao paulo' => 'SP',
            'sergipe' => 'SE',
            'tocantins' => 'TO',
        ];

        return $map[$name] ?? '';
    }
}

