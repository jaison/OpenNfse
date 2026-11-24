<?php

declare(strict_types=1);

/**
 * Gera data/rtc-combinacoes.php a partir do repositório da calculadora RTC (Receita).
 *
 * Uso: php tools/gerar-rtc-combinacoes.php /caminho/calculadora-rtc-regime-regular
 *
 * Aplica B0001 e todas as migrações V* em ordem numérica num SQLite temporário,
 * para incluir as correções posteriores à carga inicial (V0025, V0034 e as de vigência).
 *
 * Casamento do cTribNac com o codigo_servico de 6 dígitos do addon:
 * a query oficial (formatarCTribNac + CNIL_LSLC_CD = valor) formata 6 dígitos como
 * item.subitem.desdobramento (010301 → 1.03.01) e 4 dígitos como item (0103 → 1.03).
 * Nesta base a maior parte das linhas é o item LC 116 (1.03). O subitem pontuado
 * (99.01.01) existe só em parte dos códigos. O addon procura primeiro 1.03.01 para
 * aquela NBS e, se não houver linha vigente, usa o item 1.03. Assim 010301 + 115061000
 * encontra cIndOp 100301 e cClassTrib 000001.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Rode pela linha de comando.\n");
    exit(1);
}

$repo = $argv[1] ?? '';
if ($repo === '' || !is_dir($repo)) {
    fwrite(STDERR, "Informe o caminho do repositório da calculadora RTC.\n");
    exit(1);
}

$repo = rtrim($repo, '/');
$base = $repo . '/flyway/sql/criacao/B0001__sistema_tributario_completo.sql';
$manutencao = $repo . '/flyway/sql/manutencao';
if (!is_file($base) || !is_dir($manutencao)) {
    fwrite(STDERR, "Não encontrei B0001 ou flyway/sql/manutencao nesse repositório.\n");
    exit(1);
}

$versoes = glob($manutencao . '/V*.sql') ?: [];
usort($versoes, static function (string $a, string $b): int {
    $na = preg_match('/V(\d+)/', basename($a), $ma) ? (int) $ma[1] : 0;
    $nb = preg_match('/V(\d+)/', basename($b), $mb) ? (int) $mb[1] : 0;

    return $na <=> $nb;
});

$commit = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse HEAD'));
if ($commit === '') {
    fwrite(STDERR, "Não foi possível ler o commit do repositório da Receita.\n");
    exit(1);
}

$tmp = tempnam(sys_get_temp_dir(), 'rtc-combinacoes-');
if ($tmp === false) {
    fwrite(STDERR, "Não foi possível criar o SQLite temporário.\n");
    exit(1);
}
@unlink($tmp);
$db = $tmp . '.sqlite';

$aplicar = static function (string $db, string $sql) use ($repo): void {
    $script = "PRAGMA foreign_keys = OFF;\n.read " . $sql . "\n";
    $proc = proc_open(
        ['sqlite3', $db],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('sqlite3 não iniciou.');
    }
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        throw new RuntimeException("Falhou ao aplicar " . str_replace($repo . '/', '', $sql) . ":\n" . $err . $out);
    }
};

try {
    $aplicar($db, $base);
    foreach ($versoes as $arquivo) {
        $aplicar($db, $arquivo);
    }
} catch (Throwable $e) {
    @unlink($db);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$consulta = static function (string $db, string $sql): array {
    $proc = proc_open(
        ['sqlite3', '-json', $db],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('sqlite3 não iniciou.');
    }
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        throw new RuntimeException($err !== '' ? $err : 'Consulta falhou.');
    }
    $out = trim((string) $out);
    if ($out === '') {
        return [];
    }
    $rows = json_decode($out, true);
    if (!is_array($rows)) {
        throw new RuntimeException('JSON inválido do sqlite3.');
    }

    return $rows;
};

try {
    $formatos = $consulta($db, "SELECT DISTINCT CNIL_LSLC_CD AS cd FROM CLASSIF_NBS_INDOP_LC WHERE CNIL_LSLC_CD LIKE '1.03%' ORDER BY cd");
    fwrite(STDOUT, "CNIL_LSLC_CD para o item 1.03: " . implode(', ', array_column($formatos, 'cd')) . "\n");

    $versao = $consulta($db, 'SELECT VRBD_VERSAO_BASE_DADO AS versao FROM VERSAO_BASE_DADO ORDER BY VRBD_ID DESC LIMIT 1');
    $versaoBase = (string) ($versao[0]['versao'] ?? '');

    $combinacoes = $consulta($db, <<<'SQL'
SELECT c.CNIL_LSLC_CD AS ls,
       c.CNIL_NBS_CD AS nbs,
       io.IOIC_CD AS indop,
       ct.CLTR_CD AS classtrib,
       c.CNIL_INICIO_VIGENCIA AS inicio,
       c.CNIL_FIM_VIGENCIA AS fim,
       c.CNIL_IN_PS_ONEROSA AS onerosa,
       c.CNIL_IN_ADQ_EXTERIOR AS exterior
FROM CLASSIF_NBS_INDOP_LC c
INNER JOIN CLASSIFICACAO_TRIBUTARIA ct ON ct.CLTR_ID = c.CNIL_CLTR_ID
INNER JOIN INDICADOR_OPERACAO_IBS_CBS io ON io.IOIC_ID = c.CNIL_IOIC_ID
ORDER BY c.CNIL_LSLC_CD, c.CNIL_NBS_CD, io.IOIC_CD, ct.CLTR_CD, c.CNIL_INICIO_VIGENCIA
SQL);

    $indops = $consulta($db, <<<'SQL'
SELECT IOIC_CD AS cd, IOIC_TIPO_OPERACAO AS descricao, IOIC_LOCAL_INCIDENCIA AS local
FROM INDICADOR_OPERACAO_IBS_CBS
ORDER BY IOIC_CD, IOIC_INICIO_VIGENCIA
SQL);

    $classes = $consulta($db, <<<'SQL'
SELECT CLTR_CD AS cd, CLTR_DESCRICAO AS descricao
FROM CLASSIFICACAO_TRIBUTARIA
ORDER BY CLTR_CD
SQL);
} catch (Throwable $e) {
    @unlink($db);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
@unlink($db);

$usadosIndop = [];
$usadosClasse = [];
$linhas = [];
foreach ($combinacoes as $row) {
    $classtrib = preg_replace('/\D/', '', (string) $row['classtrib']) ?? '';
    $classtrib = str_pad($classtrib, 6, '0', STR_PAD_LEFT);
    $indop = (string) $row['indop'];
    $usadosIndop[$indop] = true;
    $usadosClasse[$classtrib] = true;
    $linhas[] = [
        'ls' => (string) $row['ls'],
        'nbs' => (string) $row['nbs'],
        'indop' => $indop,
        'classtrib' => $classtrib,
        'inicio' => substr((string) $row['inicio'], 0, 10),
        'fim' => $row['fim'] === null || $row['fim'] === '' ? null : substr((string) $row['fim'], 0, 10),
        'onerosa' => (string) $row['onerosa'],
        'exterior' => (string) $row['exterior'],
    ];
}

$mapaIndop = [];
foreach ($indops as $row) {
    $cd = (string) $row['cd'];
    if (!isset($usadosIndop[$cd]) || isset($mapaIndop[$cd])) {
        continue;
    }
    $mapaIndop[$cd] = [
        'descricao' => trim((string) ($row['descricao'] ?? '')),
        'local' => trim((string) ($row['local'] ?? '')),
    ];
}
ksort($mapaIndop);

$mapaClasse = [];
foreach ($classes as $row) {
    $cd = str_pad(preg_replace('/\D/', '', (string) $row['cd']) ?? '', 6, '0', STR_PAD_LEFT);
    if (!isset($usadosClasse[$cd]) || isset($mapaClasse[$cd])) {
        continue;
    }
    $mapaClasse[$cd] = trim((string) ($row['descricao'] ?? ''));
}
ksort($mapaClasse);

$dados = [
    'fonte' => 'https://github.com/ReceitaFederal/calculadora-rtc-regime-regular',
    'commit' => $commit,
    'versao_base' => $versaoBase,
    'licenca' => 'MIT',
    'formatos_1_03' => array_column($formatos, 'cd'),
    'combinacoes' => $linhas,
    'indop' => $mapaIndop,
    'classtrib' => $mapaClasse,
];

$destino = dirname(__DIR__) . '/data/rtc-combinacoes.php';
if (!is_dir(dirname($destino))) {
    mkdir(dirname($destino), 0775, true);
}

$cabecalho = <<<TXT
<?php

declare(strict_types=1);

// Combinações oficiais cTribNac (CNIL_LSLC_CD) × NBS × cIndOp × cClassTrib.
// Fonte: calculadora RTC da Receita Federal (MIT), commit {$commit}.
// Versão da base (VERSAO_BASE_DADO): {$versaoBase}.
// Licença dos dados de origem: MIT, Receita Federal do Brasil.
//
// CNIL_LSLC_CD no item 1.03: %s.
// codigo_servico de 6 dígitos: procura o subitem pontuado (010301 → 1.03.01) e,
// se essa NBS não tiver linha vigente nesse formato, usa o item (010301 → 1.03).
// cClassTrib é CLTR_CD (6 dígitos), resolvido a partir de CNIL_CLTR_ID.

return %s;

TXT;

$php = sprintf($cabecalho, implode(', ', $dados['formatos_1_03']) ?: '(nenhum)', var_export($dados, true));
file_put_contents($destino, $php);

fwrite(STDOUT, 'Combinações: ' . count($linhas) . "\n");
fwrite(STDOUT, 'Versão da base: ' . $versaoBase . "\n");
fwrite(STDOUT, 'Arquivo: ' . $destino . "\n");
