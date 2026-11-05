<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Helpers;

use OpenNfse\Helpers\EnderecoParser;
use PHPUnit\Framework\TestCase;

final class EnderecoParserTest extends TestCase
{
    public static function enderecos(): array
    {
        return [
            'virgula' => ['RUA ANTÔNIO MONTEIRO DE REZENDE, 541', ['RUA ANTÔNIO MONTEIRO DE REZENDE', '541', '']],
            'numero no nome e depois da virgula' => ['RUA 7 DE SETEMBRO, 100', ['RUA 7 DE SETEMBRO', '100', '']],
            'so numero no nome' => ['RUA 7 DE SETEMBRO', ['RUA 7 DE SETEMBRO', null, '']],
            'numero no nome e no fim' => ['AV. 25 DE MARÇO 300', ['AV. 25 DE MARÇO', '300', '']],
            'prefixo nº' => ['AV. PAULISTA Nº 1000', ['AV. PAULISTA', '1000', '']],
            'prefixo n°' => ['Av. Paulista n° 1000', ['Av. Paulista', '1000', '']],
            'prefixo no.' => ['RUA X, NO. 15', ['RUA X', '15', '']],
            'prefixo número' => ['rua das flores, número 22', ['rua das flores', '22', '']],
            'prefixo NÚMERO' => ['RUA DAS FLORES NÚMERO 22', ['RUA DAS FLORES', '22', '']],
            'fim da linha' => ['AV PAULISTA 1000', ['AV PAULISTA', '1000', '']],
            'hifen' => ['Rua São João - 45', ['Rua São João', '45', '']],
            'sufixo letra' => ['RUA X, 12A', ['RUA X', '12A', '']],
            'sufixo letra minuscula' => ['rua x, 12a', ['rua x', '12A', '']],
            'sem numero' => ['RUA X, S/N', ['RUA X', 'S/N', '']],
            'sem numero sn minusculo' => ['rua x sn', ['rua x', 'S/N', '']],
            'resto depois da virgula' => ['AV. BRASIL, 1500, SALA 3', ['AV. BRASIL', '1500', 'SALA 3']],
            'resto depois do hifen' => ['AV. BRASIL, 1500 - APTO 12', ['AV. BRASIL', '1500', 'APTO 12']],
            'acentos minusculas' => ['travessa joão açaí, 7', ['travessa joão açaí', '7', '']],
            'vazio' => ['', ['', null, '']],
            'espacos' => ['   ', ['', null, '']],
            'so numero' => ['541', ['541', null, '']],
            'complemento no fim' => ['RUA 10, QD 5, LT 3', ['RUA 10, QD 5, LT 3', null, '']],
            'km' => ['RODOVIA BR-101, KM 5', ['RODOVIA BR-101, KM 5', null, '']],
            'numero na frente' => ['500 Market Street', ['500 Market Street', null, '']],
            'espacos extras' => ['  RUA   X ,  541  ', ['RUA X', '541', '']],
        ];
    }

    /**
     * @dataProvider enderecos
     */
    public function testSeparaNumero(string $linha, array $esperado): void
    {
        $this->assertSame($esperado, EnderecoParser::separarNumero($linha));
    }
}
