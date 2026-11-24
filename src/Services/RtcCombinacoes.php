<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Repositories\LogRepository;

/**
 * Combinações oficiais cTribNac × NBS × cIndOp × cClassTrib da calculadora RTC.
 *
 * O codigo_servico do addon tem 6 dígitos. A query oficial formata isso como
 * item.subitem.desdobramento (010301 → 1.03.01). A base publicada guarda, na
 * maior parte, só o item LC 116 (1.03). A busca usa o subitem quando existe
 * linha vigente para a NBS e, caso contrário, o item (quatro primeiros dígitos).
 */
final class RtcCombinacoes
{
    public const VALIDA = 'valida';
    public const INVALIDA = 'invalida';
    public const DESCONHECIDA = 'desconhecida';
    public const LOG_FORA_TABELA = 'IBSCBS_COMBINACAO_FORA_TABELA';

    private const LIMITE_NBS_PAINEL = 20;

    /** @var array<string, mixed>|null */
    private static ?array $arquivo = null;

    /**
     * @param array<string, mixed>|null $dados fixture de teste; null carrega data/rtc-combinacoes.php
     */
    public function __construct(private ?array $dados = null)
    {
    }

    /**
     * @return list<string> CNIL_LSLC_CD do mais específico ao item
     */
    public function codigosListaServico(string $codigoServico): array
    {
        $digitos = preg_replace('/\D/', '', $codigoServico) ?? '';
        if (strlen($digitos) === 6) {
            return array_values(array_unique(array_filter([
                $this->formatarLc($digitos),
                $this->formatarLc(substr($digitos, 0, 4)),
            ])));
        }
        if (strlen($digitos) === 4) {
            $item = $this->formatarLc($digitos);

            return $item === null ? [] : [$item];
        }

        return [];
    }

    /**
     * Pares vigentes: cIndOp, descrição, local de incidência, cClassTrib e descrição.
     *
     * @return list<array<string, string>>
     */
    public function opcoes(string $codigoServico, string $nbs, \DateTimeInterface $data): array
    {
        $pool = $this->pool($codigoServico, $nbs, $data->format('Y-m-d'));
        $indop = $this->mapaIndop();
        $classes = $this->mapaClasse();
        $vistos = [];
        $saida = [];
        foreach ($pool as $row) {
            $chave = $row['indop'] . '|' . $row['classtrib'];
            if (isset($vistos[$chave])) {
                continue;
            }
            $vistos[$chave] = true;
            $saida[] = [
                'cIndOp' => $row['indop'],
                'cIndOpDescricao' => (string) ($indop[$row['indop']]['descricao'] ?? ''),
                'localIncidencia' => (string) ($indop[$row['indop']]['local'] ?? ''),
                'cClassTrib' => $row['classtrib'],
                'cClassTribDescricao' => (string) ($classes[$row['classtrib']] ?? ''),
            ];
        }

        return $saida;
    }

    /**
     * @return list<string>
     */
    public function nbsPara(string $codigoServico, \DateTimeInterface $data): array
    {
        $dia = $data->format('Y-m-d');
        $codigos = $this->codigosListaServico($codigoServico);
        if ($codigos === []) {
            return [];
        }
        $aceitos = array_fill_keys($codigos, true);
        $encontrados = [];
        foreach ($this->combinacoes() as $row) {
            if (!isset($aceitos[$row['ls']]) || !$this->vigente($row, $dia)) {
                continue;
            }
            $encontrados[(string) $row['nbs']] = true;
        }
        $lista = [];
        foreach (array_keys($encontrados) as $nbs) {
            $lista[] = (string) $nbs;
        }
        sort($lista);

        return $lista;
    }

    public function avaliar(
        string $codigoServico,
        string $nbs,
        string $cIndOp,
        string $cClassTrib,
        \DateTimeInterface $data
    ): string {
        $nbs = preg_replace('/\D/', '', $nbs) ?? '';
        $cIndOp = preg_replace('/\D/', '', $cIndOp) ?? '';
        $cClassTrib = preg_replace('/\D/', '', $cClassTrib) ?? '';
        $pool = $this->pool($codigoServico, $nbs, $data->format('Y-m-d'));
        if ($pool === []) {
            return self::DESCONHECIDA;
        }
        foreach ($pool as $row) {
            if ($row['indop'] === $cIndOp && $row['classtrib'] === $cClassTrib) {
                return self::VALIDA;
            }
        }

        return self::INVALIDA;
    }

    /**
     * @param list<array{rotulo: string, codigo_servico: string, nbs: string}> $grupos
     */
    public function htmlPainel(
        string $codigoServico,
        string $nbs,
        string $cIndOp,
        string $cClassTrib,
        array $grupos,
        \DateTimeInterface $data
    ): string {
        $contextos = [[
            'rotulo' => 'serviço padrão',
            'codigo_servico' => $codigoServico,
            'nbs' => $nbs,
        ]];
        foreach ($grupos as $grupo) {
            $codigoGrupo = (string) ($grupo['codigo_servico'] ?? '');
            $nbsGrupo = (string) ($grupo['nbs'] ?? '');
            if ($codigoGrupo === '' && $nbsGrupo === '') {
                continue;
            }
            if ($codigoGrupo === $codigoServico && $nbsGrupo === $nbs) {
                continue;
            }
            $contextos[] = [
                'rotulo' => (string) ($grupo['rotulo'] ?? 'Grupo'),
                'codigo_servico' => $codigoGrupo !== '' ? $codigoGrupo : $codigoServico,
                'nbs' => $nbsGrupo !== '' ? $nbsGrupo : $nbs,
            ];
        }

        $avisos = [];
        $listas = '';
        $cIndOps = [];
        $classes = [];
        foreach ($contextos as $contexto) {
            $nbsDigitos = preg_replace('/\D/', '', (string) $contexto['nbs']) ?? '';
            if ($nbsDigitos === '') {
                $bloco = $this->htmlNbsDoServico($contexto['rotulo'], $contexto['codigo_servico'], $data);
                $listas .= $bloco['html'];
                foreach ($bloco['opcoes'] as $opcao) {
                    $cIndOps[$opcao['cIndOp']] = $opcao['cIndOpDescricao'];
                    $classes[$opcao['cClassTrib']] = $opcao['cClassTribDescricao'];
                }
                continue;
            }
            $status = $this->avaliar(
                $contexto['codigo_servico'],
                $contexto['nbs'],
                $cIndOp,
                $cClassTrib,
                $data
            );
            $opcoes = $this->opcoes($contexto['codigo_servico'], $contexto['nbs'], $data);
            foreach ($opcoes as $opcao) {
                $cIndOps[$opcao['cIndOp']] = $opcao['cIndOpDescricao'];
                $classes[$opcao['cClassTrib']] = $opcao['cClassTribDescricao'];
            }
            if ($status === self::INVALIDA) {
                $avisos[] = $contexto['rotulo']
                    . ' (serviço ' . $contexto['codigo_servico']
                    . ', NBS ' . $contexto['nbs'] . ')';
            }
            $listas .= $this->htmlLista(
                $contexto['rotulo'],
                $contexto['codigo_servico'],
                $contexto['nbs'],
                $opcoes,
                $status
            );
        }

        $html = '';
        if ($avisos !== []) {
            $html .= '<div class="nfse-callout nfse-callout--warn" role="alert"><div class="nfse-callout__body">';
            $html .= '<div class="nfse-callout__title">Combinação fora da tabela oficial</div>';
            $html .= '<div class="nfse-callout__text">' . $this->h(
                'cIndOp ' . $cIndOp . ' com cClassTrib ' . $cClassTrib
                . ' está fora da tabela oficial para: ' . implode('; ', $avisos)
                . '. A configuração é gravada e a DPS segue para emissão.'
            ) . '</div></div></div>';
        }
        $html .= $listas;
        $html .= $this->htmlDatalist('nfse-rtc-cindop', $cIndOps);
        $html .= $this->htmlDatalist('nfse-rtc-cclasstrib', $classes);

        return $html;
    }

    public function registrarSeInvalida(
        LogRepository $logs,
        ?int $notaId,
        ?string $correlationId,
        array $config,
        string $layout,
        string $codigoServico,
        string $nbs,
        \DateTimeInterface $data
    ): void {
        if ($layout !== DpsLayoutService::LAYOUT_NT009 || !IbscbsConfig::enabled($config)) {
            return;
        }
        $codigos = IbscbsConfig::codigos($config);
        $status = $this->avaliar($codigoServico, $nbs, $codigos['cIndOp'], $codigos['cClassTrib'], $data);
        if ($status !== self::INVALIDA) {
            return;
        }
        $logs->insert($notaId, self::LOG_FORA_TABELA, json_encode([
            'cTribNac' => preg_replace('/\D/', '', $codigoServico) ?? '',
            'nbs' => preg_replace('/\D/', '', $nbs) ?? '',
            'cIndOp' => $codigos['cIndOp'],
            'cClassTrib' => $codigos['cClassTrib'],
            'cst' => $codigos['cst'],
            'data' => $data->format('Y-m-d'),
            'resultado' => self::INVALIDA,
        ], JSON_UNESCAPED_UNICODE), null, $correlationId);
    }

    private function formatarLc(string $digitos): ?string
    {
        if (strlen($digitos) === 4) {
            return ((int) substr($digitos, 0, 2)) . '.' . substr($digitos, 2, 2);
        }
        if (strlen($digitos) === 6) {
            return ((int) substr($digitos, 0, 2)) . '.' . substr($digitos, 2, 2) . '.' . substr($digitos, 4, 2);
        }

        return null;
    }

    /**
     * @return list<array{ls: string, nbs: string, indop: string, classtrib: string, inicio: string, fim: ?string}>
     */
    private function pool(string $codigoServico, string $nbs, string $dia): array
    {
        $nbs = preg_replace('/\D/', '', $nbs) ?? '';
        if ($nbs === '') {
            return [];
        }
        foreach ($this->codigosListaServico($codigoServico) as $ls) {
            $linhas = [];
            foreach ($this->combinacoes() as $row) {
                if ($row['ls'] !== $ls || $row['nbs'] !== $nbs || !$this->vigente($row, $dia)) {
                    continue;
                }
                $linhas[] = $row;
            }
            if ($linhas !== []) {
                return $linhas;
            }
        }

        return [];
    }

    /**
     * @param array{inicio: string, fim: ?string} $row
     */
    private function vigente(array $row, string $dia): bool
    {
        $inicio = substr((string) $row['inicio'], 0, 10);
        $fim = $row['fim'];
        $fim = $fim === null || $fim === '' ? null : substr((string) $fim, 0, 10);

        return $inicio <= $dia && ($fim === null || $fim >= $dia);
    }

    /**
     * @return list<array{ls: string, nbs: string, indop: string, classtrib: string, inicio: string, fim: ?string}>
     */
    private function combinacoes(): array
    {
        $dados = $this->carregar();
        $linhas = $dados['combinacoes'] ?? [];

        return is_array($linhas) ? $linhas : [];
    }

    /**
     * @return array<string, array{descricao: string, local: string}>
     */
    private function mapaIndop(): array
    {
        $mapa = $this->carregar()['indop'] ?? [];

        return is_array($mapa) ? $mapa : [];
    }

    /**
     * @return array<string, string>
     */
    private function mapaClasse(): array
    {
        $mapa = $this->carregar()['classtrib'] ?? [];

        return is_array($mapa) ? $mapa : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function carregar(): array
    {
        if ($this->dados !== null) {
            return $this->dados;
        }
        if (self::$arquivo === null) {
            $arquivo = dirname(__DIR__, 2) . '/data/rtc-combinacoes.php';
            $carregado = is_file($arquivo) ? require $arquivo : [];
            self::$arquivo = is_array($carregado) ? $carregado : [];
        }

        return self::$arquivo;
    }

    /**
     * NBS vigentes do serviço quando o contexto ainda não tem NBS.
     *
     * @return array{html: string, opcoes: list<array<string, string>>}
     */
    private function htmlNbsDoServico(string $rotulo, string $codigo, \DateTimeInterface $data): array
    {
        $titulo = $rotulo . ' · serviço ' . $codigo . ' · NBS não informada';
        $nbsLista = $this->nbsPara($codigo, $data);
        if ($nbsLista === []) {
            return [
                'html' => '<p class="nfse-help">' . $this->h(
                    $titulo . '. Nenhuma NBS vigente na tabela oficial para este serviço.'
                ) . '</p>',
                'opcoes' => [],
            ];
        }

        $visiveis = array_slice($nbsLista, 0, self::LIMITE_NBS_PAINEL);
        $resto = count($nbsLista) - count($visiveis);
        $html = '<p class="nfse-help">' . $this->h('NBS vigentes — ' . $titulo) . '</p>';
        $todas = [];
        foreach ($visiveis as $nbs) {
            $descricao = $this->descricaoNbs($nbs);
            $cabeca = 'NBS ' . $nbs . ($descricao !== '' ? ' — ' . $descricao : '');
            $html .= '<p class="nfse-help">' . $this->h($cabeca) . '</p><ul class="nfse-help">';
            $opcoes = $this->opcoes($codigo, $nbs, $data);
            if ($opcoes === []) {
                $html .= '<li>' . $this->h('Sem combinação vigente.') . '</li>';
            }
            foreach ($opcoes as $opcao) {
                $todas[] = $opcao;
                $html .= '<li>' . $this->h($this->textoOpcao($opcao)) . '</li>';
            }
            $html .= '</ul>';
        }
        if ($resto > 0) {
            $html .= '<p class="nfse-help">' . $this->h('e mais ' . $resto) . '</p>';
        }

        return ['html' => $html, 'opcoes' => $todas];
    }

    /**
     * @param array<string, string> $opcao
     */
    private function textoOpcao(array $opcao): string
    {
        return 'cIndOp ' . $opcao['cIndOp']
            . ($opcao['cIndOpDescricao'] !== '' ? ' (' . $opcao['cIndOpDescricao'] . ')' : '')
            . ($opcao['localIncidencia'] !== '' ? ', local ' . $opcao['localIncidencia'] : '')
            . ' · cClassTrib ' . $opcao['cClassTrib']
            . ($opcao['cClassTribDescricao'] !== '' ? ' (' . $opcao['cClassTribDescricao'] . ')' : '');
    }

    private function descricaoNbs(string $nbs): string
    {
        $mapa = $this->carregar()['nbs'] ?? [];
        if (!is_array($mapa) || !isset($mapa[$nbs]) || !is_string($mapa[$nbs])) {
            return '';
        }

        return trim($mapa[$nbs]);
    }

    /**
     * @param list<array<string, string>> $opcoes
     */
    private function htmlLista(string $rotulo, string $codigo, string $nbs, array $opcoes, string $status): string
    {
        $titulo = $rotulo . ' · serviço ' . $codigo . ' · NBS ' . $nbs;
        if ($opcoes === []) {
            $texto = $status === self::DESCONHECIDA
                ? 'Sem combinação oficial para este par na data de hoje.'
                : 'Sem combinação oficial vigente.';

            return '<p class="nfse-help">' . $this->h($titulo . '. ' . $texto) . '</p>';
        }
        $html = '<p class="nfse-help">' . $this->h('Combinações oficiais — ' . $titulo) . '</p><ul class="nfse-help">';
        foreach ($opcoes as $opcao) {
            $html .= '<li>' . $this->h($this->textoOpcao($opcao)) . '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * @param array<string, string> $opcoes
     */
    private function htmlDatalist(string $id, array $opcoes): string
    {
        $html = '<datalist id="' . $this->h($id) . '">';
        foreach ($opcoes as $valor => $descricao) {
            $rotulo = $descricao !== '' ? $descricao : (string) $valor;
            $html .= '<option value="' . $this->h((string) $valor) . '">' . $this->h($rotulo) . '</option>';
        }

        return $html . '</datalist>';
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
